<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\PermissionCatalog;
use Database\Seeders\AccessRoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Đặc tả nền tảng phân quyền mới (Giai đoạn 1): vai trò = tập mã quyền.
 * Test chạy trên DB dev với DatabaseTransactions; seeder idempotent nên gọi lại trong mỗi test.
 */
class AccessControlTest extends TestCase
{
    use DatabaseTransactions;

    private AccessControlService $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessRoleSeeder::class);
        $this->access = app(AccessControlService::class);
    }

    private function makeUser(string $legacyRole = 'visitor', string $status = 'Active'): User
    {
        return User::create([
            'name'            => 'Access User',
            'email'           => 'access_' . uniqid() . '@gm.uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($legacyRole),
            'status'          => $status,
            'initials'        => 'AU',
        ]);
    }

    private function role(string $slug): AccessRole
    {
        return AccessRole::where('slug', $slug)->firstOrFail();
    }

    private function give(User $user, string ...$slugs): User
    {
        foreach ($slugs as $slug) {
            $user->accessRoles()->attach($this->role($slug)->id);
        }
        $this->access->forget($user->id);

        return $user;
    }

    // ───────────────────────── seed ─────────────────────────

    public function test_seeds_the_five_default_roles_each_with_exactly_its_own_permissions(): void
    {
        // DB dev có thể đã bị sửa quyền qua trang Vai trò: đặt về mặc định trước khi so sánh.
        foreach (\Database\Seeders\AccessRoleSeeder::DEFAULT_ROLES as $slug => [, $permissions]) {
            $this->role($slug)->syncPermissions($permissions);
        }
        $this->access->forget();
        $this->assertSame(['access.manage', 'class.manage', 'system.overview', 'user.manage'], $this->role('admin')->permissionCodes());

        foreach (\Database\Seeders\AccessRoleSeeder::DEFAULT_ROLES as $slug => [$name, $permissions]) {
            $user = $this->give($this->makeUser(), $slug);
            $sorted = $permissions;
            sort($sorted);

            $this->assertSame($sorted, $this->access->permissionsOf($user), $slug);
            $roles = $this->access->rolesOf($user);
            $this->assertCount(1, $roles, $slug);
            $this->assertSame($this->role($slug)->name, $roles[0]['name']);
            $this->assertSame($sorted, $roles[0]['permissions'], 'mỗi vai trò là một khu vực kèm quyền của chính nó');
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $before = AccessRole::count();
        $rows = DB::table('access_role_permissions')->count();

        $this->seed(AccessRoleSeeder::class);

        $this->assertSame($before, AccessRole::count());
        $this->assertSame($rows, DB::table('access_role_permissions')->count());
    }

    public function test_seeder_never_overwrites_an_edited_role(): void
    {
        $role = $this->role('instructor');
        $role->syncPermissions(['practice.solve']);
        $role->update(['name' => 'Giảng viên (đã sửa)']);

        $this->seed(AccessRoleSeeder::class);

        $role->refresh();
        $this->assertSame(['practice.solve'], $role->permissionCodes());
        $this->assertSame('Giảng viên (đã sửa)', $role->name);
    }

    public function test_seeder_gives_the_student_role_to_student_accounts_without_roles_and_nothing_to_staff(): void
    {
        $student = $this->makeUser('student');
        $staff = [];
        foreach (['instructor', 'department_head', 'faculty_office', 'admin', 'visitor'] as $kind) {
            $staff[$kind] = $this->makeUser($kind);
        }
        // Tài khoản mới đã được gán tự động; bỏ đi để kiểm tra đúng việc seeder gán cho người chưa có vai trò.
        $all = array_merge([$student], array_values($staff));
        DB::table('user_access_roles')->whereIn('user_id', array_map(fn (User $u) => $u->id, $all))->delete();

        $this->seed(AccessRoleSeeder::class);

        $this->assertSame([$this->role('student')->id], $student->accessRoles()->pluck('access_roles.id')->all());
        foreach ($staff as $kind => $user) {
            $this->assertSame(0, $user->accessRoles()->count(), "nhân sự {$kind} không được gán vai trò suy diễn");
        }
    }

    public function test_initial_role_is_assigned_at_creation_by_id_or_slug_and_unknown_gives_none(): void
    {
        $byId = User::create([
            'name' => 'IR1', 'email' => 'ir1_' . uniqid() . '@uit.edu.vn', 'hashed_password' => 'x', 'initials' => 'IR',
            'account_type' => 'staff', 'initial_role' => (string) $this->role('faculty_office')->id, 'status' => 'Active',
        ]);
        $bySlug = User::create([
            'name' => 'IR2', 'email' => 'ir2_' . uniqid() . '@uit.edu.vn', 'hashed_password' => 'x', 'initials' => 'IR',
            'account_type' => 'staff', 'initial_role' => 'instructor', 'status' => 'Active',
        ]);
        $unknown = User::create([
            'name' => 'IR3', 'email' => 'ir3_' . uniqid() . '@uit.edu.vn', 'hashed_password' => 'x', 'initials' => 'IR',
            'account_type' => 'student', 'initial_role' => 'khong-co-vai-tro-nay', 'status' => 'Active',
        ]);
        $plainStudent = User::create([
            'name' => 'IR4', 'email' => 'ir4_' . uniqid() . '@uit.edu.vn', 'hashed_password' => 'x', 'initials' => 'IR',
            'account_type' => 'student', 'status' => 'Active',
        ]);
        $plainStaff = User::create([
            'name' => 'IR5', 'email' => 'ir5_' . uniqid() . '@uit.edu.vn', 'hashed_password' => 'x', 'initials' => 'IR',
            'account_type' => 'staff', 'status' => 'Active',
        ]);

        $this->assertSame([$this->role('faculty_office')->id], $byId->accessRoles()->pluck('access_roles.id')->all());
        $this->assertSame([$this->role('instructor')->id], $bySlug->accessRoles()->pluck('access_roles.id')->all());
        $this->assertSame(0, $unknown->accessRoles()->count(), 'vai trò khởi tạo không tồn tại thì không rơi về mặc định');
        $this->assertSame([$this->role('student')->id], $plainStudent->accessRoles()->pluck('access_roles.id')->all());
        $this->assertSame(0, $plainStaff->accessRoles()->count());
        $this->assertArrayNotHasKey('initial_role', $byId->fresh()->getAttributes(), 'không lưu thành cột');
    }

    public function test_seeder_does_not_touch_users_who_already_have_a_role(): void
    {
        $user = $this->makeUser('student');
        DB::table('user_access_roles')->where('user_id', $user->id)->delete(); // bỏ vai trò gán tự động
        $this->give($user, 'faculty_office');

        $this->seed(AccessRoleSeeder::class);

        $this->assertSame([$this->role('faculty_office')->id], $user->accessRoles()->pluck('access_roles.id')->all());
    }

    // ───────────────────────── quyền hiệu lực ─────────────────────────

    public function test_default_role_permissions(): void
    {
        $student = $this->give($this->makeUser(), 'student');
        $this->assertSame(['assignment.do', 'practice.solve', 'submission.view'], $this->access->permissionsOf($student));
        $this->assertSame(['Sinh viên'], array_column($this->access->rolesOf($student), 'name'));

        $teacher = $this->give($this->makeUser(), 'instructor');
        $this->assertTrue($this->access->can($teacher, 'results.grade'));
        $this->assertTrue($this->access->can($teacher, 'student.reset_password'));
        $this->assertFalse($this->access->can($teacher, 'class.manage'), 'chỉ admin tạo lớp/thêm sinh viên');
        $this->assertFalse($this->access->can($teacher, 'results.view_all'));

        $head = $this->give($this->makeUser(), 'department_head');
        $this->assertTrue($this->access->can($head, 'results.view_all'));
        $this->assertFalse($this->access->can($head, 'results.grade'), 'trưởng bộ môn không chấm');
        $this->assertTrue($this->access->can($head, 'tag.manage'), 'trưởng bộ môn quản lý thẻ câu hỏi');
        $this->assertFalse($this->access->can($teacher, 'tag.manage'));

        $office = $this->give($this->makeUser(), 'faculty_office');
        $this->assertTrue($this->access->can($office, 'examination.manage'));
        $this->assertFalse($this->access->can($office, 'access.manage'));
    }

    public function test_multiple_roles_union_permissions_and_each_role_is_its_own_area(): void
    {
        $user = $this->give($this->makeUser(), 'student', 'faculty_office');

        $this->assertTrue($this->access->can($user, 'practice.solve'));
        $this->assertTrue($this->access->can($user, 'examination.manage'));

        $roles = collect($this->access->rolesOf($user))->keyBy('name');
        $this->assertSame(['Giáo vụ khoa', 'Sinh viên'], $roles->keys()->sort()->values()->all());
        $this->assertNotContains('examination.manage', $roles['Sinh viên']['permissions'], 'quyền của vai trò này không lẫn sang khu vực của vai trò kia');
        $this->assertContains('examination.manage', $roles['Giáo vụ khoa']['permissions']);
    }

    public function test_a_new_role_becomes_a_new_area_with_its_own_permissions(): void
    {
        $role = AccessRole::create(['name' => 'Trợ giảng ' . uniqid()]);
        $role->syncPermissions(['results.grade', 'exam.proctor', 'not.a.permission']);
        $user = $this->makeUser();
        $user->accessRoles()->attach($role->id);
        $this->access->forget($user->id);

        $this->assertSame(
            [['id' => (string) $role->id, 'name' => $role->name, 'permissions' => ['exam.proctor', 'results.grade']]],
            $this->access->rolesOf($user),
            'mã quyền không có trong danh mục bị bỏ qua',
        );

        $role->syncPermissions(['results.grade', 'exam.proctor', 'statistics.view']);
        $this->access->forget($user->id);
        $this->assertSame(['exam.proctor', 'results.grade', 'statistics.view'], $this->access->rolesOf($user)[0]['permissions'], 'gán thêm quyền thì khu vực có thêm quyền đó');
    }

    public function test_direct_permissions_are_not_part_of_any_role(): void
    {
        $user = $this->give($this->makeUser(), 'student');
        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => 'statistics.view']);
        $this->access->forget($user->id);

        $this->assertSame(['statistics.view'], $this->access->directPermissionsOf($user));
        $this->assertContains('statistics.view', $this->access->permissionsOf($user));
        $this->assertNotContains('statistics.view', $this->access->rolesOf($user)[0]['permissions']);
    }

    public function test_direct_permissions_are_added_on_top_of_roles(): void
    {
        $user = $this->give($this->makeUser(), 'student');
        $this->assertFalse($this->access->can($user, 'problem.create'));

        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => 'problem.create']);
        $this->access->forget($user->id);

        $this->assertTrue($this->access->can($user, 'problem.create'));
    }

    public function test_admin_role_has_only_its_four_permissions_and_nothing_implicit(): void
    {
        $admin = $this->give($this->makeUser(), 'admin');

        $this->assertSame(['access.manage', 'class.manage', 'system.overview', 'user.manage'], $this->access->permissionsOf($admin));
        foreach (['examination.manage', 'problem.create', 'results.grade', 'tag.manage', 'practice.solve'] as $code) {
            $this->assertFalse($this->access->can($admin, $code), $code);
        }
    }

    public function test_admin_role_is_edited_like_any_other_role(): void
    {
        $this->role('admin')->syncPermissions(['access.manage', 'examination.manage']);
        $admin = $this->give($this->makeUser(), 'admin');

        $this->assertSame(['access.manage', 'examination.manage'], $this->access->permissionsOf($admin));
    }

    public function test_roles_combine_permissions_without_removing_any(): void
    {
        $both = $this->give($this->makeUser(), 'admin', 'instructor');

        $this->assertTrue($this->access->can($both, 'results.grade'), 'vai trò Giảng viên cấp quyền chấm');
        $this->assertTrue($this->access->can($both, 'access.manage'), 'vai trò Admin cấp quyền quản trị');

        $expected = array_unique(array_merge($this->role('admin')->permissionCodes(), $this->role('instructor')->permissionCodes()));
        sort($expected);
        $this->assertSame($expected, $this->access->permissionsOf($both));
        $this->assertCount(2, $this->access->rolesOf($both));
    }

    public function test_last_active_access_manager_is_detected_through_roles_and_direct_grants(): void
    {
        $manager = $this->give($this->makeUser(), 'admin');
        DB::table('user_access_roles')->whereIn('access_role_id', [$this->role('admin')->id])->where('user_id', '!=', $manager->id)->delete();
        DB::table('user_permissions')->where('permission', 'access.manage')->delete();
        $this->access->forget();

        $this->assertTrue($this->access->isLastActiveAccessManager($manager));

        $second = $this->makeUser();
        DB::table('user_permissions')->insert(['user_id' => $second->id, 'permission' => 'access.manage']);
        $this->access->forget();

        $this->assertFalse($this->access->isLastActiveAccessManager($manager), 'còn người khác giữ quyền (gán riêng) nên không phải người cuối');
        $this->assertTrue($this->access->hasActiveHolder('access.manage', $manager->id));
    }

    public function test_inactive_accounts_have_no_permissions(): void
    {
        foreach (['Inactive'] as $status) {
            $user = $this->give($this->makeUser('visitor', $status), 'admin', 'instructor');
            $this->assertSame([], $this->access->permissionsOf($user), $status);
            $this->assertSame([], $this->access->rolesOf($user), $status);
        }
    }

    public function test_unknown_codes_stored_in_a_role_are_ignored(): void
    {
        $role = $this->role('student');
        DB::table('access_role_permissions')->insert(['access_role_id' => $role->id, 'permission' => 'contest.create']);
        $student = $this->give($this->makeUser(), 'student');

        $this->assertSame(['assignment.do', 'practice.solve', 'submission.view'], $this->access->permissionsOf($student));
        $this->assertFalse($this->access->can($student, 'contest.create'));
    }

    public function test_user_without_roles_has_nothing(): void
    {
        $user = $this->makeUser();

        $this->assertSame([], $this->access->permissionsOf($user));
        $this->assertFalse($this->access->canAny($user, ['practice.solve', 'access.manage']));
    }

    public function test_can_any_requires_just_one_match(): void
    {
        $student = $this->give($this->makeUser(), 'student');

        $this->assertTrue($this->access->canAny($student, ['access.manage', 'practice.solve']));
        $this->assertFalse($this->access->canAny($student, ['access.manage', 'class.manage']));
    }

    public function test_result_is_cached_until_forgotten(): void
    {
        $user = $this->give($this->makeUser(), 'student');
        $this->assertFalse($this->access->can($user, 'problem.create'));

        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => 'problem.create']);
        $this->assertFalse($this->access->can($user, 'problem.create'), 'còn trong bộ nhớ đệm của request');

        $this->access->forget($user->id);
        $this->assertTrue($this->access->can($user, 'problem.create'));
    }

    public function test_catalog_has_no_contest_permission(): void
    {
        $this->assertFalse(PermissionCatalog::isValid('contest.create'));
        $this->assertContains('results.grade', PermissionCatalog::codes());
        $this->assertContains('tag.manage', PermissionCatalog::codes());
    }

    // ───────────────────────── middleware capability: ─────────────────────────

    private function registerProbeRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'capability:problem.create'])->get('/api/_probe/one', fn () => ['ok' => true]);
        Route::middleware(['api', 'auth:sanctum', 'capability:results.grade,results.view_all'])->get('/api/_probe/any', fn () => ['ok' => true]);
    }

    public function test_capability_middleware_requires_authentication(): void
    {
        $this->registerProbeRoutes();

        $this->getJson('/api/_probe/one')->assertStatus(401);
    }

    public function test_capability_middleware_allows_and_denies_by_permission(): void
    {
        $this->registerProbeRoutes();

        Sanctum::actingAs($this->give($this->makeUser(), 'student'));
        $this->getJson('/api/_probe/one')->assertStatus(403)->assertJsonStructure(['message', 'detail']);

        Sanctum::actingAs($this->give($this->makeUser(), 'instructor'));
        $this->getJson('/api/_probe/one')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_capability_middleware_accepts_any_of_the_listed_codes(): void
    {
        $this->registerProbeRoutes();

        foreach (['instructor', 'department_head'] as $slug) {
            Sanctum::actingAs($this->give($this->makeUser(), $slug));
            $this->getJson('/api/_probe/any')->assertOk();
        }

        Sanctum::actingAs($this->give($this->makeUser(), 'student'));
        $this->getJson('/api/_probe/any')->assertStatus(403);
    }

    public function test_capability_middleware_denies_inactive_accounts(): void
    {
        $this->registerProbeRoutes();

        Sanctum::actingAs($this->give($this->makeUser('visitor', 'Inactive'), 'instructor'));
        $this->getJson('/api/_probe/one')->assertStatus(403);
    }
}
