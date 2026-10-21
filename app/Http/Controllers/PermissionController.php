<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PermissionService;
use App\Models\FunctionModel;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;

class PermissionController extends Controller
{
    protected PermissionService $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Lấy ma trận quyền của người dùng hiện tại đang đăng nhập
     */
    public function getPermission(Request $request)
    {
        $user = $request->user();
        $permission = $this->permissionService->getUserPermission($user->id);

        return response()->json([
            'permissions' => $permission,
            'permission'  => $permission,
        ]);
    }

    // ──────────────── TAB 1: FUNCTIONS ────────────────
    public function getFunctions()
    {
        return response()->json(FunctionModel::orderBy('id')->get());
    }

    public function storeFunction(Request $request)
    {
        return response()->json(['message' => 'Danh mục chức năng hệ thống được gán cứng theo từng module/tab, không cho phép thêm mới.'], 403);
    }

    public function deleteFunction($id)
    {
        return response()->json(['message' => 'Danh mục chức năng hệ thống được gán cứng theo từng module/tab, không cho phép xóa.'], 403);
    }

    // ──────────────── TAB 2: ROLES (QUYỀN CHI TIẾT) ────────────────
    public function getRoles()
    {
        return response()->json(Role::with('functionModel')->orderBy('id')->get());
    }

    public function storeRole(Request $request)
    {
        $data = $request->validate([
            'function_id' => 'required|exists:functions,id',
            'name'        => 'nullable|string|max:255',
            'view'        => 'boolean',
            'add'         => 'boolean',
            'edit'        => 'boolean',
            'delete'      => 'boolean',
            'download'    => 'boolean',
        ]);
        $role = Role::create($data);
        return response()->json($role->load('functionModel'), 201);
    }

    public function deleteRole($id)
    {
        $role = Role::findOrFail($id);
        $role->delete();
        return response()->json(['message' => 'Đã xóa quyền chi tiết thành công!']);
    }

    // ──────────────── TAB 3: ROLE GROUPS (NHÓM QUYỀN) ────────────────
    public function getRoleGroups()
    {
        return response()->json(RoleGroup::with('roles')->orderBy('id')->get());
    }

    public function storeRoleGroup(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);
        $group = RoleGroup::create($data);
        return response()->json($group, 201);
    }

    public function deleteRoleGroup($id)
    {
        $group = RoleGroup::findOrFail($id);
        $group->delete();
        return response()->json(['message' => 'Đã xóa nhóm quyền thành công!']);
    }

    // ──────────────── TAB 4: GÁN QUYỀN VÀO NHÓM ────────────────
    public function assignRolesToGroup(Request $request, $groupId)
    {
        $group = RoleGroup::findOrFail($groupId);
        $roleIds = $request->input('role_ids', []);
        $group->roles()->sync($roleIds);
        return response()->json([
            'message' => 'Cập nhật danh sách quyền cho nhóm thành công!',
            'group'   => $group->load('roles'),
        ]);
    }

    // ──────────────── TAB 5: PHÂN QUYỀN TÀI KHOẢN ────────────────
    public function getUsers()
    {
        $users = User::with(['roleGroups', 'directRoles'])
            ->select('id', 'name', 'email', 'role', 'status')
            ->orderBy('name')
            ->get();
        return response()->json($users);
    }

    public function assignToUser(Request $request, $userId)
    {
        $user = User::findOrFail($userId);
        if ($request->has('group_ids')) {
            $user->roleGroups()->sync($request->input('group_ids', []));
        }
        if ($request->has('role_ids')) {
            $user->directRoles()->sync($request->input('role_ids', []));
        }
        return response()->json([
            'message'               => 'Cập nhật phân quyền tài khoản thành công!',
            'effective_permissions' => $this->permissionService->getUserPermission($userId),
            'user'                  => $user->load(['roleGroups', 'directRoles']),
        ]);
    }
}