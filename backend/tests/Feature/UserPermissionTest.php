<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AccountFixture;
use Tests\TestCase;

/**
 * Quyền của khu vực quản trị theo vai trò mới: user.manage (tài khoản), class.manage (lớp, môn học, học kỳ, thẻ),
 * và bảo vệ siêu quản trị Active cuối cùng. Tài khoản test có loại tài khoản lệch với quyền để chứng minh
 * code không đọc loại tài khoản.
 */
class UserPermissionTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $legacyRole, ?array $permissions = null, string $status = 'Active'): User
    {
        $user = User::create([
            'name'            => 'UP ' . uniqid(),
            'email'           => 'up_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            ...AccountFixture::for($legacyRole),
            'status'          => $status,
            'initials'        => 'UP',
        ]);

        if ($permissions !== null) {
            DB::table('user_access_roles')->where('user_id', $user->id)->delete();
            $role = AccessRole::create(['name' => 'Role ' . uniqid()]);
            $role->syncPermissions($permissions);
            $user->accessRoles()->attach($role->id);
            app(AccessControlService::class)->forget();
        }

        return $user;
    }

    /** Làm cho $user là người Active duy nhất giữ access.manage (khóa mọi người giữ quyền này khác trong transaction của test). */
    private function makeLastAccessManager(User $user): void
    {
        $others = app(AccessControlService::class)->holdersOf('access.manage')->pluck('user_id')->filter(fn ($id) => $id !== $user->id);
        User::whereIn('id', $others)->update(['status' => 'Inactive']);
        app(AccessControlService::class)->forget();
    }

    // ───────────── route theo quyền ─────────────

    public function test_user_management_routes_need_user_manage_not_users_role(): void
    {
        $manager = $this->makeUser('student', ['user.manage']);
        $stripped = $this->makeUser('admin', ['practice.solve']);  // loại tài khoản = admin nhưng hết quyền
        $target = $this->makeUser('student');

        Sanctum::actingAs($stripped);
        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->postJson('/api/admin/users', [])->assertStatus(403);
        $this->putJson("/api/admin/users/{$target->id}", ['name' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(403);

        Sanctum::actingAs($manager);
        $this->getJson('/api/admin/users')->assertOk();
        $created = $this->postJson('/api/admin/users', [
            'email' => 'new_' . uniqid() . '@uit.edu.vn', 'password' => 'password123', 'name' => 'Người mới', 'account_type' => 'student',
        ])->assertStatus(201)->json('id');
        $this->putJson("/api/admin/users/{$created}", ['name' => 'Đã sửa'])->assertOk();
        $this->deleteJson("/api/admin/users/{$created}")->assertOk();
    }

    public function test_user_manage_does_not_open_class_or_role_routes(): void
    {
        $manager = $this->makeUser('student', ['user.manage']);
        Sanctum::actingAs($manager);

        $this->getJson('/api/admin/classes')->assertStatus(403);
        $this->getJson('/api/admin/courses')->assertStatus(403);
        $this->getJson('/api/admin/exercise-tags')->assertStatus(403);
        $this->getJson('/api/admin/access')->assertStatus(403);
    }

    public function test_class_manage_opens_classes_courses_and_terms_only(): void
    {
        $registrar = $this->makeUser('student', ['class.manage']);
        Sanctum::actingAs($registrar);

        $this->getJson('/api/admin/classes')->assertOk();
        $this->getJson('/api/admin/courses')->assertOk();
        $this->getJson('/api/admin/terms')->assertOk();
        $this->getJson('/api/admin/exercise-tags')->assertStatus(403);
        $this->getJson('/api/admin/users')->assertStatus(403);
    }

    public function test_tag_manage_opens_exercise_tags_only(): void
    {
        $head = $this->makeUser('instructor', ['tag.manage']);
        Sanctum::actingAs($head);

        $this->getJson('/api/admin/exercise-tags')->assertOk();
        $this->postJson('/api/admin/exercise-tags', ['name' => 'Thẻ ' . uniqid(), 'kind' => 'category'])->assertStatus(201);
        $this->getJson('/api/admin/classes')->assertStatus(403);
        $this->getJson('/api/admin/users')->assertStatus(403);
    }

    public function test_exercise_tag_management_is_denied_without_tag_manage_even_for_instructors_and_admins(): void
    {
        $instructor = $this->makeUser('instructor', ['problem.create', 'class.manage']);
        Sanctum::actingAs($instructor);

        $this->postJson('/api/admin/exercise-tags', ['name' => 'Thẻ ' . uniqid(), 'kind' => 'category'])->assertStatus(403);
        // Danh sách thẻ công khai vẫn đọc được để soạn bài.
        $this->getJson('/api/exercise-tags')->assertOk();
    }

    public function test_overview_needs_system_overview(): void
    {
        Sanctum::actingAs($this->makeUser('student', ['system.overview']));
        $this->getJson('/api/admin/overview/attention')->assertOk();
        foreach (['user.manage', 'class.manage', 'access.manage'] as $permission) {
            Sanctum::actingAs($this->makeUser('student', [$permission]));
            $this->getJson('/api/admin/overview/attention')->assertStatus(403);
        }
        Sanctum::actingAs($this->makeUser('admin', ['problem.create']));
        $this->getJson('/api/admin/overview/attention')->assertStatus(403);
    }

    // ───────────── bảo vệ người giữ access.manage cuối cùng ─────────────

    public function test_the_last_active_access_manager_cannot_be_deleted_or_locked(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeLastAccessManager($admin);
        $actor = $this->makeUser('student', ['user.manage']);
        Sanctum::actingAs($actor);

        $this->deleteJson("/api/admin/users/{$admin->id}")->assertStatus(422);
        $this->putJson("/api/admin/users/{$admin->id}/status", ['status' => 'Inactive'])->assertStatus(422);
        $this->putJson("/api/admin/users/{$admin->id}", ['status' => 'Inactive'])->assertStatus(422);

        $this->assertNotNull(User::find($admin->id));
        $this->assertSame('Active', $admin->fresh()->status);
    }

    public function test_an_access_manager_can_be_removed_when_another_active_one_exists(): void
    {
        $first = $this->makeUser('admin');
        $second = $this->makeUser('admin');
        $this->makeLastAccessManager($first);
        User::where('id', $second->id)->update(['status' => 'Active']);
        app(AccessControlService::class)->forget();

        Sanctum::actingAs($this->makeUser('student', ['user.manage']));
        $this->putJson("/api/admin/users/{$first->id}/status", ['status' => 'Inactive'])->assertOk();
        $this->assertSame('Inactive', $first->fresh()->status);
    }

    public function test_a_non_admin_account_can_always_be_locked_and_deleted(): void
    {
        $student = $this->makeUser('student');
        Sanctum::actingAs($this->makeUser('student', ['user.manage']));

        $this->putJson("/api/admin/users/{$student->id}/status", ['status' => 'Inactive'])->assertOk();
        $this->deleteJson("/api/admin/users/{$student->id}")->assertOk();
    }

    // ───────────── đổi loại tài khoản ─────────────

    public function test_changing_the_account_type_changes_neither_roles_nor_permissions(): void
    {
        $user = $this->makeUser('student');
        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission' => 'library.manage']);
        $access = app(AccessControlService::class);
        $rolesBefore = DB::table('user_access_roles')->where('user_id', $user->id)->pluck('access_role_id')->all();
        $permissionsBefore = $access->permissionsOf($user);
        $this->assertNotEmpty($rolesBefore);

        Sanctum::actingAs($this->makeUser('student', ['user.manage']));
        $this->putJson("/api/admin/users/{$user->id}", ['account_type' => 'staff'])
            ->assertOk()->assertJsonPath('account_type', 'staff');

        $access->forget($user->id);
        $this->assertSame('staff', $user->fresh()->account_type);
        $this->assertEqualsCanonicalizing($rolesBefore, DB::table('user_access_roles')->where('user_id', $user->id)->pluck('access_role_id')->all());
        $this->assertSame($permissionsBefore, $access->permissionsOf($user->fresh()), 'quyền do vai trò quyết định, không do loại tài khoản');
        $this->putJson("/api/admin/users/{$user->id}", ['account_type' => 'visitor'])->assertStatus(422);
    }

    // ───────────── vai trò khi tạo tài khoản ─────────────

    public function test_role_options_are_listed_for_user_managers_only(): void
    {
        Sanctum::actingAs($this->makeUser('student', ['practice.solve']));
        $this->getJson('/api/admin/users/role-options')->assertStatus(403);

        Sanctum::actingAs($this->makeUser('student', ['user.manage']));
        $rows = $this->getJson('/api/admin/users/role-options')->assertOk()->json();
        $this->assertSame(['id', 'name'], array_keys($rows[0]));
        $this->assertSame(AccessRole::count(), count($rows));
        $this->assertSame(array_unique(array_column($rows, 'id')), array_column($rows, 'id'), 'không trùng');
    }

    public function test_creating_an_account_with_a_chosen_role_and_account_type(): void
    {
        $custom = AccessRole::create(['name' => 'Tùy chỉnh ' . uniqid()]);
        $custom->syncPermissions(['library.manage']);
        Sanctum::actingAs($this->makeUser('student', ['user.manage']));
        $payload = fn (array $extra) => array_merge([
            'email' => 'cr_' . uniqid() . '@uit.edu.vn', 'password' => 'password123', 'name' => 'CR',
        ], $extra);

        $res = $this->postJson('/api/admin/users', $payload(['account_type' => 'staff', 'role_id' => (string) $custom->id]))
            ->assertStatus(201)->assertJsonPath('account_type', 'staff')->assertJsonPath('roles.0.id', (string) $custom->id);
        $this->assertSame([(string) $custom->id], DB::table('user_access_roles')->where('user_id', $res->json('id'))->pluck('access_role_id')->map(fn ($v) => (string) $v)->all());

        // Không chọn vai trò: sinh viên nhận "Sinh viên", nhân sự không có vai trò nào.
        $student = $this->postJson('/api/admin/users', $payload(['account_type' => 'student']))->assertStatus(201);
        $this->assertSame(1, DB::table('user_access_roles')->where('user_id', $student->json('id'))->count());
        $staff = $this->postJson('/api/admin/users', $payload(['account_type' => 'staff']))->assertStatus(201);
        $this->assertSame(0, DB::table('user_access_roles')->where('user_id', $staff->json('id'))->count());
        $this->assertSame([], $staff->json('roles'));

        // Kiểm tra đầu vào.
        $this->postJson('/api/admin/users', $payload([]))->assertStatus(422)->assertJsonValidationErrors('account_type', 'errors');
        $this->postJson('/api/admin/users', $payload(['account_type' => 'role']))->assertStatus(422);
        $this->postJson('/api/admin/users', $payload(['account_type' => 'staff', 'role_id' => 'khong-co']))->assertStatus(422)->assertJsonValidationErrors('role_id', 'errors');
    }

    public function test_granting_a_role_with_access_manage_needs_access_manage(): void
    {
        $manager = AccessRole::create(['name' => 'QL vai trò ' . uniqid()]);
        $manager->syncPermissions(['access.manage']);
        $plain = AccessRole::create(['name' => 'Thường ' . uniqid()]);
        $plain->syncPermissions(['practice.solve']);
        $make = fn (AccessRole $role) => [
            'email' => 'gr_' . uniqid() . '@uit.edu.vn', 'password' => 'password123', 'name' => 'GR',
            'account_type' => 'staff', 'role_id' => (string) $role->id,
        ];

        Sanctum::actingAs($this->makeUser('student', ['user.manage']));
        $this->postJson('/api/admin/users', $make($manager))->assertStatus(403);
        $this->postJson('/api/admin/users', $make($plain))->assertStatus(201);

        Sanctum::actingAs($this->makeUser('student', ['user.manage', 'access.manage']));
        $this->postJson('/api/admin/users', $make($manager))->assertStatus(201);
    }
}
