<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PermissionController;

Route::prefix('auth')->group(base_path('routes/auth.php'));

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('admin')->group(function () {
        // Lấy quyền cá nhân của người dùng đang đăng nhập
        Route::get('/my-permissions', [PermissionController::class, 'getPermission']);

        // Tab 1: Functions (Chức năng - Gán cứng hệ thống, chỉ xem)
        Route::get('/functions', [PermissionController::class, 'getFunctions']);

        // Tab 2: Roles (Quyền chi tiết)
        Route::get('/roles', [PermissionController::class, 'getRoles']);
        Route::post('/roles', [PermissionController::class, 'storeRole']);
        Route::delete('/roles/{id}', [PermissionController::class, 'deleteRole']);

        // Tab 3: Role Groups (Nhóm quyền)
        Route::get('/role-groups', [PermissionController::class, 'getRoleGroups']);
        Route::post('/role-groups', [PermissionController::class, 'storeRoleGroup']);
        Route::delete('/role-groups/{id}', [PermissionController::class, 'deleteRoleGroup']);

        // Tab 4: Gán quyền vào nhóm
        Route::post('/role-groups/{id}/assign-roles', [PermissionController::class, 'assignRolesToGroup']);

        // Tab 5: Phân quyền tài khoản
        Route::get('/users', [PermissionController::class, 'getUsers']);
        Route::post('/users/{id}/assign', [PermissionController::class, 'assignToUser']);
    });
});