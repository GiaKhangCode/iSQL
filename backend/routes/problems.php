<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\ProblemController;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Trending and Solution / Stage Validation (defined before parameterised {id} routes)
    Route::get('/trending', [ProblemController::class, 'trending']);
    Route::post('/validate', [ProblemController::class, 'validateSolution'])->middleware('capability:problem.create,library.manage');
    Route::post('/validate-stage', [ProblemController::class, 'validateStage'])->middleware('capability:problem.create,library.manage');

    // List & Detail
    Route::get('/', [ProblemController::class, 'index']);
    Route::get('/{id}', [ProblemController::class, 'show']);

    // Soạn bài toán: quyền problem.create; phạm vi (của mình / được chia sẻ quyền sửa / siêu quản trị) do ProblemService quyết định.
    Route::middleware('capability:problem.create')->group(function () {
        Route::post('/', [ProblemController::class, 'store']);
        Route::put('/{id}', [ProblemController::class, 'update']);
        Route::delete('/{id}', [ProblemController::class, 'destroy']);
    });

    // Running & Submissions
    Route::post('/{id}/run', [ProblemController::class, 'runQuery']);
    Route::post('/{id}/submit', [ProblemController::class, 'submit']);
    Route::post('/{id}/workspace/run', [ProblemController::class, 'runWorkspace']);
    Route::post('/{id}/workspace/database', [ProblemController::class, 'questionDatabase']);
    Route::post('/{id}/workspace/expected-output', [ProblemController::class, 'expectedOutput']);
    Route::post('/{id}/workspace/current', [ProblemController::class, 'studentDatabase']);
    Route::post('/{id}/workspace/reset', [ProblemController::class, 'resetStudentDatabase']);
    Route::post('/{id}/workspace/submit', [ProblemController::class, 'submitWorkspace']);
    Route::post('/{id}/workspace/questions/submit', [ProblemController::class, 'submitWorkspaceQuestion']);
});
