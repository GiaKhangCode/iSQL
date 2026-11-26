<?php

namespace App\Services\SqlServer;

use App\Models\Assignment;
use App\Models\AssignmentProblem;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;

/**
 * Dọn schema bài làm trên SQL Server khi kết thúc học phần (quyền sandbox.cleanup).
 *
 * - Theo lớp: schema của sinh viên trong lớp với các bài giao cho lớp đó. Không gồm bài luyện tập, bài giao riêng.
 * - Mồ côi: schema `sv_*` không còn khớp tài khoản + hoạt động (hoặc luyện tập) + bài toán nào, và các schema nháp
 *   hệ thống tự tạo khi chạy/chấm bài (`sv_*_x`, `exp_*`, `stu_*`).
 * - Còn hoạt động đang mở (đã công bố, đã mở, chưa hết hạn) thì từ chối: theo lớp xét hoạt động của lớp, mồ côi xét
 *   mọi hoạt động (schema nháp đang được dùng để chấm).
 *
 * Chỉ đụng tới schema có tên đúng một trong các mẫu dưới đây; không bao giờ `dbo` hay schema đặt tên khác.
 */
class SandboxCleanupService
{
    private const STUDENT = '/^sv_[0-9a-f]{24}$/';
    private const TEMPORARY = '/^(sv_[0-9a-f]{24}_x|exp_[0-9a-f]{20}|stu_[0-9a-f]{32})$/';
    /** Số schema xóa tối đa mỗi lần gọi (tránh hết thời gian chờ của web server); frontend gọi lặp đến khi hết. */
    public const BATCH = 100;
    public const ORPHAN_CONFIRMATION = 'DON';

    public function __construct(
        private readonly SandboxService $sandbox,
        private readonly ActivityLogService $activityLog,
    ) {
    }

    /**
     * @return array{totals: array{student: int, orphan: int, temporary: int},
     *               classes: array<int, array{id: string, course: string, term: string, schemas: int, openActivities: string[]}>,
     *               openActivities: string[]}
     */
    public function summary(): array
    {
        [$students, $temporary] = $this->schemas();

        $classes = ClassModel::orderBy('term')->orderBy('id')->get()
            ->map(fn (ClassModel $class): array => [
                'id'             => (string) $class->id,
                'course'         => (string) $class->course,
                'term'           => (string) $class->term,
                'schemas'        => count($this->classSchemas((string) $class->id, $students)),
                'openActivities' => $this->openActivities((string) $class->id),
            ])
            ->filter(fn (array $row) => $row['schemas'] > 0)
            ->values()
            ->all();

        return [
            'totals' => [
                'student'   => count($students),
                'orphan'    => count($this->orphans($students)),
                'temporary' => count($temporary),
            ],
            'classes'        => $classes,
            'openActivities' => $this->openActivities(),
        ];
    }

    /** @return array{deleted: int, failed: int, remaining: int} */
    public function cleanClass(User $actor, string $classId, string $confirm): array
    {
        $class = ClassModel::find($classId);
        abort_if(!$class, 404, 'Không tìm thấy lớp học.');
        abort_unless($confirm === (string) $class->id, 422, 'Xác nhận không khớp: hãy gõ đúng mã lớp.');
        $this->assertNothingOpen($this->openActivities((string) $class->id));

        [$students] = $this->schemas();
        $result = $this->drop($this->classSchemas((string) $class->id, $students));
        if ($result['deleted'] > 0) {
            $this->activityLog->record($actor->id, "sandbox:clean_class:{$class->id}:count={$result['deleted']}");
        }

        return $result;
    }

    /** @return array{deleted: int, failed: int, remaining: int} */
    public function cleanOrphans(User $actor, string $confirm): array
    {
        abort_unless($confirm === self::ORPHAN_CONFIRMATION, 422, 'Xác nhận không khớp: hãy gõ ' . self::ORPHAN_CONFIRMATION . '.');
        $this->assertNothingOpen($this->openActivities());

        [$students, $temporary] = $this->schemas();
        $result = $this->drop($this->orphans($students) + $temporary);
        if ($result['deleted'] > 0) {
            $this->activityLog->record($actor->id, "sandbox:clean_orphans:count={$result['deleted']}");
        }

        return $result;
    }

