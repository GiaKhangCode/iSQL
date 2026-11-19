<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Services\Access\AccessControlService;

/**
 * Số liệu tổng quan cho trang quản trị.
 */
class AdminOverviewService
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    /**
     * Các bộ đếm toàn hệ thống (không lọc theo trạng thái).
     *
     * total_students đếm tài khoản loại sinh viên; total_instructors đếm người được phân công dạy lớp
     * (giữ quyền class.teach qua vai trò hoặc gán riêng, mọi trạng thái; siêu quản trị không tự tính).
     *
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return [
            'total_users'       => User::count(),
            'total_students'    => User::where('account_type', User::ACCOUNT_STUDENT)->count(),
            'total_instructors' => User::whereIn('id', $this->access->holdersOf('class.teach'))->count(),
            'total_problems'    => Problem::count(),
            'total_submissions' => Submission::count(),
            'total_classes'     => ClassModel::count(),
        ];
    }
}
