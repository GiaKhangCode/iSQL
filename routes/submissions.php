<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SubmissionController;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/', [SubmissionController::class, 'index']);
    Route::get('/{id}', [SubmissionController::class, 'show']);
    Route::put('/{id}/review', [SubmissionController::class, 'review'])->middleware('permission:ASSIGNMENT_MGMT,edit');
});
