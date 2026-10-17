<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Student\DashboardController;
use App\Http\Controllers\Student\AssignmentController;
use App\Http\Controllers\Student\PreferenceController;
use App\Http\Controllers\Student\PersonalDbController;

Route::middleware(['auth:sanctum', 'role:student,instructor,admin'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::get('/preferences', [PreferenceController::class, 'index']);
    Route::post('/preferences/favorites/{id}', [PreferenceController::class, 'toggleFavorite']);
    Route::get('/personal-db/status', [PersonalDbController::class, 'status']);
    Route::post('/personal-db/run', [PersonalDbController::class, 'runQuery']);
});
