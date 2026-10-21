<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request by checking the RBAC permission matrix.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $functionCode  (e.g., 'CLASS_MGMT', 'PROBLEM_MGMT', 'USER_MGMT')
     * @param  string  $action        (e.g., 'view', 'add', 'edit', 'delete', 'download')
     */
    public function handle(Request $request, Closure $next, string $functionCode, string $action = 'view'): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['detail' => 'Unauthorized'], 401);
        }

        if (!$user->canDo($functionCode, $action)) {
            return response()->json([
                'detail' => 'Permission denied: Bạn không có quyền thực hiện thao tác này.'
            ], 403);
        }

        return $next($request);
    }
}
