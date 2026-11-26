<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Student\DashboardController;
use App\Http\Controllers\Student\AssignmentController;
use App\Http\Controllers\Student\PreferenceController;
use App\Http\Controllers\Student\PersonalDbController;
use App\Http\Controllers\Student\ClassController as StudentClassController;
use App\Http\Controllers\Student\ActivityAttemptController;
use App\Http\Controllers\SubmissionController;

// Khu vực sinh viên: quyền practice.solve. Phạm vi (lớp, bài được giao) do service quyết định.
Route::middleware(['auth:sanctum', 'active', 'capability:practice.solve'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/profile', [SubmissionController::class, 'studentProfile']);
    Route::get('/preferences', [PreferenceController::class, 'index']);
    Route::post('/preferences/favorites/{id}', [PreferenceController::class, 'toggleFavorite']);
    Route::get('/personal-db/status', [PersonalDbController::class, 'status']);
    Route::post('/personal-db/run', [PersonalDbController::class, 'runQuery']);

});

// Tab Bài tập: assignment.do. Tài khoản thi tạm (exam.session) vẫn làm bài trong ca thi.
Route::middleware(['auth:sanctum', 'active', 'capability:assignment.do,exam.session'])->group(function () {
    Route::get('/assignments', [AssignmentController::class, 'index']);

    // Activity Attempts (Exams / Assignments)
    Route::get('/activity-attempts/{activityId}', [ActivityAttemptController::class, 'show']);
    Route::post('/activity-attempts/{activityId}/start', [ActivityAttemptController::class, 'start']);
    Route::post('/activity-attempts/{activityId}/{action}', [ActivityAttemptController::class, 'updateAction']);
});

// Lớp mình đang học: chỉ trả các lớp có ghi danh của chính người dùng.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/classes', [StudentClassController::class, 'index']);
});
