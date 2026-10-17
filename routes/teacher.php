<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\ClassController;
use App\Http\Controllers\Teacher\AssignmentController;
use App\Http\Controllers\Teacher\ReviewController;

Route::middleware(['auth:sanctum', 'role:instructor,admin'])->group(function () {
    Route::get('/classes', [ClassController::class, 'index']);
    Route::post('/classes', [ClassController::class, 'store']);
});
