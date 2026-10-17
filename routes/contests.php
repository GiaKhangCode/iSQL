<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Contest\ExamController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/{id}/setup-db', [ExamController::class, 'setupDb']);
    Route::post('/{id}/run-ddl', [ExamController::class, 'runDdl']);
    Route::post('/{id}/seed-dml', [ExamController::class, 'seedDml']);
    Route::get('/{id}/inspect-schema', [ExamController::class, 'inspectSchema']);
    Route::post('/{id}/run-query', [ExamController::class, 'runQuery']);
    Route::post('/{id}/submit-exam', [ExamController::class, 'submitExam']);
});
