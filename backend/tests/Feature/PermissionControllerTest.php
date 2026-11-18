<?php

namespace Tests\Feature;

use App\Models\FunctionModel;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test đặc tả (characterization) cho App\Http\Controllers\PermissionController,
 * viết TRƯỚC khi tách logic sang service. Bổ sung cho PermissionTest (happy path).
 *
 * DB dev có dữ liệu sẵn nên test dùng tên/mã duy nhất và so sánh độ chênh,
 * không giả định danh sách rỗng.
 */
class PermissionControllerTest extends TestCase
{
    use DatabaseTransactions;

    /** Danh sách capability cố định mà /api/auth/permissions trả cho admin. */
    private const ALL_CAPABILITIES = [
        'practice.solve', 'problem.create', 'problem.share', 'library.manage', 'library.share',
        'assignment.create', 'contest.create', 'examination.manage', 'exam.credentials',
        'exam.proctor', 'results.grade', 'statistics.all', 'access.manage',
    ];

    private const INSTRUCTOR_CAPABILITIES = [
        'practice.solve', 'problem.create', 'problem.share', 'library.manage', 'library.share',
        'assignment.create', 'contest.create', 'examination.manage', 'exam.credentials',
        'exam.proctor', 'results.grade', 'statistics.all',
    ];

