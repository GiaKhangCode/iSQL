<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;

/**
 * Số liệu tổng quan cho trang quản trị.
 */
class AdminOverviewService
{
    /**
     * Các bộ đếm toàn hệ thống (không lọc theo trạng thái).
     *
     * Lưu ý: total_instructors hiện chỉ đếm role 'instructor' (role cũ), không gồm
     * instructor_practical / instructor_theory / department_head. Hành vi này được khóa
     * bởi AdminOverviewTest; nếu đổi thì cần cập nhật test có chủ ý.
     *
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return [
            'total_users'       => User::count(),
            'total_students'    => User::where('role', 'student')->count(),
            'total_instructors' => User::where('role', 'instructor')->count(),
            'total_problems'    => Problem::count(),
            'total_submissions' => Submission::count(),
            'total_classes'     => ClassModel::count(),
        ];
    }
}
