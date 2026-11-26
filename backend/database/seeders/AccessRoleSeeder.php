<?php

namespace Database\Seeders;

use App\Models\AccessRole;
use App\Models\User;
use App\Services\Access\AccessRoleAssigner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed 5 vai trò mặc định và gán vai trò "Sinh viên" cho tài khoản sinh viên hiện có chưa có vai trò nào.
 *
 * Idempotent và không ghi đè chỉnh sửa của admin:
 * - vai trò đã có (theo slug) giữ nguyên tên và danh sách quyền;
 * - chỉ người chưa có vai trò nào mới được gán vai trò suy ra từ loại tài khoản (sinh viên nhận "Sinh viên", nhân sự không nhận vai trò nào).
 */
class AccessRoleSeeder extends Seeder
{
    /** slug => [tên, quyền mặc định]. Mỗi vai trò là một khu vực giao diện; không vai trò nào tự có mọi quyền. */
    public const DEFAULT_ROLES = [
        'student' => ['Sinh viên', [
            'practice.solve', 'assignment.do', 'submission.view',
        ]],
        'instructor' => ['Giảng viên', [
            'practice.solve', 'assignment.do', 'submission.view', 'problem.create', 'library.manage',
            'assignment.create', 'exam.proctor', 'results.grade', 'statistics.view', 'results.regrade',
            'student.reset_password', 'class.teach',
        ]],
        'faculty_office' => ['Giáo vụ khoa', [
            'assignment.create', 'examination.manage', 'exam.credentials', 'exam.results', 'statistics.faculty',
        ]],
        'admin' => ['Admin', [
            'system.overview', 'user.manage', 'class.manage', 'access.manage',
        ]],
        'department_head' => ['Trưởng bộ môn', [
            'practice.solve', 'assignment.do', 'submission.view', 'problem.create', 'tag.manage', 'library.manage',
            'examination.manage', 'exam.results', 'statistics.faculty', 'exam.proctor', 'results.view_all',
        ]],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $this->seedRoles();
            $this->assignExistingUsers();
        });
    }

    private function seedRoles(): void
    {
        foreach (self::DEFAULT_ROLES as $slug => [$name, $permissions]) {
            if (AccessRole::where('slug', $slug)->exists()) {
                continue;
            }
            $role = AccessRole::create(['slug' => $slug, 'name' => $name]);
            $role->syncPermissions($permissions);
        }
    }

    private function assignExistingUsers(): void
    {
        $assigner = app(AccessRoleAssigner::class);

        $users = User::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('user_access_roles')->whereColumn('user_access_roles.user_id', 'users.id'))
            ->get(['id', 'account_type']);

        // Không dùng Collection::each: nó dừng ngay khi callback trả false (người không gán được).
        foreach ($users as $user) {
            $assigner->assignInitial($user);
        }
    }
}
