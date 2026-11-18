<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Student\DashboardController;
use App\Http\Controllers\Student\AssignmentController;
use App\Http\Controllers\Student\PreferenceController;
use App\Http\Controllers\Student\PersonalDbController;
use App\Http\Controllers\Student\ClassController as StudentClassController;
use App\Http\Controllers\Student\ActivityAttemptController;
use App\Http\Controllers\SubmissionController;

Route::middleware(['auth:sanctum', 'active', 'permission:PRACTICE_MGMT,view'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::get('/profile', [SubmissionController::class, 'studentProfile']);
    Route::get('/preferences', [PreferenceController::class, 'index']);
    Route::post('/preferences/favorites/{id}', [PreferenceController::class, 'toggleFavorite']);
    Route::get('/personal-db/status', [PersonalDbController::class, 'status']);
    Route::post('/personal-db/run', [PersonalDbController::class, 'runQuery']);

    // Activity Attempts (Exams / Assignments)
    Route::get('/activity-attempts/{activityId}', [ActivityAttemptController::class, 'show']);
    Route::post('/activity-attempts/{activityId}/start', [ActivityAttemptController::class, 'start']);
    Route::post('/activity-attempts/{activityId}/{action}', [ActivityAttemptController::class, 'updateAction']);
});

Route::middleware(['auth:sanctum', 'active', 'permission:CLASS_MGMT,view'])->group(function () {
    Route::get('/classes', [StudentClassController::class, 'index']);
});
