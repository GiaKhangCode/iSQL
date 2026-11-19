<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\StudentIdentity;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Nhật ký thao tác của giám thị trong một ca, lưu trong activity_logs với action
 * "proctor:{loại}:{mã ca}:{mã sinh viên}[:{chi tiết}]" (frontend ProctorPage đọc lại qua /notices).
 */
class ProctorNoticeService
{
    public const LIMIT = 40;

    private const LABELS = [
        'unlock'           => 'Mở khóa bài thi',
        'add_student'      => 'Thêm sinh viên vào ca',
        'transfer_student' => 'Chuyển ca thi',
        'approve_browser'  => 'Duyệt trình duyệt mới',
    ];

    public function __construct(protected readonly ExaminationService $examinationService)
    {
    }

    /** Ghi một thao tác. $detail rỗng thì action kết thúc bằng dấu hai chấm (add_student) hoặc không có phần chi tiết. */
    public function record(User $actor, string $kind, string $sessionId, string $studentId, ?string $detail = null): void
    {
        $action = "proctor:{$kind}:{$sessionId}:{$studentId}" . ($detail === null ? '' : ":{$detail}");

        ActivityLog::create([
            'id'         => (string) Str::uuid(),
            'user_id'    => $actor->id,
            'action'     => $action,
            'created_at' => now(),
        ]);
    }

    /**
     * 40 thao tác mới nhất của ca, kèm tên người thao tác, tên và mã sinh viên.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFor(Assignment $session): array
    {
        $logs = ActivityLog::where('action', 'like', "proctor:%:{$session->id}:%")
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();

        $userIds = $logs->pluck('user_id')->filter()->unique()->values()->all();
        $parsedStudentIds = [];
        $parsedItems = [];

        foreach ($logs as $row) {
            $parts = explode(':', (string) $row->action, 5);
            if (count($parts) >= 4) {
                $parsedStudentIds[] = $parts[3];
                $parsedItems[] = [
                    'row'       => $row,
                    'kind'      => $parts[1],
                    'studentId' => $parts[3],
                    'detail'    => $parts[4] ?? '',
                ];
            }
        }

        $allUserIds = array_values(array_unique(array_merge($userIds, $parsedStudentIds)));
        $usersMap = !empty($allUserIds) ? User::whereIn('id', $allUserIds)->get()->keyBy('id') : collect([]);
        $codesMap = !empty($parsedStudentIds)
            ? StudentIdentity::whereIn('user_id', $parsedStudentIds)->pluck('student_code', 'user_id')->all()
            : [];

        $result = [];
        foreach ($parsedItems as $item) {
            $row = $item['row'];
            $kind = $item['kind'];
            $studentId = $item['studentId'];

            $actor = $usersMap->get($row->user_id);
            $student = $usersMap->get($studentId);
            $code = $codesMap[$studentId] ?? ($student ? $this->examinationService->studentCode($student->email, $student->id) : '');

            $actorName = $actor ? $actor->name : 'Giám thị';
            $studentName = $student ? $student->name : $studentId;

            $result[] = [
                'id'          => (string) $row->id,
                'kind'        => $kind,
                'label'       => self::LABELS[$kind] ?? $kind,
                'actor'       => $actorName,
                'actorName'   => $actorName,
                'studentId'   => $studentId,
                'studentCode' => $code,
                'student'     => $studentName,
                'studentName' => $studentName,
                'detail'      => $item['detail'],
                'createdAt'   => $row->created_at ? $row->created_at->toISOString() : null,
            ];
        }

        return $result;
    }
}
