<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ClassController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\ClassRosterController;
use App\Http\Controllers\Admin\ExerciseTagController;
use App\Http\Controllers\Admin\SandboxController;

// Khu vực quản trị: quyền theo từng nhóm chức năng (capability), không so sánh loại tài khoản hay tên vai trò.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Quản lý tài khoản: quyền user.manage.
    Route::middleware('capability:user.manage')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/import', [UserController::class, 'importCsv']);
    Route::get('/users/role-options', [UserController::class, 'roleOptions']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}', [UserController::class, 'update']);
    Route::put('/users/{id}/status', [UserController::class, 'updateStatus']);
    Route::patch('/users/{id}/status', [UserController::class, 'updateStatus']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetStudentPassword']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    });

    // Trang Tổng quan: tình trạng dịch vụ, việc cần xử lý, ca thi sắp tới, nhật ký gần đây.
    Route::prefix('overview')->middleware('capability:system.overview')->group(function () {
        Route::get('/health', [OverviewController::class, 'health']);
        Route::get('/attention', [OverviewController::class, 'attention']);
        Route::get('/exams', [OverviewController::class, 'exams']);
        Route::get('/activities', [OverviewController::class, 'activities']);
    });

    // Dọn schema bài làm trên SQL Server (kết thúc học phần): quyền riêng sandbox.cleanup.
    Route::prefix('sandbox')->middleware('capability:sandbox.cleanup')->group(function () {
        Route::get('/', [SandboxController::class, 'index']);
        Route::post('/cleanup', [SandboxController::class, 'cleanup']);
    });

    // Lớp, danh mục môn học và học kỳ: quyền class.manage.
    Route::middleware('capability:class.manage')->group(function () {
    Route::get('/classes', [ClassController::class, 'index']);
    Route::post('/classes', [ClassController::class, 'store']);
    Route::get('/instructors', [ClassController::class, 'instructors']);
    Route::get('/classes/students', [ClassRosterController::class, 'getAllStudents']);
    Route::get('/classes/{id}', [ClassController::class, 'show']);
    Route::put('/classes/{id}', [ClassController::class, 'update']);
    Route::patch('/classes/{id}', [ClassController::class, 'update']);
    Route::delete('/classes/{id}', [ClassController::class, 'destroy']);
    Route::put('/classes/{id}/instructor', [ClassController::class, 'assignInstructor']);
    Route::post('/classes/{id}/instructor', [ClassController::class, 'assignInstructor']);
    Route::put('/classes/{id}/allocate-teacher', [ClassController::class, 'assignInstructor']);
    Route::post('/classes/{id}/allocate-teacher', [ClassController::class, 'assignInstructor']);

    Route::get('/classes/{id}/members', [ClassRosterController::class, 'getMembers']);
    Route::post('/classes/{id}/members', [ClassRosterController::class, 'addMember']);
    Route::delete('/classes/{id}/members/{studentId}', [ClassRosterController::class, 'removeMember']);
    Route::post('/classes/{id}/members/import', [ClassRosterController::class, 'importMembers']);

    Route::get('/classes/{id}/students', [ClassRosterController::class, 'index']);
    Route::post('/classes/{id}/students', [ClassRosterController::class, 'add']);
    Route::put('/classes/{id}/students', [ClassRosterController::class, 'replace']);
    Route::delete('/classes/{id}/students/{studentId}', [ClassRosterController::class, 'remove']);

    Route::apiResource('courses', CourseController::class);
    Route::apiResource('terms', AcademicTermController::class);
    });

    // Thẻ câu hỏi: quyền riêng tag.manage (siêu quản trị không tự có; mặc định giao cho Trưởng bộ môn).
    Route::middleware('capability:tag.manage')->group(function () {
        Route::get('/exercise-tags', [ExerciseTagController::class, 'index']);
        Route::post('/exercise-tags', [ExerciseTagController::class, 'store']);
        Route::put('/exercise-tags/{id}', [ExerciseTagController::class, 'update']);
        Route::delete('/exercise-tags/{id}', [ExerciseTagController::class, 'destroy']);
    });
});

// Trang Vai trò: gắn theo mã quyền access.manage (không phụ thuộc tên vai trò).
Route::middleware(['auth:sanctum', 'active', 'capability:access.manage'])->prefix('access')->group(function () {
    Route::get('/', [AccessController::class, 'catalog']);
    Route::post('/roles', [AccessController::class, 'storeRole']);
    Route::put('/roles/{id}', [AccessController::class, 'updateRole']);
    Route::patch('/roles/{id}', [AccessController::class, 'updateRole']);
    Route::get('/users/{id}', [AccessController::class, 'showUser']);
    Route::put('/users/{id}', [AccessController::class, 'assignUser']);
    Route::put('/users/{id}/student-code', [AccessController::class, 'setStudentCode']);
});
