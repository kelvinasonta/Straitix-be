<?php

namespace Modules\Events\app\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Events\app\Events\EventCreatedEvent;
use Modules\Events\app\Events\EventUpdatedEvent;
use Modules\Events\app\Listeners\SendEventCreatedEmailListener;
use Modules\Events\app\Listeners\SendEventUpdatedEmailListener;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        EventCreatedEvent::class => [
            SendEventCreatedEmailListener::class,
        ],
        EventUpdatedEvent::class => [
            SendEventUpdatedEmailListener::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
