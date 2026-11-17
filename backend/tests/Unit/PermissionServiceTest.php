<?php

namespace Tests\Unit;

use App\Models\FunctionModel;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_get_user_permission_aggregates_direct_and_group_roles(): void
    {
        $service = new PermissionService();

        // 1. Create a function
        $func = FunctionModel::create([
            'name' => 'Custom Management',
            'code' => 'CUSTOM_MGMT_' . uniqid(),
        ]);

        // 2. Create roles with different permissions
        $role1 = Role::create([
            'function_id' => $func->id,
            'name'        => 'Viewer Role',
            'view'        => 1,
            'add'         => 0,
            'edit'        => 0,
            'delete'      => 0,
            'download'    => 0,
        ]);

        $role2 = Role::create([
            'function_id' => $func->id,
            'name'        => 'Editor Role',
            'view'        => 0,
            'add'         => 1,
            'edit'        => 1,
            'delete'      => 0,
            'download'    => 0,
        ]);

        // 3. Create group with role1
        $group = RoleGroup::create(['name' => 'Test Group ' . uniqid()]);
        $group->roles()->attach($role1->id);

        // 4. Create user, assign group AND direct role2
        $user = User::create([
            'name'            => 'Aggregated User',
            'email'           => 'perm_agg_' . uniqid() . '@uit.edu.vn',
            'hashed_password' => Hash::make('password'),
            'role'            => 'student',
            'status'          => 'Active',
            'initials'        => 'AU',
        ]);

        $user->roleGroups()->attach($group->id);
        $user->directRoles()->attach($role2->id);

        // 5. Test getUserPermission
        $permissions = $service->getUserPermission($user->id);

        $this->assertArrayHasKey($func->code, $permissions);
        $perm = $permissions[$func->code];

        // Should combine: view from role1 (true), add from role2 (true), edit from role2 (true)
        $this->assertTrue($perm['view']);
        $this->assertTrue($perm['add']);
        $this->assertTrue($perm['edit']);
        $this->assertFalse($perm['delete']);
    }
}
