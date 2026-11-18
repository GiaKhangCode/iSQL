<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['detail' => 'Unauthorized'], 401);
        }

        // roles can be passed as 'admin' or 'instructor,admin'
        $allowedRoles = [];
        foreach ($roles as $role) {
            foreach (explode(',', $role) as $r) {
                $role = trim($r);
                $allowedRoles[] = $role;

                // Existing feature routes use `instructor` as a generic
                // faculty permission. Keep both split faculty roles compatible.
                if ($role === User::LEGACY_INSTRUCTOR_ROLE) {
                    $allowedRoles = array_merge($allowedRoles, User::INSTRUCTOR_ROLES);
                }
            }
        }

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json(['detail' => 'Permission denied'], 403);
        }

        return $next($request);
    }
}
