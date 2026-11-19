<?php

declare(strict_types=1);

namespace Modules\Notification\app\Channels;

use Modules\Auth\app\Models\User;
use Modules\Notification\app\Contracts\NotificationChannelInterface;
use Modules\Notification\app\DTOs\NotificationPayload;
use Modules\Notification\app\Models\InAppNotification;
use Modules\Notification\app\Models\NotificationDelivery;

class InAppChannel implements NotificationChannelInterface
{
    public function id(): string
    {
        return 'in_app';
    }

    public function send(?User $user, ?string $target, NotificationPayload $payload): bool
    {
        // In-app notifications require an authenticated user account
        if ($user === null) {
            return false;
        }

        try {
            $notification = InAppNotification::create([
                'user_id' => $user->id,
                'type' => $payload->type,
                'title' => $payload->title,
                'message' => $payload->message,
                'action_url' => $payload->actionUrl,
                'icon' => $payload->icon,
                'data' => $payload->data,
            ]);

            NotificationDelivery::create([
                'user_id' => $user->id,
                'in_app_notification_id' => $notification->id,
                'type' => $payload->type,
                'channel' => $this->id(),
                'recipient_target' => (string) $user->id,
                'status' => 'delivered',
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            logger()->error("InAppChannel delivery failed for user [{$user->id}]: ".$e->getMessage());

            NotificationDelivery::create([
                'user_id' => $user->id,
                'type' => $payload->type,
                'channel' => $this->id(),
                'recipient_target' => (string) $user->id,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
