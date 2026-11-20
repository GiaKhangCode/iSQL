<?php

use App\Http\Controllers\ContentSharingController;
use Illuminate\Support\Facades\Route;

// Quyền theo loại nội dung (problem.* / library.*) được kiểm tiếp trong ContentSharingService;
// ở đây chỉ chặn người không có quyền nào liên quan.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/', [ContentSharingController::class, 'inbox']);

    Route::middleware('capability:problem.share,library.share')->group(function () {
        Route::get('/recipients/search', [ContentSharingController::class, 'searchRecipients']);
        Route::get('/{kind}/{resourceId}', [ContentSharingController::class, 'shares']);
        Route::put('/{kind}/{resourceId}', [ContentSharingController::class, 'share']);
        Route::delete('/{kind}/{resourceId}/{recipientId}', [ContentSharingController::class, 'revoke']);
    });

    Route::middleware('capability:problem.create,library.manage')->group(function () {
        Route::get('/{kind}/{resourceId}/preview', [ContentSharingController::class, 'preview']);
        Route::post('/{kind}/{resourceId}/copy', [ContentSharingController::class, 'copy']);
    });
});

