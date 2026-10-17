<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\ProblemController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/', [ProblemController::class, 'index']);
    Route::get('/{id}', [ProblemController::class, 'show']);
    Route::post('/{id}/run', [ProblemController::class, 'runQuery']);
    Route::post('/{id}/submit', [ProblemController::class, 'submit']);

    Route::middleware('role:instructor,admin')->group(function () {
        Route::post('/', [ProblemController::class, 'store']);
        Route::put('/{id}', [ProblemController::class, 'update']);
        Route::delete('/{id}', [ProblemController::class, 'destroy']);
    });
});
