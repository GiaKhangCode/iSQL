<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\ClassController;
use App\Http\Controllers\Teacher\AssignmentController;
use App\Http\Controllers\Teacher\ReviewController;
use App\Http\Controllers\Teacher\CatalogController;
use App\Http\Controllers\ClassRosterController;

Route::middleware(['auth:sanctum', 'active', 'role:instructor_practical,instructor_theory,department_head,instructor'])->group(function () {
    Route::get('/courses', [CatalogController::class, 'courses']);
    Route::get('/terms', [CatalogController::class, 'terms']);
    Route::get('/classes', [ClassController::class, 'index']);
    Route::post('/classes', [ClassController::class, 'store']);
    Route::get('/classes/{id}', [ClassController::class, 'show']);
    Route::put('/classes/{id}', [ClassController::class, 'update']);
    Route::patch('/classes/{id}', [ClassController::class, 'update']);
    Route::delete('/classes/{id}', [ClassController::class, 'destroy']);
    Route::put('/classes/{id}/instructor', [ClassController::class, 'assignInstructor']);
    Route::put('/classes/{id}/instructors', [ClassController::class, 'syncInstructors']);
    Route::delete('/classes/{id}/instructors/{assignmentType}', [ClassController::class, 'removeInstructorAssignment'])
        ->whereIn('assignmentType', ['theory', 'practical']);

    Route::get('/classes/{id}/students', [ClassRosterController::class, 'index']);
    Route::post('/classes/{id}/students', [ClassRosterController::class, 'add']);
    Route::put('/classes/{id}/students', [ClassRosterController::class, 'replace']);
    Route::delete('/classes/{id}/students/{studentId}', [ClassRosterController::class, 'remove']);
    Route::post('/classes/{id}/students/{studentId}/reset-password', [ClassRosterController::class, 'resetStudentPassword']);
});
