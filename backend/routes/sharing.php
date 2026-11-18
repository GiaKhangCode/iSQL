<?php

use App\Http\Controllers\ContentSharingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/', [ContentSharingController::class, 'inbox']);
    Route::get('/recipients/search', [ContentSharingController::class, 'searchRecipients']);
    Route::get('/{kind}/{resourceId}/preview', [ContentSharingController::class, 'preview']);
    Route::post('/{kind}/{resourceId}/copy', [ContentSharingController::class, 'copy']);
    Route::get('/{kind}/{resourceId}', [ContentSharingController::class, 'shares']);
    Route::put('/{kind}/{resourceId}', [ContentSharingController::class, 'share']);
    Route::delete('/{kind}/{resourceId}/{recipientId}', [ContentSharingController::class, 'revoke']);
});

