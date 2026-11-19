<?php

declare(strict_types=1);

namespace Modules\Notification\app\Channels;

use Modules\Auth\app\Models\User;
use Modules\Notification\app\Contracts\NotificationChannelInterface;
use Modules\Notification\app\DTOs\NotificationPayload;
use Modules\Notification\app\Models\NotificationDelivery;
use Modules\Notification\app\Services\MailerService;

class EmailChannel implements NotificationChannelInterface
{
    public function __construct(
        protected MailerService $mailerService,
    ) {}

    public function id(): string
    {
        return 'email';
    }

    public function send(?User $user, ?string $target, NotificationPayload $payload): bool
    {
        $recipientEmail = $target ?: $user?->email;
        if (empty($recipientEmail)) {
            return false;
        }

        $templateSlug = $payload->emailTemplateSlug;
        if (empty($templateSlug)) {
            // If no explicit email template slug is provided, skip email channel gracefully
            return false;
        }

        try {
            $this->mailerService->send($recipientEmail, $templateSlug, $payload->data);

            NotificationDelivery::create([
                'user_id' => $user?->id,
                'type' => $payload->type,
                'channel' => $this->id(),
                'recipient_target' => $recipientEmail,
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            logger()->error("EmailChannel delivery failed to [{$recipientEmail}]: ".$e->getMessage());

            NotificationDelivery::create([
                'user_id' => $user?->id,
                'type' => $payload->type,
                'channel' => $this->id(),
                'recipient_target' => $recipientEmail,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