    /**
     * Schema trên SQL Server, tách thành schema bài làm và schema nháp; giá trị = schema còn đối tượng bên trong.
     *
     * @return array{0: array<string, bool>, 1: array<string, bool>}
     */
    private function schemas(): array
    {
        try {
            $all = $this->sandbox->listSandboxSchemas();
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'Không kết nối được SQL Server.');
        }

        $students = [];
        $temporary = [];
        foreach ($all as $name => $objects) {
            if (preg_match(self::STUDENT, $name)) {
                $students[$name] = $objects > 0;
            } elseif (preg_match(self::TEMPORARY, $name)) {
                $temporary[$name] = $objects > 0;
            }
        }

        return [$students, $temporary];
    }

    /**
     * @param  array<string, bool>  $existing
     * @return array<string, bool>
     */
    private function classSchemas(string $classId, array $existing): array
    {
        $studentIds = ClassEnrollment::where('class_id', $classId)->pluck('student_id');
        $pairs = AssignmentProblem::whereIn('assignment_id', DB::table('assignment_classes')->where('class_id', $classId)->select('assignment_id'))
            ->get(['assignment_id', 'problem_id']);

        $found = [];
        foreach ($studentIds as $studentId) {
            foreach ($pairs as $pair) {
                $name = $this->sandbox->studentSchemaName((string) $studentId, (string) $pair->assignment_id, (string) $pair->problem_id);
                if (isset($existing[$name])) {
                    $found[$name] = $existing[$name];
                }
            }
        }

        return $found;
    }

    /**
     * Schema bài làm không còn thuộc về tài khoản nào với bài luyện tập hay bài trong hoạt động đang có.
     *
     * @param  array<string, bool>  $existing
     * @return array<string, bool>
     */
    private function orphans(array $existing): array
    {
        $contexts = AssignmentProblem::get(['assignment_id', 'problem_id'])
            ->map(fn ($pair) => [(string) $pair->assignment_id, (string) $pair->problem_id])
            ->concat(Problem::pluck('id')->map(fn ($id) => ['Practice', (string) $id]));

        foreach (User::pluck('id') as $userId) {
            foreach ($contexts as [$context, $problemId]) {
                if ($existing === []) {
                    return [];
                }
                unset($existing[$this->sandbox->studentSchemaName((string) $userId, $context, $problemId)]);
            }
        }

        return $existing;
    }

    /** @return string[] tên các hoạt động đang mở (của một lớp, hoặc toàn hệ thống) */
    private function openActivities(?string $classId = null): array
    {
        $now = now();

        return Assignment::where('published', true)
            ->where(fn ($q) => $q->whereNull('opens')->orWhere('opens', '<=', $now))
            ->where(fn ($q) => $q->whereNull('closes')->orWhere('closes', '>', $now))
            ->when($classId !== null, fn ($q) => $q->whereIn(
                'id',
                DB::table('assignment_classes')->where('class_id', $classId)->select('assignment_id')
            ))
            ->orderBy('title')
            ->pluck('title')
            ->map(fn ($title) => (string) $title)
            ->all();
    }

    /** @param string[] $open */
    private function assertNothingOpen(array $open): void
    {
        if ($open === []) {
            return;
        }
        $shown = implode('; ', array_slice($open, 0, 5)) . (count($open) > 5 ? '; …' : '');
        abort(409, 'Còn ' . count($open) . " bài tập / ca thi đang mở, chưa thể dọn: {$shown}");
    }

    /**
     * @param  array<string, bool>  $targets
     * @return array{deleted: int, failed: int, remaining: int}
     */
    private function drop(array $targets): array
    {
        $deleted = 0;
        $failed = 0;
        foreach (array_slice($targets, 0, self::BATCH, true) as $name => $hasObjects) {
            $this->sandbox->removeSandboxSchema($name, $hasObjects) ? $deleted++ : $failed++;
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'remaining' => count($targets) - $deleted];
    }
}
