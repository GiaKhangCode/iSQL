<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SubmissionController;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/', [SubmissionController::class, 'index']);
    Route::get('/activity/{activityId}/students/{studentId}/problems/{problemId}', [SubmissionController::class, 'studentWork']);
    Route::get('/{id}', [SubmissionController::class, 'show']);
    Route::put('/{id}/review', [SubmissionController::class, 'review']);
    Route::post('/{id}/inspect', [SubmissionController::class, 'inspect']);
    Route::post('/{id}/appeal', [SubmissionController::class, 'appeal']);
});
