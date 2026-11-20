<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ClassModel;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Số liệu cho trang Tổng quan của admin (frontend OverviewPage.tsx).
 *
 * Khóa JSON là camelCase vì frontend đọc thẳng. Không trả UUID nào để hiển thị: `id` chỉ dùng làm khóa
 * danh sách; mọi thứ người dùng thấy là tên, tiêu đề, thời gian.
 */
class AdminOverviewService
{
    public const ACTIVITY_LIMIT = 10;
    public const ERROR_LIMIT = 5;

    public function __construct(private readonly LecturerRequestService $lecturerRequests)
    {
    }

    /** @return array{users: int, classes: int, pendingRequests: int, unassignedClasses: int, submissionsToday: int, gradingErrors: int} */
    public function stats(): array
    {
        $todayStartUtc = now()->utc()->startOfDay();

        return [
            'users'             => User::count(),
            'classes'           => ClassModel::where('status', 'Active')->count(),
            'pendingRequests'   => $this->lecturerRequests->pendingCount(),
            'unassignedClasses' => $this->unassignedClasses()->count(),
            'submissionsToday'  => Submission::where('submitted_at', '>=', $todayStartUtc)->count(),
            'gradingErrors'     => $this->gradingErrorSubmissions()->count(),
        ];
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

    /**
     * Các bài nộp lỗi chấm mới nhất. `errorType` là kết quả gốc (frontend tự dịch sang tiếng Việt).
     *
     * @return array<int, array{id: string, problemTitle: string, errorType: string, studentName: string, submittedAt: string}>
     */
    public function recentGradingErrors(): array
    {
        return $this->gradingErrorSubmissions()
            ->with(['problem:id,title', 'user:id,name'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(self::ERROR_LIMIT)
            ->get()
            ->map(fn (Submission $submission): array => [
                'id'           => $submission->id,
                'problemTitle' => $submission->problem?->title ?? 'Không rõ bài toán',
                'errorType'    => (string) $submission->result,
                'studentName'  => $submission->user?->name ?? 'Không rõ',
                'submittedAt'  => $submission->submitted_at ? $submission->submitted_at->utc()->toIso8601ZuluString() : '',
            ])
            ->all();
    }

    /**
     * Lớp chưa lưu trữ và chưa có giảng viên chính. Phải khớp danh sách mà panel "Classes without a lecturer"
     * lọc từ /api/admin/classes (lecturer === "Unassigned" và status !== "Archived").
     *
     * @return Builder<ClassModel>
     */
    private function unassignedClasses(): Builder
    {
        return ClassModel::whereNull('instructor_id')->where('status', '!=', 'Archived');
    }

    /** @return Builder<Submission> */
    private function gradingErrorSubmissions(): Builder
    {
        return Submission::where(
            fn (Builder $query) => $query->where('result', 'like', '%error%')->orWhere('result', 'like', '%timeout%')
        );
    }
}
