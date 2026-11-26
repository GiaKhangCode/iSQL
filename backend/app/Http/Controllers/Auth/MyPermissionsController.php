<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Access\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/auth/permissions: quyền hiệu lực, các vai trò đang giữ (mỗi vai trò là một khu vực giao diện, kèm quyền
 * của nó) và quyền gán riêng của người đang đăng nhập.
 */
class MyPermissionsController extends Controller
{
    public function __invoke(Request $request, AccessControlService $access): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'permissions'  => $access->permissionsOf($user),
            'roles'        => $access->rolesOf($user),
            'directPermissions' => $access->directPermissionsOf($user),
        ]);
    }
}
