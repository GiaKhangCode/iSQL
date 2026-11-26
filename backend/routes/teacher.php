<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\ClassController;
use App\Http\Controllers\Teacher\AssignmentController;
use App\Http\Controllers\Teacher\CatalogController;
use App\Http\Controllers\ClassRosterController;

// Lớp học và danh sách sinh viên.
// ĐỌC: không cần quyền riêng; phạm vi (mọi lớp hay chỉ lớp mình dạy) do ClassService/ClassRosterService quyết định.
// GHI (tạo/sửa/xóa lớp, phân giảng viên, thêm/bớt sinh viên): quyền class.manage.
// Đặt lại mật khẩu sinh viên: quyền student.reset_password, trong lớp mình dạy.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::middleware('capability:class.manage,class.teach,assignment.create,examination.manage,results.view_all')->group(function () {
        Route::get('/courses', [CatalogController::class, 'courses']);
        Route::get('/terms', [CatalogController::class, 'terms']);
    });

    Route::get('/classes', [ClassController::class, 'index']);
    Route::get('/classes/{id}', [ClassController::class, 'show'])->where('id', '^(?!students$)[^/]+$');
    Route::get('/classes/{id}/members', [ClassRosterController::class, 'getMembers']);
    Route::get('/classes/{id}/students', [ClassRosterController::class, 'index']);

    Route::post('/classes/{id}/students/{studentId}/reset-password', [ClassRosterController::class, 'resetStudentPassword'])
        ->middleware('capability:student.reset_password');

    Route::middleware('capability:class.manage')->group(function () {
        Route::post('/classes', [ClassController::class, 'store']);
        Route::get('/classes/students', [ClassRosterController::class, 'getAllStudents']);
        Route::put('/classes/{id}', [ClassController::class, 'update']);
        Route::patch('/classes/{id}', [ClassController::class, 'update']);
        Route::delete('/classes/{id}', [ClassController::class, 'destroy']);
        Route::put('/classes/{id}/instructor', [ClassController::class, 'assignInstructor']);
        Route::post('/classes/{id}/instructor', [ClassController::class, 'assignInstructor']);
        Route::put('/classes/{id}/allocate-teacher', [ClassController::class, 'assignInstructor']);
        Route::post('/classes/{id}/allocate-teacher', [ClassController::class, 'assignInstructor']);

        Route::post('/classes/{id}/members', [ClassRosterController::class, 'addMember']);
        Route::delete('/classes/{id}/members/{studentId}', [ClassRosterController::class, 'removeMember']);
        Route::post('/classes/{id}/members/import', [ClassRosterController::class, 'importMembers']);

        Route::post('/classes/{id}/students', [ClassRosterController::class, 'add']);
        Route::put('/classes/{id}/students', [ClassRosterController::class, 'replace']);
        Route::delete('/classes/{id}/students/{studentId}', [ClassRosterController::class, 'remove']);
    });
});

// Thư viện cơ sở dữ liệu mẫu: quyền library.manage (xem thêm cho người soạn bài toán: problem.create).
// Phạm vi (của mình / mẫu hệ thống / siêu quản trị) do DatabaseLibraryService quyết định.
Route::middleware(['auth:sanctum', 'active'])->prefix('database-library')->group(function () {
    $controller = \App\Http\Controllers\Teacher\DatabaseLibraryController::class;
    Route::middleware('capability:library.manage,problem.create')->group(function () use ($controller) {
        Route::get('/', [$controller, 'index']);
        Route::get('/{id}', [$controller, 'show']);
    });
    Route::middleware('capability:library.manage')->group(function () use ($controller) {
        Route::post('/', [$controller, 'store']);
        Route::post('/test-run', [$controller, 'testRun']);
        Route::put('/{id}', [$controller, 'update']);
        Route::delete('/{id}', [$controller, 'destroy']);
    });
});
