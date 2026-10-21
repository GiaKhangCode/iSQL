<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\ClassController;
use App\Http\Controllers\Teacher\AssignmentController;
use App\Http\Controllers\Teacher\ReviewController;
use App\Http\Controllers\Teacher\CatalogController;
use App\Http\Controllers\ClassRosterController;

Route::middleware(['auth:sanctum', 'active', 'permission:CLASS_MGMT,view'])->group(function () {
    Route::get('/courses', [CatalogController::class, 'courses']);
    Route::get('/terms', [CatalogController::class, 'terms']);
    Route::get('/classes', [ClassController::class, 'index']);
    Route::post('/classes', [ClassController::class, 'store'])->middleware('permission:CLASS_MGMT,add');
    Route::get('/classes/{id}', [ClassController::class, 'show']);
    Route::put('/classes/{id}', [ClassController::class, 'update'])->middleware('permission:CLASS_MGMT,edit');
    Route::patch('/classes/{id}', [ClassController::class, 'update'])->middleware('permission:CLASS_MGMT,edit');
    Route::delete('/classes/{id}', [ClassController::class, 'destroy'])->middleware('permission:CLASS_MGMT,delete');
    Route::put('/classes/{id}/instructor', [ClassController::class, 'assignInstructor'])->middleware('permission:CLASS_MGMT,edit');
    Route::put('/classes/{id}/instructors', [ClassController::class, 'syncInstructors'])->middleware('permission:CLASS_MGMT,edit');
    Route::delete('/classes/{id}/instructors/{assignmentType}', [ClassController::class, 'removeInstructorAssignment'])
        ->whereIn('assignmentType', ['theory', 'practical'])
        ->middleware('permission:CLASS_MGMT,edit');

    Route::get('/classes/{id}/students', [ClassRosterController::class, 'index']);
    Route::post('/classes/{id}/students', [ClassRosterController::class, 'add'])->middleware('permission:CLASS_MGMT,edit');
    Route::put('/classes/{id}/students', [ClassRosterController::class, 'replace'])->middleware('permission:CLASS_MGMT,edit');
    Route::delete('/classes/{id}/students/{studentId}', [ClassRosterController::class, 'remove'])->middleware('permission:CLASS_MGMT,edit');
    Route::post('/classes/{id}/students/{studentId}/reset-password', [ClassRosterController::class, 'resetStudentPassword'])->middleware('permission:CLASS_MGMT,edit');
});
