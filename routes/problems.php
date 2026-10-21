<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\ProblemController;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/', [ProblemController::class, 'index'])->middleware('permission:PRACTICE_MGMT,view');
    Route::get('/{id}', [ProblemController::class, 'show'])->middleware('permission:PRACTICE_MGMT,view');
    Route::post('/{id}/run', [ProblemController::class, 'runQuery'])->middleware('permission:PRACTICE_MGMT,view');
    Route::post('/{id}/submit', [ProblemController::class, 'submit'])->middleware('permission:PRACTICE_MGMT,add');

    Route::post('/', [ProblemController::class, 'store'])->middleware('permission:PROBLEM_MGMT,add');
    Route::put('/{id}', [ProblemController::class, 'update'])->middleware('permission:PROBLEM_MGMT,edit');
    Route::delete('/{id}', [ProblemController::class, 'destroy'])->middleware('permission:PROBLEM_MGMT,delete');
});
