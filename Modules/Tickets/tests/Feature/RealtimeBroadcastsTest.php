<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event as EventFacade;
use Modules\Auth\app\Models\User;
use Modules\Credits\app\Services\CreditLedgerService;
use Modules\Events\app\Enum\EventStatus;
use Modules\Events\app\Enum\EventType;
use Modules\Events\app\Enum\EventVisibility;
use Modules\Events\app\Models\Event;
use Modules\Notification\app\Services\MailerService;
use Modules\Organizer\app\Enum\OrganizerMemberRole;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerMember;
use Modules\Payments\app\Services\OrderService;
use Modules\Tickets\app\Events\AttendeeCheckedIn;
use Modules\Tickets\app\Events\BulkJobProgressUpdated;
use Modules\Tickets\app\Events\CheckInUndone;
use Modules\Tickets\app\Events\TicketInventoryUpdated;
use Modules\Tickets\app\Models\Ticket;
use Modules\Tickets\app\Models\TicketType;
use Modules\Tickets\app\Services\AttendeeService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['account_type' => 'organizer']);
    $this->organizer = Organizer::create([
        'user_id' => $this->owner->id,
        'name' => 'Realtime Events Group',
    ]);

    $this->staffUser = User::factory()->create(['account_type' => 'organizer']);
    OrganizerMember::create([
        'organizer_id' => $this->organizer->id,
        'user_id' => $this->staffUser->id,
        'role' => OrganizerMemberRole::STAFF,
        'status' => 'active',
    ]);

    $this->outsider = User::factory()->create(['account_type' => 'customer']);

    $this->event = Event::create([
        'organizer_id' => $this->organizer->id,
        'user_id' => $this->owner->id,
        'title' => 'Lagos Live Summit 2026',
        'description' => 'Live tech conference.',
        'event_country' => 'Nigeria',
        'event_type' => EventType::PHYSICAL,
        'event_format' => 'Conference',
        'status' => EventStatus::PUBLISHED,
        'visibility' => EventVisibility::PUBLIC,
    ]);

    $this->ticketType = TicketType::create([
        'event_id' => $this->event->id,
        'name' => 'VIP Pass',
        'type' => 'paid',
        'ticket_capacity' => 'limited',
        'quantity' => 10,
        'sold_quantity' => 0,
        'reserved_quantity' => 0,
        'price' => 15000.00,
        'currency' => 'NGN',
        'status' => 'active',
    ]);
});

test('order reservation and cancellation broadcast TicketInventoryUpdated on public inventory channel', function () {
    EventFacade::fake([TicketInventoryUpdated::class]);

    $orderService = app(OrderService::class);
    $order = $orderService->createOrder(
        buyer: $this->outsider,
        event: $this->event,
        itemsData: [
            ['ticket_type_uuid' => $this->ticketType->uuid, 'quantity' => 3],
        ],
        customerData: [
            'customer_name' => 'Test Buyer',
            'customer_email' => 'buyer@example.com',
        ]
    );

    EventFacade::assertDispatched(
        TicketInventoryUpdated::class,
        function (TicketInventoryUpdated $event): bool {
            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();
            $tier = $payload['ticket_types'][0] ?? null;

            return count($channels) === 1
                && $channels[0] instanceof Channel
                && $channels[0]->name === "events.{$this->event->uuid}.inventory"
                && $event->broadcastAs() === 'ticket.inventory.updated'
                && $tier !== null
                && $tier['reserved_quantity'] === 3
                && $tier['available_quantity'] === 7;
        }
    );

    EventFacade::fake([TicketInventoryUpdated::class]);
    $orderService->cancelOrder($order->fresh(['items.ticketType']));

    EventFacade::assertDispatched(
        TicketInventoryUpdated::class,
        function (TicketInventoryUpdated $event): bool {
            $tier = $event->broadcastWith()['ticket_types'][0] ?? null;

            return $tier !== null
                && $tier['reserved_quantity'] === 0
                && $tier['available_quantity'] === 10;
        }
    );
});

