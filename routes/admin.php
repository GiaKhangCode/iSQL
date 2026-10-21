<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ClassController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\ClassRosterController;
use App\Http\Controllers\PermissionController;

Route::middleware(['auth:sanctum', 'active', 'role:admin'])->group(function () {
    // Permission & Role Management
    Route::get('/my-permissions', [PermissionController::class, 'getPermission']);
    Route::get('/functions', [PermissionController::class, 'getFunctions']);
    Route::get('/roles', [PermissionController::class, 'getRoles']);
    Route::post('/roles', [PermissionController::class, 'storeRole']);
    Route::delete('/roles/{id}', [PermissionController::class, 'deleteRole']);
    Route::get('/role-groups', [PermissionController::class, 'getRoleGroups']);
    Route::post('/role-groups', [PermissionController::class, 'storeRoleGroup']);
    Route::delete('/role-groups/{id}', [PermissionController::class, 'deleteRoleGroup']);
    Route::post('/role-groups/{id}/assign-roles', [PermissionController::class, 'assignRolesToGroup']);
    Route::post('/users/{id}/assign', [PermissionController::class, 'assignToUser']);

    // User Management
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/import', [UserController::class, 'importCsv']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}', [UserController::class, 'update']);
    Route::put('/users/{id}/status', [UserController::class, 'updateStatus']);
    Route::patch('/users/{id}/status', [UserController::class, 'updateStatus']);
    Route::post('/users/{id}/approve', [UserController::class, 'approve']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetStudentPassword']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::get('/overview', [OverviewController::class, 'index']);
    Route::get('/classes', [ClassController::class, 'index']);
    Route::post('/classes', [ClassController::class, 'store']);
    Route::get('/classes/{id}', [ClassController::class, 'show']);
    Route::put('/classes/{id}', [ClassController::class, 'update']);
    Route::patch('/classes/{id}', [ClassController::class, 'update']);
    Route::delete('/classes/{id}', [ClassController::class, 'destroy']);
    Route::put('/classes/{id}/instructor', [ClassController::class, 'assignInstructor']);
    Route::put('/classes/{id}/instructors', [ClassController::class, 'syncInstructors']);
    Route::delete('/classes/{id}/instructors/{assignmentType}', [ClassController::class, 'removeInstructorAssignment'])
        ->whereIn('assignmentType', ['theory', 'practical']);

    Route::get('/classes/{id}/students', [ClassRosterController::class, 'index']);
    Route::post('/classes/{id}/students', [ClassRosterController::class, 'add']);
    Route::put('/classes/{id}/students', [ClassRosterController::class, 'replace']);
    Route::delete('/classes/{id}/students/{studentId}', [ClassRosterController::class, 'remove']);

    Route::apiResource('courses', CourseController::class);
    Route::apiResource('terms', AcademicTermController::class);
});
