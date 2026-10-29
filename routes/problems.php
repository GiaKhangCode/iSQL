<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\ProblemController;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Trending and Solution Validation (defined before parameterised {id} routes)
    Route::get('/trending', [ProblemController::class, 'trending']);
    Route::post('/validate', [ProblemController::class, 'validateSolution']);

    // List & Detail
    Route::get('/', [ProblemController::class, 'index']);
    Route::get('/{id}', [ProblemController::class, 'show']);

    // Teacher / Admin CRUD
    Route::post('/', [ProblemController::class, 'store']);
    Route::put('/{id}', [ProblemController::class, 'update']);
    Route::delete('/{id}', [ProblemController::class, 'destroy']);

    // Running & Submissions
    Route::post('/{id}/run', [ProblemController::class, 'runQuery']);
    Route::post('/{id}/submit', [ProblemController::class, 'submit']);
    Route::post('/{id}/workspace/run', [ProblemController::class, 'runWorkspace']);
    Route::post('/{id}/workspace/submit', [ProblemController::class, 'submitWorkspace']);
});