    private const FACULTY_CAPABILITIES = [
        'contest.create', 'examination.manage', 'exam.credentials', 'results.grade',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        Sanctum::actingAs($this->admin);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function makeUser(string $role, string $status = 'Active'): User
    {
        return User::create([
            'name'            => 'PC ' . $role,
            'email'           => 'pc_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => $role,
            'status'          => $status,
            'initials'        => 'PC',
        ]);
    }

    private function makeFunction(): FunctionModel
    {
        return FunctionModel::create([
            'name' => 'PC Function ' . uniqid(),
            'code' => 'PC_FN_' . strtoupper(uniqid()),
        ]);
    }

    private function makeRole(FunctionModel $fn, array $flags = []): Role
    {
        return Role::create(array_merge([
            'function_id' => $fn->id,
            'name'        => 'PC Role ' . uniqid(),
            'view'        => false,
            'add'         => false,
            'edit'        => false,
            'delete'      => false,
            'download'    => false,
        ], $flags));
    }

    private function makeGroup(): RoleGroup
    {
        return RoleGroup::create(['name' => 'PC Group ' . uniqid()]);
    }

    private function actingAsRole(string $role, string $status = 'Active'): User
    {
        $user = $this->makeUser($role, $status);
        Sanctum::actingAs($user);

        return $user;
    }

    // ───────────────────────── quyền truy cập ─────────────────────────

    /** @return array<string, array{0:string,1:string}> */
    public static function adminRoutes(): array
    {
        return [
            'my-permissions' => ['GET', '/api/admin/my-permissions'],
            'functions'      => ['GET', '/api/admin/functions'],
            'roles.index'    => ['GET', '/api/admin/roles'],
            'roles.store'    => ['POST', '/api/admin/roles'],
            'roles.destroy'  => ['DELETE', '/api/admin/roles/1'],
            'groups.index'   => ['GET', '/api/admin/role-groups'],
            'groups.store'   => ['POST', '/api/admin/role-groups'],
            'groups.destroy' => ['DELETE', '/api/admin/role-groups/1'],
            'groups.assign'  => ['POST', '/api/admin/role-groups/1/assign-roles'],
            'users.assign'   => ['POST', '/api/admin/users/abc/assign'],
        ];
    }

    /**
     * @dataProvider adminRoutes
     */
    public function test_non_admin_roles_are_forbidden_on_every_admin_permission_route(string $method, string $uri): void
    {
        foreach (['student', 'instructor', 'instructor_practical', 'department_head', 'faculty_office'] as $role) {
            $this->actingAsRole($role);
            $this->json($method, $uri, [])
                ->assertStatus(403)
                ->assertJsonPath('detail', 'Permission denied');
        }
    }

    public function test_pending_admin_is_blocked_by_active_middleware(): void
    {
        $this->actingAsRole('admin', 'Pending');

        $this->getJson('/api/admin/functions')->assertStatus(403);
    }

    public function test_guest_gets_401_on_admin_and_auth_permission_routes(): void
    {
        // setUp đã Sanctum::actingAs(admin); bỏ user đó khỏi guard để request thành khách.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/my-permissions')->assertStatus(401);
        $this->getJson('/api/auth/my-permissions')->assertStatus(401);
        $this->getJson('/api/auth/permissions')->assertStatus(401);
    }

    // ───────────────────────── my-permissions (ma trận) ─────────────────────────

    public function test_my_permissions_for_admin_lists_every_function_with_all_flags_true(): void
    {
        $fn = $this->makeFunction();

        $res = $this->getJson('/api/admin/my-permissions')->assertOk();

        $matrix = $res->json('permissions');
        $this->assertSame($matrix, $res->json('permission'), 'hai khóa permissions/permission phải giống hệt nhau');
        $this->assertCount(FunctionModel::count(), $matrix);
        $this->assertSame(
            ['name' => $fn->name, 'view' => true, 'add' => true, 'edit' => true, 'delete' => true, 'download' => true],
            $matrix[$fn->code]
        );
        foreach (['USER_MGMT', 'CLASS_MGMT', 'PROBLEM_MGMT'] as $code) {
            $this->assertTrue($matrix[$code]['delete'], $code);
        }
    }

    public function test_my_permissions_merges_group_and_direct_roles_with_or(): void
    {
        $fn = $this->makeFunction();
        $other = $this->makeFunction();
        $viewer = $this->makeRole($fn, ['view' => true]);
        $editor = $this->makeRole($fn, ['add' => true, 'edit' => true]);
        $otherRole = $this->makeRole($other, ['download' => true]);

        $group = $this->makeGroup();
        $group->roles()->attach($viewer->id);

        $user = $this->makeUser('visitor');
        $user->roleGroups()->attach($group->id);
        $user->directRoles()->attach([$editor->id, $otherRole->id]);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/auth/my-permissions')->assertOk();

        $this->assertSame(
            ['name' => $fn->name, 'view' => true, 'add' => true, 'edit' => true, 'delete' => false, 'download' => false],
            $res->json("permissions.{$fn->code}")
        );
        $this->assertSame(
            ['name' => $other->name, 'view' => false, 'add' => false, 'edit' => false, 'delete' => false, 'download' => true],
            $res->json("permissions.{$other->code}")
        );
        $this->assertSame($res->json('permissions'), $res->json('permission'));
    }

    public function test_my_permissions_for_user_without_any_role_is_empty(): void
    {
        $this->actingAsRole('visitor');

        $res = $this->getJson('/api/auth/my-permissions')->assertOk();

        $this->assertSame([], $res->json('permissions'));
        $this->assertSame([], $res->json('permission'));
    }

    public function test_my_permissions_ignores_roles_of_other_users_and_groups(): void
    {
        $fn = $this->makeFunction();
        $role = $this->makeRole($fn, ['view' => true]);
        $group = $this->makeGroup();
        $group->roles()->attach($role->id);
        $this->makeUser('visitor')->roleGroups()->attach($group->id); // người khác

        $this->actingAsRole('visitor');

        $this->getJson('/api/auth/my-permissions')->assertOk()->assertJsonMissingPath("permissions.{$fn->code}");
    }

    // ───────────────────────── /api/auth/permissions (capability) ─────────────────────────

    public function test_effective_permissions_for_admin_is_the_full_capability_list(): void
    {
        $this->getJson('/api/auth/permissions')
            ->assertOk()
            ->assertExactJson(['permissions' => self::ALL_CAPABILITIES]);
    }

    /** @return array<string, array{0:string}> */
    public static function facultyRoles(): array
    {
        return [
            'faculty_office' => ['faculty_office'],
            'Faculty Office' => ['Faculty Office'],
            'giáo vụ khoa'   => ['giáo vụ khoa'],
        ];
    }

    /**
     * @dataProvider facultyRoles
     */
    public function test_effective_permissions_for_faculty_office(string $role): void
    {
        $this->actingAsRole($role);

        $this->getJson('/api/auth/permissions')
            ->assertOk()
            ->assertExactJson(['permissions' => self::FACULTY_CAPABILITIES]);
    }

    /** @return array<string, array{0:string}> */
    public static function instructorRoles(): array
    {
        return [
            'instructor_practical' => ['instructor_practical'],
            'instructor_theory'    => ['instructor_theory'],
            'department_head'      => ['department_head'],
            'instructor (cũ)'      => ['instructor'],
        ];
    }

    /**
     * @dataProvider instructorRoles
     */
    public function test_effective_permissions_for_instructor_roles(string $role): void
    {
        $this->actingAsRole($role);

        $res = $this->getJson('/api/auth/permissions')
            ->assertOk()
            ->assertExactJson(['permissions' => self::INSTRUCTOR_CAPABILITIES]);

        $this->assertNotContains('access.manage', $res->json('permissions'));
    }

    public function test_effective_permissions_for_student_and_unknown_roles_is_practice_only(): void
    {
        foreach (['student', 'visitor'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/auth/permissions')
                ->assertOk()
                ->assertExactJson(['permissions' => ['practice.solve']]);
        }
    }

    public function test_effective_permissions_do_not_depend_on_the_rbac_matrix(): void
    {
        // Khóa lại hành vi hiện tại: capability của frontend chỉ phụ thuộc cột users.role,
        // KHÔNG đọc nhóm quyền/quyền trực tiếp (khác ma trận ở /my-permissions).
        $student = $this->makeUser('student');
        $fn = $this->makeFunction();
        $student->directRoles()->attach($this->makeRole($fn, ['view' => true, 'add' => true])->id);
        Sanctum::actingAs($student);

        $this->getJson('/api/auth/permissions')->assertOk()->assertExactJson(['permissions' => ['practice.solve']]);
        $this->assertTrue($this->getJson('/api/auth/my-permissions')->json("permissions.{$fn->code}.add"));
    }

    // ───────────────────────── functions ─────────────────────────

    public function test_functions_are_listed_in_id_order_and_include_new_ones(): void
    {
        $fn = $this->makeFunction();

        $res = $this->getJson('/api/admin/functions')->assertOk();

        $ids = array_column($res->json(), 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertCount(FunctionModel::count(), $ids);
        $this->assertContains($fn->id, $ids);
        $codes = array_column($res->json(), 'code');
        foreach (['USER_MGMT', 'PERMISSION_MGMT', 'CLASS_MGMT', 'PROBLEM_MGMT', 'ASSIGNMENT_MGMT', 'PRACTICE_MGMT'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_function_create_and_delete_are_not_routed(): void
    {
        // storeFunction/deleteFunction/getUsers tồn tại trong controller nhưng không có route.
        $this->postJson('/api/admin/functions', ['name' => 'x', 'code' => 'X'])->assertStatus(405);
        $this->deleteJson('/api/admin/functions/1')->assertStatus(404);
    }

    // ───────────────────────── roles ─────────────────────────

    public function test_roles_index_is_id_ordered_and_embeds_function(): void
    {
        $fn = $this->makeFunction();
        $role = $this->makeRole($fn, ['view' => true, 'download' => true]);

        $res = $this->getJson('/api/admin/roles')->assertOk();

        $ids = array_column($res->json(), 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertCount(Role::count(), $ids);

        $row = collect($res->json())->firstWhere('id', $role->id);
        $this->assertSame($fn->code, $row['function_model']['code']);
        $this->assertSame($fn->id, $row['function_id']);
        $this->assertTrue($row['view']);
        $this->assertTrue($row['download']);
        $this->assertFalse($row['add']);
        $this->assertFalse($row['edit']);
        $this->assertFalse($row['delete']);
    }

    public function test_store_role_with_only_function_id_stores_false_flags_but_responds_null(): void
    {
        $fn = $this->makeFunction();

        $res = $this->postJson('/api/admin/roles', ['function_id' => $fn->id])->assertStatus(201);

        $res->assertJsonPath('function_id', $fn->id)
            ->assertJsonPath('function_model.code', $fn->code)
            ->assertJsonPath('name', null);
        $stored = Role::findOrFail($res->json('id'));
        foreach (['view', 'add', 'edit', 'delete', 'download'] as $flag) {
            $this->assertFalse($stored->{$flag}, "DB: {$flag} mặc định false");
            // LỖI ĐÃ BIẾT (khóa lại hành vi hiện tại): model không được refresh sau create()
            // nên cờ bị bỏ trống trả về null thay vì false.
            $this->assertNull($res->json($flag), "response: {$flag} hiện là null");
        }
    }

    public function test_store_role_persists_each_flag_independently(): void
    {
        $fn = $this->makeFunction();
        $flags = ['view', 'add', 'edit', 'delete', 'download'];

        foreach ($flags as $flag) {
            $res = $this->postJson('/api/admin/roles', ['function_id' => $fn->id, $flag => true])->assertStatus(201);
            $this->assertTrue($res->json($flag), "response: {$flag}");
            $stored = Role::findOrFail($res->json('id'));
            foreach ($flags as $other) {
                $this->assertSame($other === $flag, $stored->{$other}, "DB: gửi {$flag} → {$other}");
            }
        }
    }

    public function test_store_role_accepts_numeric_and_string_booleans(): void
    {
        $fn = $this->makeFunction();

        $res = $this->postJson('/api/admin/roles', [
            'function_id' => $fn->id, 'view' => 1, 'add' => '1', 'edit' => 0, 'delete' => '0', 'download' => true,
        ])->assertStatus(201);

        $this->assertTrue($res->json('view'));
        $this->assertTrue($res->json('add'));
        $this->assertFalse($res->json('edit'));
        $this->assertFalse($res->json('delete'));
        $this->assertTrue($res->json('download'));
    }

    /** @return array<string, array{0:array<string,mixed>,1:string}> */
    public static function invalidRolePayloads(): array
    {
        return [
            'thiếu function_id'     => [[], 'function_id'],
            'function_id không có'  => [['function_id' => 99999999], 'function_id'],
            'name quá 255 ký tự'    => [['function_id' => '@fn', 'name' => str_repeat('a', 256)], 'name'],
            'view không phải bool'  => [['function_id' => '@fn', 'view' => 'abc'], 'view'],
            'delete không phải bool' => [['function_id' => '@fn', 'delete' => 'yes'], 'delete'],
            'name là mảng'          => [['function_id' => '@fn', 'name' => ['x']], 'name'],
        ];
    }

    /**
     * @dataProvider invalidRolePayloads
     */
    public function test_store_role_rejects_invalid_payload_with_422_and_creates_nothing(array $payload, string $errorField): void
    {
        $fn = $this->makeFunction();
        if (($payload['function_id'] ?? null) === '@fn') {
            $payload['function_id'] = $fn->id;
        }
        $before = Role::count();

        $res = $this->postJson('/api/admin/roles', $payload)->assertStatus(422);

        $res->assertJsonValidationErrors($errorField, 'errors');
        $this->assertNotEmpty($res->json('detail'));
        $this->assertSame($before, Role::count());
    }

    public function test_store_role_ignores_mass_assignment_of_id(): void
    {
        $fn = $this->makeFunction();

        $res = $this->postJson('/api/admin/roles', ['function_id' => $fn->id, 'id' => 987654321])->assertStatus(201);

        $this->assertNotSame(987654321, $res->json('id'));
    }

    public function test_delete_role_returns_message_and_removes_row(): void
    {
        $role = $this->makeRole($this->makeFunction());

        $this->deleteJson("/api/admin/roles/{$role->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Đã xóa quyền chi tiết thành công!']);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_delete_unknown_role_is_404_json(): void
    {
        $before = Role::count();

        $this->deleteJson('/api/admin/roles/99999999')->assertStatus(404)->assertJsonStructure(['message', 'detail']);

        $this->assertSame($before, Role::count());
    }

    public function test_delete_role_cascades_to_group_and_direct_assignments_and_revokes_permission(): void
    {
        $fn = $this->makeFunction();
        $viaGroup = $this->makeRole($fn, ['view' => true]);
        $direct = $this->makeRole($fn, ['edit' => true]);
        $keep = $this->makeRole($fn, ['download' => true]);
        $group = $this->makeGroup();
        $group->roles()->attach([$viaGroup->id, $keep->id]);
        $user = $this->makeUser('visitor');
        $user->roleGroups()->attach($group->id);
        $user->directRoles()->attach($direct->id);

        $this->deleteJson("/api/admin/roles/{$viaGroup->id}")->assertOk();
        $this->deleteJson("/api/admin/roles/{$direct->id}")->assertOk();

        $this->assertDatabaseMissing('role_group_assign_role', ['role_id' => $viaGroup->id]);
        $this->assertDatabaseMissing('account_assign_role', ['role_id' => $direct->id]);
        // role còn lại vẫn nằm trong nhóm
        $this->assertDatabaseHas('role_group_assign_role', ['role_group_id' => $group->id, 'role_id' => $keep->id]);

        Sanctum::actingAs($user);
        $matrix = $this->getJson('/api/auth/my-permissions')->assertOk()->json("permissions.{$fn->code}");
        $this->assertSame(
            ['name' => $fn->name, 'view' => false, 'add' => false, 'edit' => false, 'delete' => false, 'download' => true],
            $matrix
        );
    }

    // ───────────────────────── role groups ─────────────────────────

    public function test_role_groups_index_is_id_ordered_with_embedded_roles(): void
    {
        $fn = $this->makeFunction();
        $role = $this->makeRole($fn, ['view' => true]);
        $group = $this->makeGroup();
        $group->roles()->attach($role->id);
        $empty = $this->makeGroup();

        $res = $this->getJson('/api/admin/role-groups')->assertOk();

        $ids = array_column($res->json(), 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertCount(RoleGroup::count(), $ids);

        $rows = collect($res->json());
        $this->assertSame([$role->id], array_column($rows->firstWhere('id', $group->id)['roles'], 'id'));
        $this->assertSame([], $rows->firstWhere('id', $empty->id)['roles']);
    }

    public function test_store_role_group_returns_201_and_persists(): void
    {
        $name = 'PC Nhóm ' . uniqid();

        $res = $this->postJson('/api/admin/role-groups', ['name' => $name, 'description' => 'mô tả'])->assertStatus(201);

        $res->assertJsonPath('name', $name)->assertJsonPath('description', 'mô tả');
        $this->assertDatabaseHas('role_groups', ['id' => $res->json('id'), 'name' => $name]);
    }

    public function test_store_role_group_description_is_optional(): void
    {
        $res = $this->postJson('/api/admin/role-groups', ['name' => 'PC ' . uniqid()])->assertStatus(201);

        $res->assertJsonPath('description', null);
    }

    public function test_store_role_group_allows_duplicate_names(): void
    {
        // Khóa lại hành vi hiện tại: không có ràng buộc unique trên name.
        $name = 'PC Trùng ' . uniqid();

        $this->postJson('/api/admin/role-groups', ['name' => $name])->assertStatus(201);
        $this->postJson('/api/admin/role-groups', ['name' => $name])->assertStatus(201);

        $this->assertSame(2, RoleGroup::where('name', $name)->count());
    }

    /** @return array<string, array{0:array<string,mixed>,1:string}> */
    public static function invalidGroupPayloads(): array
    {
        return [
            'thiếu name'            => [[], 'name'],
            'name rỗng'             => [['name' => ''], 'name'],
            'name quá 255'          => [['name' => str_repeat('a', 256)], 'name'],
            'description quá 255'   => [['name' => 'ok', 'description' => str_repeat('a', 256)], 'description'],
        ];
    }

    /**
     * @dataProvider invalidGroupPayloads
     */
    public function test_store_role_group_rejects_invalid_payload(array $payload, string $errorField): void
    {
        $before = RoleGroup::count();

        $res = $this->postJson('/api/admin/role-groups', $payload)->assertStatus(422);

        $res->assertJsonValidationErrors($errorField, 'errors');
        $this->assertNotEmpty($res->json('detail'));
        $this->assertSame($before, RoleGroup::count());
    }

    public function test_delete_role_group_cascades_and_revokes_permission_but_keeps_roles_and_users(): void
    {
        $fn = $this->makeFunction();
        $role = $this->makeRole($fn, ['view' => true]);
        $group = $this->makeGroup();
        $group->roles()->attach($role->id);
        $user = $this->makeUser('visitor');
        $user->roleGroups()->attach($group->id);

        $this->deleteJson("/api/admin/role-groups/{$group->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Đã xóa nhóm quyền thành công!']);

        $this->assertDatabaseMissing('role_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('role_group_assign_role', ['role_group_id' => $group->id]);
        $this->assertDatabaseMissing('account_assign_role_group', ['role_group_id' => $group->id]);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        Sanctum::actingAs($user);
        $this->assertSame([], $this->getJson('/api/auth/my-permissions')->assertOk()->json('permissions'));
    }

    public function test_delete_unknown_role_group_is_404_json(): void
    {
        $before = RoleGroup::count();

        $this->deleteJson('/api/admin/role-groups/99999999')->assertStatus(404)->assertJsonStructure(['message', 'detail']);

        $this->assertSame($before, RoleGroup::count());
    }

    // ───────────────────────── assign-roles (nhóm ← quyền) ─────────────────────────

    public function test_assign_roles_to_group_replaces_the_whole_set(): void
    {
        $fn = $this->makeFunction();
        [$a, $b, $c] = [$this->makeRole($fn), $this->makeRole($fn), $this->makeRole($fn)];
        $group = $this->makeGroup();
        $group->roles()->attach([$a->id, $b->id]);

        $res = $this->postJson("/api/admin/role-groups/{$group->id}/assign-roles", ['role_ids' => [$b->id, $c->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Cập nhật danh sách quyền cho nhóm thành công!')
            ->assertJsonPath('group.id', $group->id);

        $this->assertEqualsCanonicalizing([$b->id, $c->id], array_column($res->json('group.roles'), 'id'));
        $this->assertEqualsCanonicalizing(
            [$b->id, $c->id],
            $group->roles()->pluck('roles.id')->all()
        );
    }

    public function test_assign_roles_with_empty_or_missing_role_ids_detaches_everything(): void
    {
        $fn = $this->makeFunction();
        $role = $this->makeRole($fn);
        $group = $this->makeGroup();

        $group->roles()->attach($role->id);
        $this->postJson("/api/admin/role-groups/{$group->id}/assign-roles", ['role_ids' => []])->assertOk();
        $this->assertSame(0, $group->roles()->count());

        // Khóa lại hành vi hiện tại: thiếu hẳn role_ids cũng xóa sạch (không phải no-op).
        $group->roles()->attach($role->id);
        $res = $this->postJson("/api/admin/role-groups/{$group->id}/assign-roles", [])->assertOk();
        $this->assertSame(0, $group->roles()->count());
        $this->assertSame([], $res->json('group.roles'));
    }

    public function test_assign_roles_tolerates_duplicate_ids(): void
    {
        $role = $this->makeRole($this->makeFunction());
        $group = $this->makeGroup();

        $this->postJson("/api/admin/role-groups/{$group->id}/assign-roles", ['role_ids' => [$role->id, $role->id]])->assertOk();

        $this->assertSame(1, $group->roles()->count());
    }

    public function test_assign_roles_to_unknown_group_is_404(): void
    {
        $this->postJson('/api/admin/role-groups/99999999/assign-roles', ['role_ids' => []])
            ->assertStatus(404)
            ->assertJsonStructure(['message', 'detail']);
    }

    public function test_assign_roles_with_unknown_role_id_errors_and_wipes_the_group(): void
    {
        $role = $this->makeRole($this->makeFunction());
        $group = $this->makeGroup();
        $group->roles()->attach($role->id);

        $res = $this->postJson("/api/admin/role-groups/{$group->id}/assign-roles", ['role_ids' => [99999999]]);

        // LỖI ĐÃ BIẾT (khóa lại hành vi hiện tại): không validate `exists` và không có
        // transaction → sync() gỡ quyền cũ rồi mới vi phạm khóa ngoại (500), nhóm mất sạch quyền.
        // Khi sửa lỗi này: kỳ vọng đúng là 422 và nhóm giữ nguyên [$role->id].
        $res->assertStatus(500);
        $this->assertSame([], $group->roles()->pluck('roles.id')->all());
    }

    public function test_assigning_roles_to_group_grants_them_to_its_members(): void
    {
        $fn = $this->makeFunction();
        $role = $this->makeRole($fn, ['view' => true, 'edit' => true]);
        $group = $this->makeGroup();
        $user = $this->makeUser('visitor');
        $user->roleGroups()->attach($group->id);

        $this->postJson("/api/admin/role-groups/{$group->id}/assign-roles", ['role_ids' => [$role->id]])->assertOk();

        Sanctum::actingAs($user);
        $this->assertSame(
            ['name' => $fn->name, 'view' => true, 'add' => false, 'edit' => true, 'delete' => false, 'download' => false],
            $this->getJson('/api/auth/my-permissions')->json("permissions.{$fn->code}")
        );
    }

    // ───────────────────────── users/{id}/assign (tài khoản ← nhóm/quyền) ─────────────────────────

    public function test_assign_to_user_syncs_groups_and_roles_and_reports_effective_permissions(): void
    {
        $fn = $this->makeFunction();
        $groupRole = $this->makeRole($fn, ['view' => true]);
        $directRole = $this->makeRole($fn, ['delete' => true]);
        $group = $this->makeGroup();
        $group->roles()->attach($groupRole->id);
        $target = $this->makeUser('visitor');

        $res = $this->postJson("/api/admin/users/{$target->id}/assign", [
            'group_ids' => [$group->id],
            'role_ids'  => [$directRole->id],
        ])->assertOk()->assertJsonPath('message', 'Cập nhật phân quyền tài khoản thành công!');

        $this->assertSame(
            ['name' => $fn->name, 'view' => true, 'add' => false, 'edit' => false, 'delete' => true, 'download' => false],
            $res->json("effective_permissions.{$fn->code}"),
            'effective_permissions phải phản ánh trạng thái SAU khi gán'
        );
        $this->assertSame($target->id, $res->json('user.id'));
        $this->assertSame([$group->id], array_column($res->json('user.role_groups'), 'id'));
        $this->assertSame([$directRole->id], array_column($res->json('user.direct_roles'), 'id'));
    }

    public function test_assign_to_user_only_touches_the_keys_that_are_present(): void
    {
        $fn = $this->makeFunction();
        $role = $this->makeRole($fn, ['view' => true]);
        $group = $this->makeGroup();
        $other = $this->makeGroup();
        $target = $this->makeUser('visitor');
        $target->roleGroups()->attach($group->id);
        $target->directRoles()->attach($role->id);

        // Chỉ group_ids → role trực tiếp giữ nguyên.
        $this->postJson("/api/admin/users/{$target->id}/assign", ['group_ids' => [$other->id]])->assertOk();
        $this->assertSame([$other->id], $target->roleGroups()->pluck('role_groups.id')->all());
        $this->assertSame([$role->id], $target->directRoles()->pluck('roles.id')->all());

        // Chỉ role_ids → nhóm giữ nguyên.
        $this->postJson("/api/admin/users/{$target->id}/assign", ['role_ids' => []])->assertOk();
        $this->assertSame([$other->id], $target->roleGroups()->pluck('role_groups.id')->all());
        $this->assertSame(0, $target->directRoles()->count());

        // Không có khóa nào → không đổi gì nhưng vẫn 200.
        $this->postJson("/api/admin/users/{$target->id}/assign", [])->assertOk();
        $this->assertSame([$other->id], $target->roleGroups()->pluck('role_groups.id')->all());
    }

    public function test_assign_to_user_with_empty_arrays_clears_both(): void
    {
        $role = $this->makeRole($this->makeFunction(), ['view' => true]);
        $group = $this->makeGroup();
        $target = $this->makeUser('visitor');
        $target->roleGroups()->attach($group->id);
        $target->directRoles()->attach($role->id);

        $res = $this->postJson("/api/admin/users/{$target->id}/assign", ['group_ids' => [], 'role_ids' => []])->assertOk();

        $this->assertSame(0, $target->roleGroups()->count());
        $this->assertSame(0, $target->directRoles()->count());
        $this->assertSame([], $res->json('effective_permissions'));
        $this->assertSame([], $res->json('user.role_groups'));
        $this->assertSame([], $res->json('user.direct_roles'));
    }

    public function test_assign_to_user_does_not_affect_other_users(): void
    {
        $group = $this->makeGroup();
        $bystander = $this->makeUser('visitor');
        $bystander->roleGroups()->attach($group->id);
        $target = $this->makeUser('visitor');

        $this->postJson("/api/admin/users/{$target->id}/assign", ['group_ids' => []])->assertOk();

        $this->assertSame(1, $bystander->roleGroups()->count());
    }

    public function test_assign_to_unknown_user_is_404(): void
    {
        $this->postJson('/api/admin/users/khong-ton-tai/assign', ['group_ids' => []])
            ->assertStatus(404)
            ->assertJsonStructure(['message', 'detail']);
    }

    public function test_assign_to_admin_user_reports_full_bypass_matrix(): void
    {
        $target = $this->makeUser('admin');

        $res = $this->postJson("/api/admin/users/{$target->id}/assign", ['group_ids' => []])->assertOk();

        $this->assertCount(FunctionModel::count(), $res->json('effective_permissions'));
    }
}
