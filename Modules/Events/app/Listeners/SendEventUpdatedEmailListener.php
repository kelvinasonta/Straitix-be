<?php

declare(strict_types=1);

namespace Modules\Events\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Events\app\Events\EventUpdatedEvent;
use Modules\Events\app\Services\Mail\SendEventUpdatedEmailService;
use Throwable;

class SendEventUpdatedEmailListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Number of times the queued job may be attempted.
     */
    public int $tries = 3;

    public function __construct(
        private SendEventUpdatedEmailService $sendEventUpdatedEmailService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(EventUpdatedEvent $event): void
    {
        try {
            $this->sendEventUpdatedEmailService->execute(
                $event->event,
                $event->organizer,
                $event->editor,
                $event->changes
            );
        } catch (Throwable $e) {
            Log::error("Failed to send event updated email for event [{$event->event->id}]: {$e->getMessage()}");
            throw $e;
        }
    }
}