test('attendee check-in and undo check-in broadcast AttendeeCheckedIn and CheckInUndone on private gate channel', function () {
    EventFacade::fake([AttendeeCheckedIn::class, CheckInUndone::class]);

    $ticket = Ticket::create([
        'event_id' => $this->event->id,
        'user_id' => $this->outsider->id,
        'ticket_type_id' => $this->ticketType->id,
        'attendee_name' => 'Gate Guest',
        'attendee_email' => 'gate@example.com',
        'ticket_number' => 'TKT-GATE001',
        'price_paid' => 15000.00,
        'currency' => 'NGN',
        'status' => 'valid',
        'qr_code' => 'QR-GATE-001',
    ]);

    $attendeeService = app(AttendeeService::class);
    $attendeeService->checkInAttendee($this->organizer, $this->event->uuid, $ticket->ticket_number);

    EventFacade::assertDispatched(
        AttendeeCheckedIn::class,
        function (AttendeeCheckedIn $event): bool {
            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();

            return count($channels) === 1
                && $channels[0]->name === "private-events.{$this->event->uuid}.gate"
                && $event->broadcastAs() === 'attendee.checked_in'
                && $payload['stats']['checked_in_count'] === 1
                && $payload['ticket']['ticket_number'] === 'TKT-GATE001';
        }
    );

    $attendeeService->undoCheckInAttendee($this->organizer, $this->event->uuid, $ticket->ticket_number);

    EventFacade::assertDispatched(
        CheckInUndone::class,
        function (CheckInUndone $event): bool {
            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();

            return count($channels) === 1
                && $channels[0]->name === "private-events.{$this->event->uuid}.gate"
                && $event->broadcastAs() === 'attendee.check_in_undone'
                && $payload['stats']['checked_in_count'] === 0
                && $payload['ticket']['checked_in_at'] === null;
        }
    );
});

test('email broadcast emits BulkJobProgressUpdated events on private organizer jobs channel', function () {
    EventFacade::fake([BulkJobProgressUpdated::class]);

    Ticket::create([
        'event_id' => $this->event->id,
        'user_id' => $this->outsider->id,
        'ticket_type_id' => $this->ticketType->id,
        'attendee_name' => 'Broadcast Attendee 1',
        'attendee_email' => 'one@example.com',
        'ticket_number' => 'TKT-BRC001',
        'price_paid' => 15000.00,
        'currency' => 'NGN',
        'status' => 'valid',
        'qr_code' => 'QR-BRC-001',
    ]);

    $ledger = app(CreditLedgerService::class);
    $wallet = $ledger->getOrCreateWallet($this->organizer);
    $ledger->creditWallet(
        wallet: $wallet,
        amount: 50,
        reason: \Modules\Credits\app\Enums\CreditTxReason::TOPUP,
        idempotencyKey: 'realtime_brc_seed',
        reference: 'REF-RT-SEED',
        description: 'Seed credits for realtime broadcast test'
    );

    $mockMailer = Mockery::mock(MailerService::class);
    $mockMailer->shouldReceive('send')->once()->andReturnTrue();
    $this->app->instance(MailerService::class, $mockMailer);

    $result = app(AttendeeService::class)->broadcastEmail(
        organizer: $this->organizer,
        eventUuid: $this->event->uuid,
        subject: 'Doors Open Early',
        message: 'Please arrive 30 minutes before keynote.',
        actor: $this->owner
    );

    expect($result['sent_count'])->toBe(1);

    EventFacade::assertDispatched(
        BulkJobProgressUpdated::class,
        function (BulkJobProgressUpdated $event): bool {
            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();

            return count($channels) === 1
                && $channels[0]->name === "private-organizers.{$this->organizer->uuid}.jobs"
                && $event->broadcastAs() === 'bulk.job.progress'
                && $payload['job_type'] === 'email_broadcast'
                && $payload['status'] === 'completed'
                && $payload['sent'] === 1
                && $payload['total'] === 1
                && $payload['percentage'] === 100;
        }
    );
});

test('organizer members can authorize gate and jobs private channels while outsiders are rejected', function () {
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.key', 'test-key');
    Config::set('broadcasting.connections.reverb.secret', 'test-secret');
    Config::set('broadcasting.connections.reverb.app_id', 'test-app');
    Broadcast::purge();
    require base_path('routes/channels.php');

    foreach (["private-events.{$this->event->uuid}.gate", "private-organizers.{$this->organizer->uuid}.jobs"] as $channel) {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $channel,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($this->staffUser, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $channel,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($this->outsider, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $channel,
            ])
            ->assertForbidden();
    }
});
