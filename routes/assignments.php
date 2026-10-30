<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\AssignmentController;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/audience-count', [AssignmentController::class, 'audienceCount']);
    Route::get('/', [AssignmentController::class, 'index']);
    Route::get('/{id}', [AssignmentController::class, 'show']);
    Route::post('/', [AssignmentController::class, 'store']);
    Route::put('/{id}', [AssignmentController::class, 'update']);
    Route::delete('/{id}', [AssignmentController::class, 'destroy']);
});
