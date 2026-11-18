<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NotificationController;

Route::middleware('auth:sanctum')->group(function () {
    // Route cố định (không có tham số) đặt trước route có {id}.
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/', [NotificationController::class, 'destroyAll']);

    // Route theo một thông báo cụ thể.
    Route::patch('/{id}/read', [NotificationController::class, 'markRead']);
    Route::delete('/{id}', [NotificationController::class, 'destroy']);
});
