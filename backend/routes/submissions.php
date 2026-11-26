<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SubmissionController;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Tab Bài nộp: lịch sử bài nộp của chính mình.
    Route::get('/', [SubmissionController::class, 'index'])->middleware('capability:submission.view,exam.session');
    Route::get('/activity/{activityId}/students/{studentId}/problems/{problemId}', [SubmissionController::class, 'studentWork']);
    Route::get('/{id}', [SubmissionController::class, 'show']);
    Route::put('/{id}/review', [SubmissionController::class, 'review']);
    Route::post('/{id}/inspect', [SubmissionController::class, 'inspect']);
});
