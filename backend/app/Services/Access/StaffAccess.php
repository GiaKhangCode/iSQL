<?php

namespace App\Services\Access;

use App\Models\User;

/**
 * Các vị từ quyền dùng chung cho nhóm chấm bài, kết quả, kỳ thi và coi thi.
 *
 * Mỗi hàm trả lời một câu hỏi "được làm loại việc gì" bằng mã quyền (PermissionCatalog), thay cho các so sánh
 * cũ như isAdmin() / isFacultyOffice() / role === department_head / role === student. Phạm vi theo dữ liệu
 * (lớp nào, bài của ai, ca thi nào) vẫn do từng service quyết định.
 */
class StaffAccess
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    /**
     * Xem mọi bài tập, ca thi, kết quả và thống kê, không bị giới hạn theo lớp mình dạy:
     * người xem kết quả mọi lớp (results.view_all), người lập kỳ thi (examination.manage), người xem kết quả thi.
     */
    public function seesEverything(User $user): bool
    {
        return $this->access->canAny($user, ['results.view_all', 'examination.manage', 'exam.results']);
    }

    /**
     * "Nhân sự": người có ít nhất một quyền làm việc với kết quả, bài tập hoặc kỳ thi. Người còn lại được coi
     * là người học (chỉ thấy bài của chính mình, bị ràng buộc bởi chế độ thi).
     */
    public function isStaff(User $user): bool
    {
        return $this->access->canAny($user, [
            'results.grade', 'results.view_all', 'examination.manage', 'exam.proctor', 'assignment.create',
            'results.regrade', 'exam.results',
        ]);
    }

    /** Tab Thống kê của giảng viên (thống kê các lớp mình phụ trách; ai xem mọi lớp thì thấy mọi lớp). */
    public function canViewStatistics(User $user): bool
    {
        return $this->access->can($user, 'statistics.view');
    }

    /** Tab Thống kê toàn khoa của giáo vụ. */
    public function canViewFacultyStatistics(User $user): bool
    {
        return $this->access->can($user, 'statistics.faculty');
    }

    /** Được phân công và chấm phúc khảo (tab Phúc khảo). */
    public function canRegrade(User $user): bool
    {
        return $this->access->can($user, 'results.regrade');
    }

    /** Chấm bài, công bố điểm, xử lý phúc khảo. */
    public function canGrade(User $user): bool
    {
        return $this->access->can($user, 'results.grade');
    }

    /** Phân công người chấm phúc khảo, lập kỳ thi, chia ca. */
    public function canManageExaminations(User $user): bool
    {
        return $this->access->can($user, 'examination.manage');
    }

    /** Vào phòng thi để coi thi. */
    public function canProctor(User $user): bool
    {
        return $this->access->canAny($user, ['exam.proctor', 'examination.manage']);
    }

    /**
     * Tiền tố đường dẫn giao diện nơi người này xem bài chấm: /teacher (tab Chấm bài) hoặc /office (tab Kết quả thi,
     * khi người này không có quyền chấm).
     */
    public function reviewAreaPrefix(User $user): string
    {
        if (! $this->access->can($user, 'results.grade') && $this->access->can($user, 'exam.results')) {
            return '/office';
        }

        return '/teacher';
    }
}
