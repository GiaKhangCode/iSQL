<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Access\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/auth/permissions: quyền hiệu lực và khu vực giao diện của người đang đăng nhập. */
class MyPermissionsController extends Controller
{
    public function __invoke(Request $request, AccessControlService $access): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'permissions'  => $access->permissionsOf($user),
            'areas'        => $access->areasOf($user),
            'isSuperAdmin' => $access->isSuperAdmin($user),
        ]);
    }
}
