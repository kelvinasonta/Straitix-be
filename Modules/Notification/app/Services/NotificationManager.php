<?php

declare(strict_types=1);

namespace Modules\Notification\app\Services;

use Modules\Auth\app\Models\User;
use Modules\Notification\app\Channels\EmailChannel;
use Modules\Notification\app\Channels\InAppChannel;
use Modules\Notification\app\Contracts\NotificationChannelInterface;
use Modules\Notification\app\DTOs\NotificationPayload;
use Modules\Notification\app\Models\NotificationPreference;

class NotificationManager
{
    /**
     * @var array<string, NotificationChannelInterface>
     */
    protected array $channels = [];

    public function __construct(
        InAppChannel $inAppChannel,
        EmailChannel $emailChannel,
    ) {
        $this->registerChannel($inAppChannel);
        $this->registerChannel($emailChannel);
    }

    public function registerChannel(NotificationChannelInterface $channel): void
    {
        $this->channels[$channel->id()] = $channel;
    }

    /**
     * Dispatch notification across allowed channels.
     * Respects user opt-in/opt-out preferences unless marked as mandatory.
     *
     * @param  User|null  $user  Registered user (null for guest)
     * @param  string|null  $target  Email, phone number, or token override
     * @return array<string, bool> Results indexed by channel id
     */
    public function send(?User $user, ?string $target, NotificationPayload $payload): array
    {
        $results = [];

        foreach ($payload->channels as $channelId) {
            if (! isset($this->channels[$channelId])) {
                continue;
            }

            // Check if user disabled this category on this channel (if not mandatory)
            if ($user !== null && ! $payload->isMandatory) {
                if (! $this->isChannelAllowedForUser($user, $payload->category, $channelId)) {
                    $results[$channelId] = false;
                    continue;
                }
            }

            $channel = $this->channels[$channelId];
            $results[$channelId] = $channel->send($user, $target, $payload);
        }

        return $results;
    }

    /**
     * Check if user has opted out of this category/channel combination.
     */
    protected function isChannelAllowedForUser(User $user, string $category, string $channelId): bool
    {
        $pref = NotificationPreference::where('user_id', $user->id)
            ->where('category', $category)
            ->where('channel', $channelId)
            ->first();

        // Enabled by default unless an explicit record says is_enabled = false
        if ($pref !== null) {
            return (bool) $pref->is_enabled;
        }

        return true;
    }
}
