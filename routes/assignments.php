<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\AssignmentController;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/', [AssignmentController::class, 'index'])->middleware('permission:ASSIGNMENT_MGMT,view');
    Route::post('/', [AssignmentController::class, 'store'])->middleware('permission:ASSIGNMENT_MGMT,add');
    Route::put('/{id}', [AssignmentController::class, 'update'])->middleware('permission:ASSIGNMENT_MGMT,edit');
    Route::delete('/{id}', [AssignmentController::class, 'destroy'])->middleware('permission:ASSIGNMENT_MGMT,delete');
});
