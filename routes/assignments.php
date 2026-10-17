<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\AssignmentController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/', [AssignmentController::class, 'index']);

    Route::middleware('role:instructor,admin')->group(function () {
        Route::post('/', [AssignmentController::class, 'store']);
        Route::put('/{id}', [AssignmentController::class, 'update']);
        Route::delete('/{id}', [AssignmentController::class, 'destroy']);
    });
});
