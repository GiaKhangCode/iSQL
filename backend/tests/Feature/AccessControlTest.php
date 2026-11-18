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
            'role'            => $legacyRole,
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

    public function test_seeds_the_five_default_roles_with_areas(): void
    {
        $expected = [
            'student' => 'student', 'instructor' => 'teacher', 'faculty_office' => 'office',
            'admin' => 'admin', 'department_head' => 'teacher',
        ];
        foreach ($expected as $slug => $area) {
            $this->assertSame($area, $this->role($slug)->area, $slug);
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

    public function test_seeder_assigns_existing_users_from_the_legacy_role_column(): void
    {
        $map = [
            'student' => 'student', 'instructor' => 'instructor', 'instructor_practical' => 'instructor',
            'instructor_theory' => 'instructor', 'department_head' => 'department_head',
            'faculty_office' => 'faculty_office', 'admin' => 'admin',
        ];
        $users = [];
        foreach ($map as $legacy => $slug) {
            $users[$legacy] = $this->makeUser($legacy);
        }
        $stranger = $this->makeUser('visitor');

        $this->seed(AccessRoleSeeder::class);

        foreach ($map as $legacy => $slug) {
            $this->assertSame(
                [$this->role($slug)->id],
                $users[$legacy]->accessRoles()->pluck('access_roles.id')->all(),
                $legacy
            );
        }
        $this->assertSame(0, $stranger->accessRoles()->count(), 'role lạ không được gán vai trò nào');
    }

    public function test_seeder_does_not_touch_users_who_already_have_a_role(): void
    {
        $user = $this->makeUser('student');
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
        $this->assertSame(['office', 'student'], $this->access->areasOf($user));
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
        $this->assertSame(['admin'], $this->access->areasOf($admin));

        DB::table('user_permissions')->insert(['user_id' => $admin->id, 'permission' => 'results.grade']);
        $this->access->forget($admin->id);
        $this->assertFalse($this->access->can($admin, 'results.grade'), 'quyền riêng cũng không cho admin chấm bài');
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
