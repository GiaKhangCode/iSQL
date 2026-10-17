<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/register-lecturer', [AuthController::class, 'registerLecturer']);
Route::post('/demo', [AuthController::class, 'demoLogin']);
Route::post('/token', [AuthController::class, 'tokenLogin']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
});
