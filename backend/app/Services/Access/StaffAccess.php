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
     * siêu quản trị, người xem kết quả mọi lớp (results.view_all), người lập kỳ thi (examination.manage).
     */
    public function seesEverything(User $user): bool
    {
        return $this->access->isSuperAdmin($user)
            || $this->access->canAny($user, ['results.view_all', 'examination.manage']);
    }

    /**
     * "Nhân sự": người có ít nhất một quyền làm việc với kết quả, bài tập hoặc kỳ thi. Người còn lại được coi
     * là người học (chỉ thấy bài của chính mình, bị ràng buộc bởi chế độ thi).
     */
    public function isStaff(User $user): bool
    {
        return $this->access->canAny($user, [
            'results.grade', 'results.view_all', 'examination.manage', 'exam.proctor', 'assignment.create',
        ]);
    }

    /** Chấm bài, công bố điểm, xử lý phúc khảo. Vai trò Admin không tự có quyền này. */
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

    /** Tiền tố đường dẫn giao diện nơi người này xem bài chấm: /admin, /office hoặc /teacher. */
    public function reviewAreaPrefix(User $user): string
    {
        $areas = $this->access->areasOf($user);
        foreach (['admin' => '/admin', 'office' => '/office'] as $area => $prefix) {
            if (in_array($area, $areas, true)) {
                return $prefix;
            }
        }

        return '/teacher';
    }
}
