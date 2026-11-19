<?php

declare(strict_types=1);

namespace Modules\Notification\app\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Auth\app\Models\User;
use Modules\Notification\app\Models\InAppNotification;
use Modules\Notification\app\Models\NotificationPreference;

class InAppNotificationService
{
    /**
     * Get paginated notifications for user.
     *
     * @param  array{unread_only?: bool, per_page?: int}  $filters
     */
    public function getUserNotifications(User $user, array $filters = []): LengthAwarePaginator
    {
        #cache
        $query = InAppNotification::where('user_id', $user->id)
            ->latest('created_at');

        if (! empty($filters['unread_only'])) {
            $query->whereNull('read_at');
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 50);

        return $query->paginate($perPage);
    }

    /**
     * Get count of unread notifications for badge display.
     */
    public function getUnreadCount(User $user): int
    {
        return InAppNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark a single notification as read by uuid.
     */
    public function markAsRead(User $user, string $uuid): bool
    {
        $notification = InAppNotification::where('user_id', $user->id)
            ->where('uuid', $uuid)
            ->first();

        if ($notification === null) {
            return false;
        }

        return $notification->markAsRead();
    }

    /**
     * Mark all notifications as read for user.
     */
    public function markAllAsRead(User $user): int
    {
        return InAppNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Get user notification preferences.
     *
     * @return array<string, array<string, bool>>
     */
    public function getUserPreferences(User $user): array
    {
        $records = NotificationPreference::where('user_id', $user->id)->get();

        $preferences = [];
        foreach ($records as $record) {
            $preferences[$record->category][$record->channel] = (bool) $record->is_enabled;
        }

        return $preferences;
    }

    /**
     * Update user notification preferences.
     *
     * @param  array<string, array<string, bool>>  $preferences  ['orders' => ['email' => true, 'in_app' => false]]
     */
    public function updateUserPreferences(User $user, array $preferences): void
    {
        foreach ($preferences as $category => $channels) {
            if (! is_array($channels)) {
                continue;
            }

            foreach ($channels as $channel => $isEnabled) {
                NotificationPreference::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'category' => (string) $category,
                        'channel' => (string) $channel,
                    ],
                    [
                        'is_enabled' => (bool) $isEnabled,
                    ]
                );
            }
        }
    }
}
