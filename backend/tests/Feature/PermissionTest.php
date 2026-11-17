<?php

namespace Tests\Feature;

use App\Models\FunctionModel;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name'            => 'Admin Test',
            'email'           => 'admin_test_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'admin',
            'status'          => 'Active',
            'initials'        => 'AT',
        ]);

        $this->adminToken = $this->adminUser->createToken('admin-token')->plainTextToken;
    }

    public function test_unauthenticated_user_cannot_access_admin_routes(): void
    {
        $response = $this->getJson('/api/admin/functions');
        $response->assertStatus(401);
    }

    public function test_get_my_permissions(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                         ->getJson('/api/admin/my-permissions');

        $response->assertStatus(200)
                 ->assertJsonStructure(['permissions', 'permission']);
    }

    public function test_get_functions(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                         ->getJson('/api/admin/functions');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_crud_roles(): void
    {
        $func = FunctionModel::first();
        if (!$func) {
            $func = FunctionModel::create([
                'name' => 'Test Function',
                'code' => 'TEST_FUNC_' . uniqid(),
            ]);
        }

        // 1. Create Role
        $createResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                               ->postJson('/api/admin/roles', [
                                   'function_id' => $func->id,
                                   'name'        => 'Custom Test Role',
                                   'view'        => true,
                                   'add'         => true,
                                   'edit'        => false,
                                   'delete'      => false,
                                   'download'    => true,
                               ]);

        $createResponse->assertStatus(201)
                       ->assertJson([
                           'name'        => 'Custom Test Role',
                           'function_id' => $func->id,
                       ]);

        $roleId = $createResponse->json('id');

        // 2. Get Roles
        $getResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                            ->getJson('/api/admin/roles');
        $getResponse->assertStatus(200);

        // 3. Delete Role
        $deleteResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                               ->deleteJson("/api/admin/roles/{$roleId}");
        $deleteResponse->assertStatus(200);
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_crud_role_groups(): void
    {
        // 1. Create Role Group
        $createResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                               ->postJson('/api/admin/role-groups', [
                                   'name'        => 'Trợ giảng (TA) ' . uniqid(),
                                   'description' => 'Nhóm hỗ trợ giảng viên chấm bài',
                               ]);

        $createResponse->assertStatus(201);
        $groupId = $createResponse->json('id');

        // 2. Get Role Groups
        $getResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                            ->getJson('/api/admin/role-groups');
        $getResponse->assertStatus(200);

        // 3. Delete Role Group
        $deleteResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                               ->deleteJson("/api/admin/role-groups/{$groupId}");
        $deleteResponse->assertStatus(200);
        $this->assertDatabaseMissing('role_groups', ['id' => $groupId]);
    }

    public function test_assign_roles_to_group(): void
    {
        $group = RoleGroup::create([
            'name'        => 'Test Assign Group ' . uniqid(),
            'description' => 'Group for testing assignment',
        ]);

        $role = Role::first();
        if (!$role) {
            $func = FunctionModel::firstOrCreate(['name' => 'Fn Test', 'code' => 'FN_TEST']);
            $role = Role::create([
                'function_id' => $func->id,
                'name'        => 'Role Test',
                'view'        => 1,
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                         ->postJson("/api/admin/role-groups/{$group->id}/assign-roles", [
                             'role_ids' => [$role->id],
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Cập nhật danh sách quyền cho nhóm thành công!');

        $this->assertDatabaseHas('role_group_assign_role', [
            'role_group_id' => $group->id,
            'role_id'       => $role->id,
        ]);
    }

    public function test_get_users_and_assign_permissions_to_user(): void
    {
        $targetUser = User::create([
            'name'            => 'Target Student',
            'email'           => 'target_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('secret'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'TS',
        ]);

        // 1. Get Users
        $getResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                            ->getJson('/api/admin/users');
        $getResponse->assertStatus(200);

        // 2. Assign Role Group to user
        $group = RoleGroup::firstOrCreate([
            'name' => 'Demo Group',
        ], [
            'description' => 'Demo group description',
        ]);

        $assignResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                               ->postJson("/api/admin/users/{$targetUser->id}/assign", [
                                   'group_ids' => [$group->id],
                               ]);

        $assignResponse->assertStatus(200)
                       ->assertJsonStructure(['message', 'effective_permissions', 'user']);

        $this->assertDatabaseHas('account_assign_role_group', [
            'account_id'    => $targetUser->id,
            'role_group_id' => $group->id,
        ]);
    }

    public function test_student_registration_auto_assigns_student_role_group(): void
    {
        $payload = [
            'name'     => 'Auto Student',
            'email'    => 'student_' . uniqid() . '@uit.edu.vn',
            'password' => 'SecurePass123!',
        ];

        $response = $this->postJson('/api/auth/register', $payload);
        $response->assertStatus(201);

        $userId = $response->json('user.id');
        $user = User::findOrFail($userId);

        $this->assertEquals('Active', $user->status);
        $this->assertEquals('student', $user->role);
        $this->assertTrue($user->roleGroups()->where('name', 'like', '%Sinh viên%')->exists());
        $this->assertTrue($user->canDo('CLASS_MGMT', 'view'));
        $this->assertFalse($user->canDo('CLASS_MGMT', 'add'));
    }

    public function test_lecturer_registration_is_pending_and_approval_assigns_role_group(): void
    {
        $payload = [
            'name'       => 'Pending Lecturer',
            'email'      => 'lecturer_' . uniqid() . '@uit.edu.vn',
            'password'   => 'SecurePass123!',
            'department' => 'Khoa Khoa học Máy tính',
        ];

        // 1. Register lecturer -> status is Pending
        $response = $this->postJson('/api/auth/register-lecturer', $payload);
        $response->assertStatus(201);

        $userId = $response->json('user.id');
        $user = User::findOrFail($userId);
        $this->assertEquals('Pending', $user->status);
        $this->assertFalse($user->roleGroups()->where('name', 'like', '%Giảng viên%')->exists());

        // 2. Admin approves lecturer
        $approveResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                                ->postJson("/api/admin/users/{$userId}/approve");
        $approveResponse->assertStatus(200);

        $user->refresh();
        $this->assertEquals('Active', $user->status);
        $this->assertTrue($user->roleGroups()->where('name', 'like', '%Giảng viên%')->exists());
        $this->assertTrue($user->canDo('CLASS_MGMT', 'add'));
        $this->assertTrue($user->canDo('PROBLEM_MGMT', 'add'));
    }

    public function test_admin_has_superadmin_bypass(): void
    {
        // Admin automatically can do any action on any function
        $this->assertTrue($this->adminUser->canDo('NON_EXISTENT_FUNCTION', 'delete'));
        $this->assertTrue($this->adminUser->canDo('CLASS_MGMT', 'delete'));
        $this->assertTrue($this->adminUser->canDo('USER_MGMT', 'download'));
    }

    public function test_permission_middleware_denies_student_from_creating_class(): void
    {
        $student = User::create([
            'name'            => 'Restricted Student',
            'email'           => 'restricted_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'RS',
        ]);
        $studentGroup = RoleGroup::where('name', 'like', '%Sinh viên%')->first();
        if ($studentGroup) {
            $student->roleGroups()->sync([$studentGroup->id]);
        }

        $token = $student->createToken('student-token')->plainTextToken;

        // Try to create a class on teacher route guarded by permission:CLASS_MGMT,add
        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->postJson('/api/teacher/classes', [
                             'course' => 'Database Design',
                             'term'   => 'HK1 2026',
                         ]);

        $response->assertStatus(403)
                 ->assertJsonPath('detail', 'Permission denied: Bạn không có quyền thực hiện thao tác này.');
    }

    public function test_user_can_fetch_own_permissions_via_auth_route(): void
    {
        $student = User::create([
            'name'            => 'Check Perm Student',
            'email'           => 'perm_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password123'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'CP',
        ]);
        $studentGroup = RoleGroup::where('name', 'like', '%Sinh viên%')->first();
        if ($studentGroup) {
            $student->roleGroups()->sync([$studentGroup->id]);
        }

        $token = $student->createToken('student-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->getJson('/api/auth/my-permissions');

        $response->assertStatus(200)
                 ->assertJsonStructure(['permissions', 'permission']);
    }
}

