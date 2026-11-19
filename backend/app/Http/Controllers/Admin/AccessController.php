<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignUserAccessRequest;
use App\Http\Requests\Admin\SaveAccessRoleRequest;
use App\Http\Requests\Admin\SetStudentCodeRequest;
use App\Services\Access\AccessRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trang Vai trò: danh mục quyền, vai trò, gán vai trò cho người dùng.
 * Route gắn middleware capability:access.manage.
 */
class AccessController extends Controller
{
    public function __construct(private readonly AccessRoleService $service)
    {
    }

    public function catalog(): JsonResponse
    {
        return response()->json($this->service->catalog());
    }

    public function storeRole(SaveAccessRoleRequest $request): JsonResponse
    {
        return response()->json($this->service->createRole($request->validated(), $request->user()), 201);
    }

    public function updateRole(SaveAccessRoleRequest $request, string $id): JsonResponse
    {
        return response()->json($this->service->updateRole($id, $request->validated(), $request->user()));
    }

    public function showUser(string $id): JsonResponse
    {
        return response()->json($this->service->userAccess($id));
    }

    public function assignUser(AssignUserAccessRequest $request, string $id): JsonResponse
    {
        return response()->json($this->service->assignRoles(
            $id,
            $request->validated('role_ids'),
            $request->directPermissions(),
            $request->user()
        ));
    }

    public function setStudentCode(SetStudentCodeRequest $request, string $id): JsonResponse
    {
        return response()->json($this->service->setStudentCode($id, $request->validated('student_code'), $request->user()));
    }
}
