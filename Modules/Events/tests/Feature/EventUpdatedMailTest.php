<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Modules\Auth\app\Models\User;
use Modules\Events\app\DTOs\EventDTO;
use Modules\Events\app\DTOs\EventLocationDTO;
use Modules\Events\app\DTOs\EventScheduleDTO;
use Modules\Events\app\Enum\EventStatus;
use Modules\Events\app\Enum\EventType;
use Modules\Events\app\Enum\EventVisibility;
use Modules\Events\app\Events\EventUpdatedEvent;
use Modules\Events\app\Models\Event;
use Modules\Events\app\Services\EventService;
use Modules\Events\app\Services\Mail\SendEventUpdatedEmailService;
use Modules\Notification\app\Services\MailerService;
use Modules\Organizer\app\Models\Organizer;
use Modules\Tickets\app\Models\Ticket;
use Modules\Tickets\app\Models\TicketType;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('dispatches EventUpdatedEvent when an event is updated', function () {
    EventFacade::fake([EventUpdatedEvent::class]);

    $user = User::factory()->create(['account_type' => 'organizer']);
    $organizer = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Tech Summit Organizers',
    ]);

    $event = Event::create([
        'organizer_id' => $organizer->id,
        'user_id' => $user->id,
        'title' => 'Original Tech Summit',
        'description' => 'Original description',
        'event_country' => 'Nigeria',
        'event_type' => EventType::PHYSICAL,
        'event_format' => 'Conference',
        'status' => EventStatus::PUBLISHED,
        'visibility' => EventVisibility::PUBLIC,
    ]);

    $eventService = app(EventService::class);
    $updated = $eventService->updateEvent($organizer, $user, $event->uuid, [
        'title' => 'Updated Tech Summit 2026',
        'description' => 'Brand new conference description',
    ]);

    expect($updated->title)->toBe('Updated Tech Summit 2026');

    EventFacade::assertDispatched(EventUpdatedEvent::class, function ($e) use ($event, $organizer, $user) {
        return $e->event->id === $event->id
            && $e->organizer->id === $organizer->id
            && $e->editor->id === $user->id
            && isset($e->changes['title']);
    });
});

it('does not send attendee emails when an event is updated', function () {
    $user = User::factory()->create([
        'email' => 'creator@example.com',
        'first_name' => 'Alex',
        'account_type' => 'organizer',
    ]);

    $organizer = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Acme Events',
        'email' => 'acme@example.com',
    ]);

    $event = Event::create([
        'organizer_id' => $organizer->id,
        'user_id' => $user->id,
        'title' => 'Design & Code 2026',
        'description' => 'Conference description',
        'event_country' => 'Nigeria',
        'event_type' => EventType::PHYSICAL,
        'event_format' => 'Conference',
        'status' => EventStatus::PUBLISHED,
        'visibility' => EventVisibility::PUBLIC,
    ]);

    $ticketType = TicketType::create([
        'event_id' => $event->id,
        'name' => 'Regular Pass',
        'price' => 5000,
        'quantity' => 100,
        'currency' => 'NGN',
    ]);

    Ticket::create([
        'event_id' => $event->id,
        'ticket_type_id' => $ticketType->id,
        'ticket_number' => 'TKT-12345',
        'attendee_name' => 'Jane Attendee',
        'attendee_email' => 'jane@example.com',
        'price_paid' => 5000,
        'status' => 'valid',
    ]);

    $mailerService = Mockery::mock(MailerService::class);
    // Should NOT send emails to attendees even when material details change
    $mailerService->shouldNotReceive('send');

    $service = new SendEventUpdatedEmailService($mailerService);
    $service->execute($event, $organizer, $user, [
        'location' => [
            'venue_address' => ['old' => 'Old Address', 'new' => '123 New Venue Road'],
        ],
    ]);
});

