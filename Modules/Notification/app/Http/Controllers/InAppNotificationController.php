<?php

declare(strict_types=1);

namespace Modules\Notification\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\app\Services\InAppNotificationService;

class InAppNotificationController extends Controller
{
    public function __construct(
        protected InAppNotificationService $notificationService,
    ) {}

    /**
     * Get paginated notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $unreadOnly = $request->boolean('unread_only', false);
        $perPage = $request->integer('per_page', 15);

        $notifications = $this->notificationService->getUserNotifications(
            $request->user(),
            ['unread_only' => $unreadOnly, 'per_page' => $perPage]
        );

        $unreadCount = $this->notificationService->getUnreadCount($request->user());

        return ApiResponse::success('Notifications retrieved successfully.', [
            'notifications' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Get unread notification count badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->getUnreadCount($request->user());

        return ApiResponse::success('Unread notification count retrieved.', [
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, string $uuid): JsonResponse
    {
        $success = $this->notificationService->markAsRead($request->user(), $uuid);

        if (! $success) {
            return ApiResponse::error('Notification not found.', 404, 'NOT_FOUND');
        }

        return ApiResponse::success('Notification marked as read.');
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = $this->notificationService->markAllAsRead($request->user());

        return ApiResponse::success('All notifications marked as read.', [
            'updated_count' => $count,
        ]);
    }

    /**
     * Get user notification preferences.
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $preferences = $this->notificationService->getUserPreferences($request->user());

        return ApiResponse::success('Notification preferences retrieved.', [
            'preferences' => $preferences,
        ]);
    }

    /**
     * Update user notification preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => 'required|array',
            'preferences.*' => 'array',
        ]);

        $this->notificationService->updateUserPreferences(
            $request->user(),
            $request->input('preferences', [])
        );

        $updated = $this->notificationService->getUserPreferences($request->user());

        return ApiResponse::success('Notification preferences updated successfully.', [
            'preferences' => $updated,
        ]);
    }
}
