<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Notification\app\Http\Controllers\InAppNotificationController;

Route::prefix('v1/notifications')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [InAppNotificationController::class, 'index'])->name('notifications.index');
    Route::get('/unread-count', [InAppNotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::patch('/{uuid}/read', [InAppNotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/read-all', [InAppNotificationController::class, 'markAllAsRead'])->name('notifications.read-all');

    Route::get('/preferences', [InAppNotificationController::class, 'getPreferences'])->name('notifications.preferences.get');
    Route::put('/preferences', [InAppNotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
});
