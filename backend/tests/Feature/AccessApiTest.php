<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\ActivityLog;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\PermissionCatalog;
use Database\Seeders\AccessRoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesDefaultRolePermissions;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Đặc tả API Giai đoạn 2: /api/admin/access* (trang Vai trò) và /api/auth/permissions.
 * DB dev có dữ liệu sẵn nên test dùng tên duy nhất và so sánh độ chênh.
 */
class AccessApiTest extends TestCase
{
    use DatabaseTransactions;
    use UsesDefaultRolePermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessRoleSeeder::class);
        $this->useDefaultRolePermissions();
        $this->admin = $this->makeUser('admin');
        Sanctum::actingAs($this->admin);
    }

    private function makeUser(string $legacyRole = 'visitor', string $status = 'Active'): User
    {
        return User::create([
            'name'            => 'Access API ' . $legacyRole,
            'email'           => 'acc_api_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($legacyRole),
            'status'          => $status,
            'initials'        => 'AA',
        ]);
    }

    private function roleId(string $slug): string
    {
        return AccessRole::where('slug', $slug)->firstOrFail()->id;
    }

    private function newRole(array $permissions = ['practice.solve'], ?string $name = null): string
    {
        return $this->postJson('/api/admin/access/roles', [
            'name' => $name ?? 'Vai trò ' . uniqid(), 'permissions' => $permissions,
        ])->assertCreated()->json('id');
    }

    private function actAs(User $user): void
    {
        app(AccessControlService::class)->forget();
        Sanctum::actingAs($user);
    }

    // ───────────────────────── quyền truy cập ─────────────────────────

    public function test_access_endpoints_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders([])->getJson('/api/admin/access')->assertStatus(401);
    }

    public function test_access_endpoints_are_forbidden_without_access_manage(): void
    {
        foreach (['student', 'instructor', 'faculty_office', 'department_head'] as $legacy) {
            $this->actAs($this->makeUser($legacy));
            $this->getJson('/api/admin/access')->assertStatus(403);
            $this->postJson('/api/admin/access/roles', ['name' => 'X', 'permissions' => []])->assertStatus(403);
            $this->getJson('/api/admin/access/users/' . $this->admin->id)->assertStatus(403);
            $this->putJson('/api/admin/access/users/' . $this->admin->id, ['role_ids' => []])->assertStatus(403);
        }
    }

    public function test_any_role_holding_access_manage_can_use_the_page_regardless_of_its_name(): void
    {
        $roleId = $this->newRole(['access.manage'], 'Quản lý quyền ' . uniqid());
        $user = $this->makeUser('visitor');
        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => [$roleId]])->assertOk();

        $this->actAs($user);
        $this->getJson('/api/admin/access')->assertOk();
    }

    // ───────────────────────── danh mục ─────────────────────────

    public function test_catalog_lists_permissions_and_the_seeded_roles(): void
    {
        $res = $this->getJson('/api/admin/access')->assertOk();

        $this->assertSame(PermissionCatalog::PERMISSIONS, $res->json('permissions'));
        $this->assertArrayNotHasKey('contest.create', $res->json('permissions'));

        $roles = collect($res->json('roles'))->keyBy('name');
        foreach (['Sinh viên', 'Giảng viên', 'Giáo vụ khoa', 'Admin', 'Trưởng bộ môn'] as $name) {
            $this->assertTrue($roles->has($name), $name);
        }
        foreach ($roles as $role) {
            $this->assertArrayNotHasKey('area', $role);
            $this->assertArrayNotHasKey('areas', $role, 'khu vực không do server tính: mỗi vai trò là một khu vực');
            $this->assertArrayNotHasKey('isSuper', $role, 'không còn vai trò nào tự có mọi quyền');
        }
        $this->assertSame(['access.manage', 'class.manage', 'system.overview', 'user.manage'], $roles['Admin']['permissions']);
        $this->assertContains('results.grade', $roles['Giảng viên']['permissions']);
        $this->assertNotContains('results.grade', $roles['Trưởng bộ môn']['permissions']);
    }

    public function test_catalog_reports_user_counts(): void
    {
        $before = collect($this->getJson('/api/admin/access')->json('roles'))->firstWhere('name', 'Sinh viên')['userCount'];
        $this->makeUser('student');
        $after = collect($this->getJson('/api/admin/access')->json('roles'))->firstWhere('name', 'Sinh viên')['userCount'];

        $this->assertSame($before + 1, $after);
    }

    // ───────────────────────── tạo vai trò ─────────────────────────

    public function test_create_role(): void
    {
        $name = 'Trợ giảng ' . uniqid();
        $res = $this->postJson('/api/admin/access/roles', [
            'name' => "  {$name}  ", 'permissions' => ['practice.solve', 'results.grade', 'practice.solve'],
        ])->assertCreated();

        $res->assertJsonPath('name', $name)->assertJsonPath('userCount', 0);
        $this->assertSame(['practice.solve', 'results.grade'], $res->json('permissions'));
        $this->assertSame(['practice.solve', 'results.grade'], AccessRole::findOrFail($res->json('id'))->permissionCodes());
        $this->assertTrue(ActivityLog::where('user_id', $this->admin->id)->where('action', 'access:save_role:' . $name)->exists());
    }

    public function test_create_role_validation(): void
    {
        $name = 'Hợp lệ ' . uniqid();
        $base = ['name' => $name, 'permissions' => []];

        $this->postJson('/api/admin/access/roles', array_merge($base, ['name' => '']))->assertStatus(422);
        $this->postJson('/api/admin/access/roles', array_merge($base, ['name' => str_repeat('a', 101)]))->assertStatus(422);
        $this->postJson('/api/admin/access/roles', array_diff_key($base, ['permissions' => 1]))->assertStatus(422);
        $this->postJson('/api/admin/access/roles', array_merge($base, ['permissions' => ['contest.create']]))->assertStatus(422)
            ->assertJsonPath('detail', 'Có quyền không hợp lệ.');
        $this->postJson('/api/admin/access/roles', array_merge($base, ['permissions' => 'practice.solve']))->assertStatus(422);

        $this->assertSame(0, AccessRole::where('name', $name)->count());
    }

    public function test_create_role_rejects_a_duplicate_name(): void
    {
        $this->postJson('/api/admin/access/roles', ['name' => 'Giảng viên', 'permissions' => []])
            ->assertStatus(422)->assertJsonPath('detail', 'Tên vai trò đã tồn tại.');
    }

    // ───────────────────────── sửa vai trò ─────────────────────────

    public function test_update_role_changes_name_and_permissions(): void
    {
        $id = $this->newRole(['practice.solve']);
        $name = 'Đã đổi ' . uniqid();

        $this->putJson("/api/admin/access/roles/{$id}", [
            'name' => $name, 'permissions' => ['examination.manage'],
        ])->assertOk()->assertJsonPath('name', $name)
            ->assertJsonPath('permissions', ['examination.manage']);

        $role = AccessRole::findOrFail($id);
        $this->assertSame(['examination.manage'], $role->permissionCodes());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('access_roles', 'area'));
    }

    public function test_a_new_role_shows_up_as_an_area_of_the_people_holding_it(): void
    {
        $res = $this->postJson('/api/admin/access/roles', [
            'name' => 'Khu vực mới ' . uniqid(), 'area' => 'admin', 'permissions' => ['results.grade', 'exam.proctor'],
        ])->assertCreated();
        $this->assertArrayNotHasKey('area', AccessRole::findOrFail($res->json('id'))->getAttributes(), 'khu vực do client gửi bị bỏ qua');

        $user = $this->makeUser('visitor');
        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => [$res->json('id')]])->assertOk();
        $this->actAs($user);
        $this->getJson('/api/auth/permissions')->assertOk()
            ->assertJsonPath('roles', [['id' => $res->json('id'), 'name' => $res->json('name'), 'permissions' => ['exam.proctor', 'results.grade']]]);

        // Gán thêm quyền cho vai trò thì khu vực của vai trò có thêm quyền đó.
        $this->actAs($this->admin);
        $this->putJson('/api/admin/access/roles/' . $res->json('id'), [
            'name' => $res->json('name'), 'permissions' => ['results.grade', 'exam.proctor', 'statistics.view'],
        ])->assertOk();
        $this->actAs($user);
        $this->getJson('/api/auth/permissions')->assertOk()
            ->assertJsonPath('roles.0.permissions', ['exam.proctor', 'results.grade', 'statistics.view']);
    }

    public function test_seeded_roles_are_editable(): void
    {
        $id = $this->roleId('faculty_office');
        $this->putJson("/api/admin/access/roles/{$id}", [
            'name' => 'Giáo vụ khoa', 'permissions' => ['examination.manage'],
        ])->assertOk();

        $this->assertSame(['examination.manage'], AccessRole::findOrFail($id)->permissionCodes());
    }

    /** Chỉ $this->admin còn là tài khoản Active giữ access.manage (thay đổi nằm trong giao dịch của test, được rollback). */
    private function makeAdminTheOnlyAccessManager(): void
    {
        DB::table('user_permissions')->where('permission', 'access.manage')->where('user_id', '!=', $this->admin->id)->delete();
        $others = DB::table('user_access_roles as uar')
            ->join('access_role_permissions as arp', 'arp.access_role_id', '=', 'uar.access_role_id')
            ->where('arp.permission', 'access.manage')->where('uar.user_id', '!=', $this->admin->id)
            ->pluck('uar.user_id');
        DB::table('users')->whereIn('id', $others)->update(['status' => 'Inactive']);
        app(AccessControlService::class)->forget();
    }

    public function test_the_admin_role_is_edited_like_any_other_role(): void
    {
        $id = $this->roleId('admin');
        $this->putJson("/api/admin/access/roles/{$id}", [
            'name' => 'Admin', 'permissions' => ['access.manage', 'user.manage'],
        ])->assertOk();

        $this->assertSame(['access.manage', 'user.manage'], AccessRole::findOrFail($id)->permissionCodes());
    }

    public function test_access_manage_cannot_be_removed_from_the_last_role_holding_it(): void
    {
        $this->makeAdminTheOnlyAccessManager();
        $id = $this->roleId('admin');

        $this->putJson("/api/admin/access/roles/{$id}", ['name' => 'Admin', 'permissions' => ['user.manage']])->assertStatus(422);
        $this->assertContains('access.manage', AccessRole::findOrFail($id)->permissionCodes(), 'bị từ chối thì không đổi gì');

        // Có người khác giữ quyền (qua vai trò khác) thì được bỏ.
        $other = $this->newRole(['access.manage']);
        $helper = $this->makeUser('visitor');
        $this->putJson('/api/admin/access/users/' . $helper->id, ['role_ids' => [$other]])->assertOk();
        $this->putJson("/api/admin/access/roles/{$id}", ['name' => 'Admin', 'permissions' => ['user.manage']])->assertOk();
    }

    public function test_the_last_access_manager_cannot_lose_the_role_or_be_downgraded(): void
    {
        $this->makeAdminTheOnlyAccessManager();

        $this->putJson('/api/admin/access/users/' . $this->admin->id, ['role_ids' => []])->assertStatus(422);
        $this->assertSame(1, DB::table('user_access_roles')->where('user_id', $this->admin->id)->count());

        // Đổi sang vai trò khác vẫn giữ access.manage thì được.
        $other = $this->newRole(['access.manage', 'user.manage']);
        $this->putJson('/api/admin/access/users/' . $this->admin->id, ['role_ids' => [$other]])->assertOk();
    }

    public function test_update_role_validation_and_not_found(): void
    {
        $id = $this->newRole();
        $this->putJson("/api/admin/access/roles/{$id}", ['name' => 'Sinh viên', 'permissions' => []])->assertStatus(422);
        $this->putJson("/api/admin/access/roles/{$id}", ['name' => 'Ok', 'permissions' => ['nope']])->assertStatus(422);
        $this->putJson('/api/admin/access/roles/does-not-exist', ['name' => 'Ok ' . uniqid(), 'permissions' => []])
            ->assertStatus(404);
    }

    public function test_update_role_may_keep_its_own_name(): void
    {
        $name = 'Giữ tên ' . uniqid();
        $id = $this->newRole(['practice.solve'], $name);

        $this->putJson("/api/admin/access/roles/{$id}", ['name' => $name, 'permissions' => []])->assertOk();
        $this->patchJson("/api/admin/access/roles/{$id}", ['name' => $name, 'permissions' => ['practice.solve']])->assertOk();
    }

    public function test_editing_a_role_changes_what_its_members_can_do_immediately(): void
    {
        $id = $this->newRole(['practice.solve']);
        $user = $this->makeUser('visitor');
        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => [$id]])->assertOk();

        $this->actAs($user);
        $this->getJson('/api/auth/permissions')->assertOk()->assertJsonPath('permissions', ['practice.solve']);

        $this->actAs($this->admin);
        $this->putJson("/api/admin/access/roles/{$id}", ['name' => 'Mới ' . uniqid(), 'permissions' => ['problem.create']])->assertOk();

        $this->actAs($user);
        $this->getJson('/api/auth/permissions')->assertOk()->assertJsonPath('permissions', ['problem.create']);
    }

    // ───────────────────────── gán cho người dùng ─────────────────────────

    public function test_show_user_access(): void
    {
        $user = $this->makeUser('student');
        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => 'problem.create']);

        $this->getJson('/api/admin/access/users/' . $user->id)->assertOk()
            ->assertJsonPath('actor', 'student')
            ->assertJsonPath('studentCode', null)
            ->assertJsonPath('role_ids', [$this->roleId('student')])
            ->assertJsonPath('direct_permissions', ['problem.create'])
            ->assertJsonPath('permissions', ['assignment.do', 'practice.solve', 'problem.create', 'submission.view']);

        $this->getJson('/api/admin/access/users/nobody')->assertStatus(404);
    }

    public function test_assign_roles_replaces_the_previous_set_and_reports_effective_access(): void
    {
        $user = $this->makeUser('student');

        $this->putJson('/api/admin/access/users/' . $user->id, [
            'role_ids' => [$this->roleId('instructor'), $this->roleId('faculty_office')],
        ])->assertOk();

        $res = $this->getJson('/api/admin/access/users/' . $user->id)->assertOk();
        $this->assertEqualsCanonicalizing([$this->roleId('instructor'), $this->roleId('faculty_office')], $res->json('role_ids'));
        $this->assertContains('examination.manage', $res->json('permissions'));
        $this->assertContains('problem.create', $res->json('permissions'));
        $this->assertTrue(ActivityLog::where('user_id', $this->admin->id)->where('action', 'access:assign_roles:' . $user->id)->exists());

        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => []])->assertOk()
            ->assertJsonPath('role_ids', [])->assertJsonPath('permissions', []);
    }

    public function test_direct_permissions_null_keeps_and_empty_array_clears(): void
    {
        $user = $this->makeUser('student');
        $roles = [$this->roleId('student')];

        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => $roles, 'direct_permissions' => ['problem.create', 'problem.create']])
            ->assertOk()->assertJsonPath('direct_permissions', ['problem.create']);

        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => $roles])
            ->assertOk()->assertJsonPath('direct_permissions', ['problem.create']);
        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => $roles, 'direct_permissions' => null])
            ->assertOk()->assertJsonPath('direct_permissions', ['problem.create']);

        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => $roles, 'direct_permissions' => []])
            ->assertOk()->assertJsonPath('direct_permissions', []);
    }

    public function test_assign_validation_and_not_found(): void
    {
        $user = $this->makeUser('student');
        $before = DB::table('user_access_roles')->where('user_id', $user->id)->count();

        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => ['no-such-role']])->assertStatus(422)
            ->assertJsonPath('detail', 'Có vai trò không tồn tại.');
        $this->putJson('/api/admin/access/users/' . $user->id, [])->assertStatus(422);
        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => [], 'direct_permissions' => ['contest.create']])->assertStatus(422)
            ->assertJsonPath('detail', 'Có quyền không hợp lệ.');
        $this->putJson('/api/admin/access/users/nobody', ['role_ids' => []])->assertStatus(404);

        $this->assertSame($before, DB::table('user_access_roles')->where('user_id', $user->id)->count(), 'lỗi validate không được đổi dữ liệu');
    }

    public function test_the_last_active_access_manager_keeps_the_role(): void
    {
        $adminRoleId = $this->roleId('admin');
        // Chỉ còn đúng một quản trị viên Active: người đang đăng nhập.
        DB::table('user_access_roles')->where('access_role_id', $adminRoleId)->where('user_id', '!=', $this->admin->id)->delete();

        $this->putJson('/api/admin/access/users/' . $this->admin->id, ['role_ids' => [$this->roleId('student')]])
            ->assertStatus(422)->assertJsonPath('detail', 'Không thể bỏ quyền quản lý vai trò của quản trị viên đang hoạt động cuối cùng.');
        $this->putJson('/api/admin/access/users/' . $this->admin->id, ['role_ids' => []])->assertStatus(422);
        $this->assertTrue(DB::table('user_access_roles')->where('user_id', $this->admin->id)->where('access_role_id', $adminRoleId)->exists());

        // Giữ vai trò Admin thì được phép (ví dụ thêm vai trò khác).
        $this->putJson('/api/admin/access/users/' . $this->admin->id, ['role_ids' => [$adminRoleId, $this->roleId('student')]])->assertOk();
    }

    public function test_an_inactive_second_admin_does_not_count_but_an_active_one_does(): void
    {
        $adminRoleId = $this->roleId('admin');
        DB::table('user_access_roles')->where('access_role_id', $adminRoleId)->where('user_id', '!=', $this->admin->id)->delete();

        $inactive = $this->makeUser('admin', 'Inactive');
        $this->putJson('/api/admin/access/users/' . $this->admin->id, ['role_ids' => []])->assertStatus(422);

        $this->makeUser('admin');
        $this->putJson('/api/admin/access/users/' . $this->admin->id, ['role_ids' => []])->assertOk();
        $this->assertNotNull($inactive->id);
    }

    // ───────────────────────── mã sinh viên ─────────────────────────

    public function test_set_student_code(): void
    {
        $student = $this->makeUser('student');
        $code = (string) random_int(10000000, 99999999);

        $this->putJson("/api/admin/access/users/{$student->id}/student-code", ['student_code' => $code])
            ->assertOk()->assertJsonPath('studentCode', $code);
        $this->assertSame($code, StudentIdentity::find($student->id)->student_code);
        $this->getJson('/api/admin/access/users/' . $student->id)->assertJsonPath('studentCode', $code);

        $new = (string) random_int(10000000, 99999999);
        $this->putJson("/api/admin/access/users/{$student->id}/student-code", ['student_code' => $new])->assertOk();
        $this->assertSame($new, StudentIdentity::find($student->id)->student_code);
    }

    public function test_student_code_validation(): void
    {
        $student = $this->makeUser('student');
        foreach (['12345', 'abc12345', str_repeat('1', 21), ''] as $bad) {
            $this->putJson("/api/admin/access/users/{$student->id}/student-code", ['student_code' => $bad])->assertStatus(422);
        }
        $this->putJson("/api/admin/access/users/{$student->id}/student-code", [])->assertStatus(422);
        $this->putJson('/api/admin/access/users/nobody/student-code', ['student_code' => '12345678'])->assertStatus(404);
    }

    public function test_student_code_only_for_student_accounts_and_must_be_unique(): void
    {
        $teacher = $this->makeUser('instructor');
        $this->putJson("/api/admin/access/users/{$teacher->id}/student-code", ['student_code' => '12345678'])
            ->assertStatus(422)->assertJsonPath('detail', 'Hãy chọn tài khoản sinh viên.');

        $a = $this->makeUser('student');
        $b = $this->makeUser('student');
        $code = (string) random_int(10000000, 99999999);
        $this->putJson("/api/admin/access/users/{$a->id}/student-code", ['student_code' => $code])->assertOk();
        $this->putJson("/api/admin/access/users/{$b->id}/student-code", ['student_code' => $code])->assertStatus(409);
        $this->putJson("/api/admin/access/users/{$a->id}/student-code", ['student_code' => $code])->assertOk();
    }

    // ───────────────────────── /api/auth/permissions ─────────────────────────

    public function test_my_permissions_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders([])->getJson('/api/auth/permissions')->assertStatus(401);
    }

    public function test_my_permissions_for_each_seeded_role(): void
    {
        $student = ['assignment.do', 'practice.solve', 'submission.view'];
        $faculty = ['assignment.create', 'exam.credentials', 'exam.results', 'examination.manage', 'statistics.faculty'];

        $this->actAs($this->makeUser('student'));
        $this->getJson('/api/auth/permissions')->assertOk()->assertExactJson([
            'permissions' => $student,
            'roles' => [['id' => $this->roleId('student'), 'name' => 'Sinh viên', 'permissions' => $student]],
            'directPermissions' => [],
        ]);

        $this->actAs($this->makeUser('faculty_office'));
        $this->getJson('/api/auth/permissions')->assertOk()->assertExactJson([
            'permissions' => $faculty,
            'roles' => [['id' => $this->roleId('faculty_office'), 'name' => 'Giáo vụ khoa', 'permissions' => $faculty]],
            'directPermissions' => [],
        ]);

        $this->actAs($this->makeUser('department_head'));
        $res = $this->getJson('/api/auth/permissions')->assertOk();
        $this->assertContains('results.view_all', $res->json('permissions'));
        $this->assertContains('examination.manage', $res->json('permissions'));
        $this->assertNotContains('results.grade', $res->json('permissions'));
        $this->assertCount(1, $res->json('roles'));
    }

    public function test_my_permissions_for_the_admin_role_has_only_its_four_permissions(): void
    {
        $four = ['access.manage', 'class.manage', 'system.overview', 'user.manage'];

        $this->getJson('/api/auth/permissions')->assertOk()->assertExactJson([
            'permissions' => $four,
            'roles' => [['id' => $this->roleId('admin'), 'name' => 'Admin', 'permissions' => $four]],
            'directPermissions' => [],
        ]);
        $this->getJson('/api/examinations')->assertStatus(403);
    }

    public function test_my_permissions_ignore_the_legacy_role_column_and_old_matrix(): void
    {
        // Cột loại tài khoản cũ không còn quyết định quyền: người "instructor" bị gỡ hết vai trò thì không có quyền gì.
        $user = $this->makeUser('instructor');
        DB::table('user_access_roles')->where('user_id', $user->id)->delete();
        $this->actAs($user);

        $this->getJson('/api/auth/permissions')->assertOk()
            ->assertExactJson(['permissions' => [], 'roles' => [], 'directPermissions' => []]);
    }

    public function test_my_permissions_include_direct_grants_and_multiple_roles(): void
    {
        $user = $this->makeUser('student');
        $this->putJson('/api/admin/access/users/' . $user->id, [
            'role_ids' => [$this->roleId('student'), $this->roleId('faculty_office')], 'direct_permissions' => ['library.manage'],
        ])->assertOk();

        $this->actAs($user);
        $res = $this->getJson('/api/auth/permissions')->assertOk();
        $this->assertContains('library.manage', $res->json('permissions'));
        $this->assertContains('examination.manage', $res->json('permissions'));
        $this->assertContains('practice.solve', $res->json('permissions'));
        // library.manage gán riêng không thuộc vai trò nào: client gom vào khu vực Quyền bổ sung.
        $this->assertCount(2, $res->json('roles'));
        foreach ($res->json('roles') as $role) {
            $this->assertNotContains('library.manage', $role['permissions']);
        }
        $this->assertSame(['library.manage'], $res->json('directPermissions'));
    }

    public function test_a_direct_grant_is_not_part_of_the_role_area(): void
    {
        $user = $this->makeUser('instructor');
        $this->putJson('/api/admin/access/users/' . $user->id, [
            'role_ids' => [$this->roleId('instructor')], 'direct_permissions' => ['examination.manage', 'tag.manage'],
        ])->assertOk();

        $this->actAs($user);
        $res = $this->getJson('/api/auth/permissions')->assertOk();
        $this->assertContains('examination.manage', $res->json('permissions'), 'quyền gán riêng vẫn có hiệu lực ở API');
        $this->assertNotContains('examination.manage', $res->json('roles.0.permissions'), 'không lẫn vào khu vực của vai trò');
        $this->assertSame(['examination.manage', 'tag.manage'], $res->json('directPermissions'));

        // API của trang vẫn kiểm theo quyền hiệu lực.
        $this->getJson('/api/examinations')->assertOk();
    }

    public function test_an_account_with_only_direct_grants_has_no_role_area(): void
    {
        $user = $this->makeUser('instructor');
        $this->putJson('/api/admin/access/users/' . $user->id, ['role_ids' => [], 'direct_permissions' => ['user.manage']])
            ->assertOk();

        $this->actAs($user);
        $this->getJson('/api/auth/permissions')->assertOk()
            ->assertExactJson(['permissions' => ['user.manage'], 'roles' => [], 'directPermissions' => ['user.manage']]);
    }

    // ───────────────────────── tài khoản mới ─────────────────────────

    public function test_new_accounts_get_the_default_role_for_their_legacy_role(): void
    {
        $map = [
            'student' => 'student', 'instructor_practical' => 'instructor', 'instructor_theory' => 'instructor',
            'department_head' => 'department_head', 'faculty_office' => 'faculty_office', 'admin' => 'admin',
        ];
        foreach ($map as $legacy => $slug) {
            $user = $this->makeUser($legacy);
            $this->assertSame([$this->roleId($slug)], DB::table('user_access_roles')->where('user_id', $user->id)->pluck('access_role_id')->all(), $legacy);
        }

        $this->assertSame(0, DB::table('user_access_roles')->where('user_id', $this->makeUser('visitor')->id)->count());
    }

    public function test_an_inactive_lecturer_has_the_role_but_no_permission_until_active(): void
    {
        $pending = $this->makeUser('instructor_practical', 'Inactive');
        $this->assertSame(1, DB::table('user_access_roles')->where('user_id', $pending->id)->count());

        $access = app(AccessControlService::class);
        $this->assertSame([], $access->permissionsOf($pending));

        $pending->update(['status' => 'Active']);
        $access->forget($pending->id);
        $this->assertTrue($access->can($pending->refresh(), 'problem.create'));
    }
}
