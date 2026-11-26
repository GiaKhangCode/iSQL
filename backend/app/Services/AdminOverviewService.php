<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\ClassModel;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Dữ liệu trang Tổng quan của admin (frontend OverviewPage.tsx): tình trạng dịch vụ, việc cần xử lý về tài khoản
 * và quyền, ca thi sắp tới, nhật ký gần đây. Chỉ đưa ra những thứ admin xử lý được; kết quả chấm bài của sinh viên
 * không thuộc trang này.
 *
 * Khóa JSON là camelCase vì frontend đọc thẳng. `id` chỉ dùng làm khóa danh sách; mọi thứ hiển thị là tên, tiêu đề,
 * thời gian.
 */
class AdminOverviewService
{
    public const ACTIVITY_LIMIT = 10;
    public const NAME_LIMIT = 20;
    public const EXAM_WINDOW_HOURS = 24;
    /** Giây chờ kết nối SQL Server khi kiểm tra tình trạng (mặc định của driver quá lâu cho một trang tổng quan). */
    private const SQL_SERVER_LOGIN_TIMEOUT = 5;

    public function __construct(private readonly AccessControlService $access)
    {
    }

    /** @return array<int, array{key: string, ok: bool, ms: int, detail: ?string}> */
    public function health(): array
    {
        return [
            $this->check('mysql', fn () => DB::connection()->select('select 1')),
            $this->check('sqlserver', function () {
                config(['database.connections.sqlsrv_health' => array_merge(
                    (array) config('database.connections.sqlsrv'),
                    ['login_timeout' => self::SQL_SERVER_LOGIN_TIMEOUT],
                )]);
                try {
                    DB::connection('sqlsrv_health')->select('select 1');
                } finally {
                    DB::purge('sqlsrv_health');
                }
            }),
            $this->check('redis', fn () => Redis::connection()->ping()),
        ];
    }

    /**
     * Tài khoản đang hoạt động không có quyền nào, những người giữ quyền quản lý phân quyền, và (chỉ với người có
     * quyền quản lý lớp) số lớp chưa có giảng viên.
     *
     * @return array{usersWithoutRole: array{count: int, names: string[]}, accessManagers: array{count: int, names: string[]}, unassignedClasses: ?int}
     */
    public function attention(User $viewer): array
    {
        $withoutRole = User::where('status', 'Active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('user_access_roles')->whereColumn('user_access_roles.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('user_permissions')->whereColumn('user_permissions.user_id', 'users.id'));
        $managers = User::where('status', 'Active')->whereIn('id', $this->access->holdersOf('access.manage'));

        return [
            'usersWithoutRole'  => $this->countWithNames($withoutRole),
            'accessManagers'    => $this->countWithNames($managers),
            // Phải khớp danh sách frontend lọc từ /api/admin/classes (lecturer "Unassigned" và không "Archived").
            'unassignedClasses' => $this->access->can($viewer, 'class.manage')
                ? ClassModel::whereNull('instructor_id')->where('status', '!=', 'Archived')->count()
                : null,
        ];
    }

    /**
     * Ca thi đã công bố đang diễn ra hoặc mở trong 24 giờ tới (để tránh bảo trì đúng giờ thi). `students` là số tài
     * khoản thi còn hiệu lực; ca chưa cấp tài khoản thì đếm sinh viên các lớp của ca.
     *
     * @return array<int, array{id: string, title: string, opens: ?string, closes: ?string, status: string, students: int}>
     */
    public function upcomingExams(): array
    {
        $now = now();

        return Assignment::whereNotNull('examination_id')
            ->where('published', true)
            ->where('closes', '>', $now)
            ->where(fn ($q) => $q->whereNull('opens')->orWhere('opens', '<=', $now->copy()->addHours(self::EXAM_WINDOW_HOURS)))
            ->orderBy('opens')
            ->orderBy('id')
            ->get()
            ->map(function (Assignment $session) use ($now): array {
                $students = DB::table('exam_admissions')->where('session_id', $session->id)->where('status', '!=', 'revoked')->count();
                if ($students === 0) {
                    $students = DB::table('assignment_classes')
                        ->join('class_enrollments', 'class_enrollments.class_id', '=', 'assignment_classes.class_id')
                        ->where('assignment_classes.assignment_id', $session->id)
                        ->distinct()
                        ->count('class_enrollments.student_id');
                }

                return [
                    'id'       => (string) $session->id,
                    'title'    => (string) $session->title,
                    'opens'    => $session->opens?->copy()->utc()->toIso8601ZuluString(),
                    'closes'   => $session->closes?->copy()->utc()->toIso8601ZuluString(),
                    'status'   => $session->opens && $session->opens->greaterThan($now) ? 'Upcoming' : 'Live',
                    'students' => $students,
                ];
            })
            ->all();
    }

    /**
     * Các nhật ký mới nhất. `by` là tên người thực hiện ("System" khi không có), `time` là ISO-8601 UTC.
     *
     * @return array<int, array{id: string, action: string, by: string, time: string}>
     */
    public function recentActivities(): array
    {
        return ActivityLog::with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'id'     => $log->id,
                'action' => $log->action,
                'by'     => $log->user?->name ?? 'System',
                'time'   => $log->created_at ? $log->created_at->utc()->toIso8601ZuluString() : '',
            ])
            ->all();
    }

    /** @return array{key: string, ok: bool, ms: int, detail: ?string} */
    private function check(string $key, callable $probe): array
    {
        $start = microtime(true);
        $detail = null;
        try {
            $probe();
        } catch (\Throwable $e) {
            $detail = mb_substr($e->getMessage(), 0, 300);
        }

        return ['key' => $key, 'ok' => $detail === null, 'ms' => (int) round((microtime(true) - $start) * 1000), 'detail' => $detail];
    }

    /** @return array{count: int, names: string[]} */
    private function countWithNames(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'names' => $query->orderBy('name')->orderBy('id')->limit(self::NAME_LIMIT)->pluck('name')->all(),
        ];
    }
}
