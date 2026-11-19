<?php

declare(strict_types=1);

namespace Modules\Notification\app\Contracts;

use Modules\Auth\app\Models\User;
use Modules\Notification\app\DTOs\NotificationPayload;

interface NotificationChannelInterface
{
    /**
     * Unique identifier for this channel (e.g. 'in_app', 'email', 'push', 'sms', 'whatsapp')
     */
    public function id(): string;

    /**
     * Send notification to recipient over this channel.
     *
     * @param  User|null  $user  User instance if registered, null for guest
     * @param  string|null  $target  Target identifier (email address, phone number, push token)
     */
    public function send(?User $user, ?string $target, NotificationPayload $payload): bool;
}
