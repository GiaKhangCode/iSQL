<?php

namespace Database\Seeders;

use App\Models\AccessRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed 5 vai trò mặc định và gán cho người dùng hiện có theo cột users.role cũ.
 *
 * Idempotent và không ghi đè chỉnh sửa của admin:
 * - vai trò đã có (theo slug) giữ nguyên tên, khu vực và danh sách quyền;
 * - chỉ người chưa có vai trò nào mới được gán vai trò suy ra từ users.role.
 */
class AccessRoleSeeder extends Seeder
{
    /** slug => [tên, khu vực, siêu quản trị, quyền mặc định] */
    public const DEFAULT_ROLES = [
        'student' => ['Sinh viên', AccessRole::AREA_STUDENT, false, [
            'practice.solve',
        ]],
        'instructor' => ['Giảng viên', AccessRole::AREA_TEACHER, false, [
            'practice.solve', 'problem.create', 'problem.share', 'library.manage', 'library.share',
            'assignment.create', 'results.grade', 'student.reset_password',
        ]],
        'faculty_office' => ['Giáo vụ khoa', AccessRole::AREA_OFFICE, false, [
            'assignment.create', 'examination.manage', 'exam.credentials', 'results.grade',
        ]],
        'admin' => ['Admin', AccessRole::AREA_ADMIN, true, []],
        'department_head' => ['Trưởng bộ môn', AccessRole::AREA_TEACHER, false, [
            'practice.solve', 'problem.create', 'problem.share', 'library.manage', 'library.share',
            'results.view_all',
        ]],
    ];

    /** Giá trị users.role cũ => slug vai trò mới (chỉ dùng để gán ban đầu). */
    public const LEGACY_ROLE_TO_SLUG = [
        User::ROLE_STUDENT              => 'student',
        User::ROLE_INSTRUCTOR_PRACTICAL => 'instructor',
        User::ROLE_INSTRUCTOR_THEORY    => 'instructor',
        User::LEGACY_INSTRUCTOR_ROLE    => 'instructor',
        User::ROLE_DEPARTMENT_HEAD      => 'department_head',
        User::ROLE_FACULTY_OFFICE       => 'faculty_office',
        User::ROLE_ADMIN                => 'admin',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $roleIds = $this->seedRoles();
            $this->assignExistingUsers($roleIds);
        });
    }

    /** @return array<string, string> slug => id */
    private function seedRoles(): array
    {
        $ids = [];
        foreach (self::DEFAULT_ROLES as $slug => [$name, $area, $isSuper, $permissions]) {
            $role = AccessRole::where('slug', $slug)->first();
            if (!$role) {
                $role = AccessRole::create(['slug' => $slug, 'name' => $name, 'area' => $area, 'is_super' => $isSuper]);
                $role->syncPermissions($permissions);
            }
            $ids[$slug] = $role->id;
        }

        return $ids;
    }

    private function assignExistingUsers(array $roleIds): void
    {
        $now = now();
        User::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('user_access_roles')->whereColumn('user_access_roles.user_id', 'users.id'))
            ->get(['id', 'role'])
            ->each(function (User $user) use ($roleIds, $now) {
                $slug = self::LEGACY_ROLE_TO_SLUG[$user->role] ?? null;
                if ($slug === null) {
                    return;
                }
                DB::table('user_access_roles')->insert([
                    'user_id'        => $user->id,
                    'access_role_id' => $roleIds[$slug],
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            });
    }
}
