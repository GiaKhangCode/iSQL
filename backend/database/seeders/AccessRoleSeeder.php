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
    /** slug => [tên, siêu quản trị, quyền mặc định] */
    public const DEFAULT_ROLES = [
        'student' => ['Sinh viên', false, [
            'practice.solve',
        ]],
        'instructor' => ['Giảng viên', false, [
            'practice.solve', 'problem.create', 'problem.share', 'library.manage', 'library.share',
            'assignment.create', 'exam.proctor', 'results.grade', 'student.reset_password', 'class.teach',
        ]],
        'faculty_office' => ['Giáo vụ khoa', false, [
            'assignment.create', 'examination.manage', 'exam.credentials',
        ]],
        'admin' => ['Admin', true, []],
        'department_head' => ['Trưởng bộ môn', false, [
            'practice.solve', 'problem.create', 'problem.share', 'library.manage', 'library.share',
            'examination.manage', 'exam.proctor', 'results.view_all',
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
        foreach (self::DEFAULT_ROLES as $slug => [$name, $isSuper, $permissions]) {
            if (AccessRole::where('slug', $slug)->exists()) {
                continue;
            }
            $role = AccessRole::create(['slug' => $slug, 'name' => $name, 'is_super' => $isSuper]);
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
