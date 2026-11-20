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

    private function sortedSuperCodes(): array
    {
        $codes = PermissionCatalog::superCodes();
        sort($codes);

        return $codes;
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

    public function test_seeds_the_five_default_roles_with_derived_areas(): void
    {
        $expected = [
            'student' => ['student'], 'instructor' => ['teacher'], 'faculty_office' => ['office'],
            'admin' => ['admin', 'office', 'teacher'], 'department_head' => ['office', 'teacher'],
        ];
        foreach ($expected as $slug => $areas) {
            $user = $this->give($this->makeUser(), $slug);
            $this->assertSame($areas, $this->access->areasOf($user), $slug);
        }
        $this->assertTrue($this->role('admin')->is_super);
        $this->assertFalse($this->role('instructor')->is_super);
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
        $this->assertSame(['practice.solve'], $this->access->permissionsOf($student));

        $teacher = $this->give($this->makeUser(), 'instructor');
        $this->assertTrue($this->access->can($teacher, 'results.grade'));
        $this->assertTrue($this->access->can($teacher, 'student.reset_password'));
        $this->assertFalse($this->access->can($teacher, 'class.manage'), 'chỉ admin tạo lớp/thêm sinh viên');
        $this->assertFalse($this->access->can($teacher, 'results.view_all'));

        $head = $this->give($this->makeUser(), 'department_head');
        $this->assertTrue($this->access->can($head, 'results.view_all'));
        $this->assertFalse($this->access->can($head, 'results.grade'), 'trưởng bộ môn không chấm');

        $office = $this->give($this->makeUser(), 'faculty_office');
        $this->assertTrue($this->access->can($office, 'examination.manage'));
        $this->assertFalse($this->access->can($office, 'access.manage'));
    }

    public function test_multiple_roles_union_permissions_and_areas(): void
    {
        $user = $this->give($this->makeUser(), 'student', 'faculty_office');

        $this->assertTrue($this->access->can($user, 'practice.solve'));
        $this->assertTrue($this->access->can($user, 'examination.manage'));
        $this->assertSame(['office'], $this->access->areasOf($user));
    }

    public function test_areas_are_derived_from_permissions(): void
    {
        $cases = [
            [[], []],
            [['practice.solve'], ['student']],
            [['practice.solve', 'results.grade'], ['teacher']],
            [['assignment.create'], ['teacher']],
            [['exam.credentials'], ['office']],
            [['examination.manage', 'problem.create'], ['office', 'teacher']],
            [['class.manage', 'practice.solve'], ['admin']],
            [['access.manage', 'examination.manage', 'problem.share'], ['admin', 'office', 'teacher']],
        ];
        foreach ($cases as [$permissions, $expected]) {
            $this->assertSame($expected, PermissionCatalog::areasFor($permissions), json_encode($permissions));
        }
    }

    public function test_direct_permissions_are_added_on_top_of_roles(): void
    {
        $user = $this->give($this->makeUser(), 'student');
        $this->assertFalse($this->access->can($user, 'problem.create'));

        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => 'problem.create']);
        $this->access->forget($user->id);

        $this->assertTrue($this->access->can($user, 'problem.create'));
    }

    public function test_super_admin_has_everything_except_grading(): void
    {
        $admin = $this->give($this->makeUser(), 'admin');

        $this->assertTrue($this->access->isSuperAdmin($admin));
        $this->assertSame($this->sortedSuperCodes(), $this->access->permissionsOf($admin));
        $this->assertFalse($this->access->can($admin, 'results.grade'));
        $this->assertTrue($this->access->can($admin, 'access.manage'));
        $this->assertSame(['admin', 'office', 'teacher'], $this->access->areasOf($admin));

        DB::table('user_permissions')->insert(['user_id' => $admin->id, 'permission' => 'results.grade']);
        $this->access->forget($admin->id);
        $this->assertTrue($this->access->can($admin, 'results.grade'), 'quyền do chính tài khoản được cấp riêng thì được giữ (cộng gộp)');
    }

    public function test_admin_role_never_grants_grading_but_does_not_remove_it_from_other_roles(): void
    {
        $both = $this->give($this->makeUser(), 'admin', 'instructor');

        $this->assertTrue($this->access->isSuperAdmin($both));
        $this->assertTrue($this->access->can($both, 'results.grade'), 'vai trò Giảng viên cấp quyền chấm');
        $this->assertTrue($this->access->can($both, 'access.manage'), 'vai trò Admin cấp quyền quản trị');

        // Gộp đủ: mỗi quyền của từng vai trò đều có mặt trong kết quả.
        $expected = array_unique(array_merge(PermissionCatalog::superCodes(), $this->role('instructor')->permissionCodes()));
        sort($expected);
        $this->assertSame($expected, $this->access->permissionsOf($both));

        $adminOnly = $this->give($this->makeUser(), 'admin');
        $this->assertFalse($this->access->can($adminOnly, 'results.grade'));
    }

    public function test_super_role_ignores_stored_permission_rows(): void
    {
        $this->role('admin')->syncPermissions(['practice.solve']);
        $admin = $this->give($this->makeUser(), 'admin');

        $this->assertSame($this->sortedSuperCodes(), $this->access->permissionsOf($admin));
    }

    public function test_inactive_and_pending_accounts_have_no_permissions(): void
    {
        foreach (['Inactive', 'Pending'] as $status) {
            $user = $this->give($this->makeUser('visitor', $status), 'admin', 'instructor');
            $this->assertSame([], $this->access->permissionsOf($user), $status);
            $this->assertSame([], $this->access->areasOf($user), $status);
            $this->assertFalse($this->access->isSuperAdmin($user), $status);
        }
    }

    public function test_unknown_codes_stored_in_a_role_are_ignored(): void
    {
        $role = $this->role('student');
        DB::table('access_role_permissions')->insert(['access_role_id' => $role->id, 'permission' => 'contest.create']);
        $student = $this->give($this->makeUser(), 'student');

        $this->assertSame(['practice.solve'], $this->access->permissionsOf($student));
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

    public function test_catalog_has_no_contest_permission_and_super_codes_exclude_grading(): void
    {
        $this->assertFalse(PermissionCatalog::isValid('contest.create'));
        $this->assertNotContains('results.grade', PermissionCatalog::superCodes());
        $this->assertContains('results.grade', PermissionCatalog::codes());
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
