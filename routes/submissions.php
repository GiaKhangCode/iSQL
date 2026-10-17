<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SubmissionController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/', [SubmissionController::class, 'index']);
    Route::get('/{id}', [SubmissionController::class, 'show']);
    Route::put('/{id}/review', [SubmissionController::class, 'review'])->middleware('role:instructor,admin');
});
