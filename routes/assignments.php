<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\AssignmentController;

// Public banner image delivery (for <img> elements)
Route::get('/banners/{filename}', [AssignmentController::class, 'getBanner']);

// Authenticated assignment management
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Specific static routes defined before parameterised {id} routes
    Route::post('/banners', [AssignmentController::class, 'uploadBanner']);
    Route::get('/students/search', [AssignmentController::class, 'searchStudents']);
    Route::get('/audience-count', [AssignmentController::class, 'audienceCount']);
    Route::post('/audience-preview', [AssignmentController::class, 'audiencePreview']);

    // List & Detail
    Route::get('/', [AssignmentController::class, 'index']);
    Route::get('/{id}', [AssignmentController::class, 'show']);
    Route::get('/{id}/submissions', [AssignmentController::class, 'submissions']);

    // Teacher CRUD
    Route::post('/', [AssignmentController::class, 'store']);
    Route::put('/{id}', [AssignmentController::class, 'update']);
    Route::delete('/{id}', [AssignmentController::class, 'destroy']);
});
