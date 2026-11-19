<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\app\Models\User;
use Modules\Events\app\Enum\EventStatus;
use Modules\Events\app\Enum\EventType;
use Modules\Events\app\Enum\EventVisibility;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Enum\OrganizerMemberRole;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerMember;
use Modules\Payments\app\Enums\OrderStatus;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Models\OrderItem;
use Modules\Payments\app\Models\Payment;
use Modules\Payments\app\Services\PaymentService;
use Modules\Tickets\app\Models\Ticket;
use Modules\Tickets\app\Models\TicketType;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['account_type' => 'organizer']);
    $this->organizer = Organizer::create([
        'user_id' => $this->owner->id,
        'name' => 'Tech Events Africa',    ]);

    $this->customer = User::factory()->create(['account_type' => 'customer']);

    $this->event = Event::create([
        'organizer_id' => $this->organizer->id,
        'user_id' => $this->owner->id,
        'title' => 'Lagos AI & Tech Summit 2026',
        'description' => 'Annual premier tech summit in Lagos.',
        'event_country' => 'Nigeria',
        'event_type' => EventType::PHYSICAL,
        'event_format' => 'Conference',
        'status' => EventStatus::PUBLISHED,
        'visibility' => EventVisibility::PUBLIC,
    ]);

    $this->vipTicket = TicketType::create([
        'event_id' => $this->event->id,
        'name' => 'VIP Access',
        'type' => 'paid',
        'price' => 50000.00,
        'currency' => 'NGN',
        'ticket_capacity' => 'limited',
        'quantity' => 50,
        'status' => 'active',
    ]);

    $this->freeTicket = TicketType::create([
        'event_id' => $this->event->id,
        'name' => 'Student Free Pass',
        'type' => 'free',
        'price' => 0.00,
        'currency' => 'NGN',
        'ticket_capacity' => 'limited',
        'quantity' => 100,
        'status' => 'active',
    ]);
});

test('customer can create an order for paid tickets and hold reservation', function () {
    $response = $this->actingAs($this->customer)
        ->postJson("/api/v1/events/{$this->event->uuid}/orders", [
            'items' => [
                [
                    'ticket_type_uuid' => $this->vipTicket->uuid,
                    'quantity' => 2,
                    'attendee_name' => 'Jane Doe',
                    'attendee_email' => 'jane@example.com',
                ],
            ],
            'customer_name' => 'John Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+2348012345678',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.subtotal', 100000)
        ->assertJsonPath('data.total', 100000)
        ->assertJsonPath('data.currency', 'NGN')
        ->assertJsonCount(1, 'data.items');

    $this->assertDatabaseHas('orders', [
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'status' => 'pending',
        'total' => 100000.00,
    ]);

    // Check reservation increment
    expect($this->vipTicket->fresh()->reserved_quantity)->toBe(2);
});

test('free ticket order is fulfilled immediately and tickets are issued', function () {
    $response = $this->actingAs($this->customer)
        ->postJson("/api/v1/events/{$this->event->uuid}/orders", [
            'items' => [
                [
                    'ticket_type_uuid' => $this->freeTicket->uuid,
                    'quantity' => 1,
                    'attendee_name' => 'Alice Tech',
                    'attendee_email' => 'alice@example.com',
                ],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.total', 0)
        ->assertJsonCount(1, 'data.tickets');

    expect($this->freeTicket->fresh()->sold_quantity)->toBe(1);
    expect($this->freeTicket->fresh()->reserved_quantity)->toBe(0);

    $this->assertDatabaseHas('tickets', [
        'user_id' => $this->customer->id,
        'ticket_type_id' => $this->freeTicket->id,
        'attendee_name' => 'Alice Tech',
        'status' => 'valid',
    ]);
});

test('order fails if requested quantity exceeds capacity', function () {
    $response = $this->actingAs($this->customer)
        ->postJson("/api/v1/events/{$this->event->uuid}/orders", [
            'items' => [
                [
                    'ticket_type_uuid' => $this->vipTicket->uuid,
                    'quantity' => 100, // max is 50
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('customer can initialize payment and verify payment to fulfill order', function () {
    $orderRes = $this->actingAs($this->customer)
        ->postJson("/api/v1/events/{$this->event->uuid}/orders", [
            'items' => [
                [
                    'ticket_type_uuid' => $this->vipTicket->uuid,
                    'quantity' => 1,
                ],
            ],
        ]);

    $orderUuid = $orderRes->json('data.id');

    // 1. Initialize payment
    $initRes = $this->actingAs($this->customer)
        ->postJson("/api/v1/orders/{$orderUuid}/payments", [
            'gateway' => 'paystack',
        ]);

    $initRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending');

    $reference = $initRes->json('data.reference');
    expect($reference)->not->toBeEmpty();

    // 2. Verify payment
    $verifyRes = $this->actingAs($this->customer)
        ->getJson("/api/v1/payments/verify/{$reference}");

    $verifyRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonCount(1, 'data.tickets');

    $this->assertDatabaseHas('orders', [
        'uuid' => $orderUuid,
        'status' => 'paid',
    ]);

    expect($this->vipTicket->fresh()->sold_quantity)->toBe(1);
    expect($this->vipTicket->fresh()->reserved_quantity)->toBe(0);
});

test('organizer can view and filter orders for an event', function () {
    // Create an order
    $order = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => 'paid',
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Emeka Okafor',
        'customer_email' => 'emeka@example.com',
    ]);

    $response = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/events/{$this->event->uuid}/orders");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.customerName', 'Emeka Okafor');
});

test('organizer can export orders as CSV', function () {
    Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => 'paid',
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
    ]);

    $response = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/events/{$this->event->uuid}/orders/export");

    $response->assertStatus(200)
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $content = $response->streamedContent();
    expect($content)->toContain('Order Number')
        ->toContain('Ada Lovelace')
        ->toContain('ada@example.com');
});

test('unauthenticated guest can create an order, initialize payment, and verify payment', function () {
    // 1. Create order as guest (no auth)
    $response = $this->postJson("/api/v1/events/{$this->event->uuid}/orders", [
        'items' => [
            [
                'ticket_type_uuid' => $this->vipTicket->uuid,
                'quantity' => 1,
                'attendee_name' => 'Guest Attendee',
                'attendee_email' => 'guest@example.com',
            ],
        ],
        'customer_name' => 'Guest Attendee',
        'customer_email' => 'guest@example.com',
        'customer_phone' => '+2348099887766',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.total', 50000)
        ->assertJsonPath('data.customerEmail', 'guest@example.com');

    $orderUuid = $response->json('data.id');
    $guestToken = $response->json('data.guestToken');
    expect($guestToken)->not->toBeEmpty();

    $this->assertDatabaseHas('orders', [
        'uuid' => $orderUuid,
        'user_id' => null,
        'customer_email' => 'guest@example.com',
        'status' => 'pending',
    ]);

    // 2. Unauthorized access without guest token is rejected with 403
    $unauthShowRes = $this->getJson("/api/v1/orders/{$orderUuid}");
    $unauthShowRes->assertStatus(403);

    // 3. Guest can view order using guest token
    $showRes = $this->withHeader('X-Guest-Token', $guestToken)
        ->getJson("/api/v1/orders/{$orderUuid}");

    $showRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $orderUuid);

    // 4. Guest can initialize payment using guest token
    $initRes = $this->withHeader('X-Guest-Token', $guestToken)
        ->postJson("/api/v1/orders/{$orderUuid}/payments", [
            'gateway' => 'paystack',
        ]);

    $initRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending');

    $reference = $initRes->json('data.reference');
    expect($reference)->not->toBeEmpty();

    // 5. Guest can verify payment by gateway reference
    $verifyRes = $this->getJson("/api/v1/payments/verify/{$reference}");

    $verifyRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonCount(1, 'data.tickets');

    $this->assertDatabaseHas('orders', [
        'uuid' => $orderUuid,
        'status' => 'paid',
        'user_id' => null,
    ]);

    $this->assertDatabaseHas('tickets', [
        'order_id' => Order::where('uuid', $orderUuid)->value('id'),
        'user_id' => null,
        'attendee_name' => 'Guest Attendee',
        'attendee_email' => 'guest@example.com',
        'status' => 'valid',
    ]);
});

test('unauthenticated guest can reserve free tickets directly', function () {
    $response = $this->postJson("/api/v1/events/{$this->event->uuid}/orders", [
        'items' => [
            [
                'ticket_type_uuid' => $this->freeTicket->uuid,
                'quantity' => 2,
                'attendee_name' => 'Free Guest',
                'attendee_email' => 'freeguest@example.com',
            ],
        ],
        'customer_name' => 'Free Guest',
        'customer_email' => 'freeguest@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.total', 0)
        ->assertJsonCount(2, 'data.tickets');

    $this->assertDatabaseHas('orders', [
        'user_id' => null,
        'customer_email' => 'freeguest@example.com',
        'status' => 'paid',
    ]);

    $this->assertDatabaseHas('tickets', [
        'user_id' => null,
        'ticket_type_id' => $this->freeTicket->id,
        'attendee_email' => 'freeguest@example.com',
        'status' => 'valid',
    ]);
});

test('concurrent or repeated fulfillOrder and verifyPayment calls are idempotent and prevent duplicate tickets', function () {
    // 1. Create order for 2 VIP tickets
    $orderRes = $this->actingAs($this->customer)
        ->postJson("/api/v1/events/{$this->event->uuid}/orders", [
            'items' => [
                [
                    'ticket_type_uuid' => $this->vipTicket->uuid,
                    'quantity' => 2,
                    'attendee_name' => 'Concurrent Customer',
                    'attendee_email' => 'concurrent@example.com',
                ],
            ],
        ]);

    $orderUuid = $orderRes->json('data.id');
    $order = Order::where('uuid', $orderUuid)->firstOrFail();

    // 2. Initialize payment
    $initRes = $this->actingAs($this->customer)
        ->postJson("/api/v1/orders/{$orderUuid}/payments", [
            'gateway' => 'paystack',
        ]);

    $reference = $initRes->json('data.reference');
    $payment = Payment::where('reference', $reference)->firstOrFail();

    /** @var PaymentService $paymentService */
    $paymentService = app(PaymentService::class);

    // Simulate parallel execution: verifyPayment (e.g. from user redirect) and fulfillOrder (e.g. from webhook)
    $fulfilledOrder1 = $paymentService->fulfillOrder($order, $payment, ['source' => 'webhook']);
    $fulfilledOrder2 = $paymentService->verifyPayment($reference, ['source' => 'browser']);
    $fulfilledOrder3 = $paymentService->fulfillOrder($order, $payment, ['source' => 'webhook_retry']);

    // Order status must be paid
    expect($fulfilledOrder1->status)->toBe(OrderStatus::Paid)
        ->and($fulfilledOrder2->status)->toBe(OrderStatus::Paid)
        ->and($fulfilledOrder3->status)->toBe(OrderStatus::Paid);

    // Exactly 2 tickets must exist for this order, never duplicated
    $tickets = Ticket::where('order_id', $order->id)->get();
    expect($tickets)->toHaveCount(2);

    // Ticket inventory check: sold_quantity increased by 2 exactly once
    expect($this->vipTicket->fresh()->sold_quantity)->toBe(2);
    expect($this->vipTicket->fresh()->reserved_quantity)->toBe(0);
});

test('pending order can be cancelled and held ticket reservation is released', function () {
    $orderRes = $this->actingAs($this->customer)
        ->postJson("/api/v1/events/{$this->event->uuid}/orders", [
            'items' => [
                [
                    'ticket_type_uuid' => $this->vipTicket->uuid,
                    'quantity' => 2,
                ],
            ],
        ]);

    $orderUuid = $orderRes->json('data.id');
    expect($this->vipTicket->fresh()->reserved_quantity)->toBe(2);

    $cancelRes = $this->actingAs($this->customer)
        ->postJson("/api/v1/orders/{$orderUuid}/cancel");

    $cancelRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'cancelled');

    expect($this->vipTicket->fresh()->reserved_quantity)->toBe(0);
});

test('organizer can refund a paid order, void tickets, decrement sold quantity, and record audit', function () {
    $order = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Paid,
        'subtotal' => 100000.00,
        'discount' => 0.00,
        'service_fee' => 0.00,
        'total' => 100000.00,
        'currency' => 'NGN',
        'customer_name' => 'John Customer',
        'customer_email' => 'customer@example.com',
        'paid_at' => now(),
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'ticket_type_id' => $this->vipTicket->id,
        'quantity' => 2,
        'unit_price' => 50000.00,
        'total_price' => 100000.00,
        'currency' => 'NGN',
        'attendee_name' => 'John Customer',
        'attendee_email' => 'customer@example.com',
    ]);

    $this->vipTicket->update(['sold_quantity' => 2]);

    $ticket1 = Ticket::create([
        'order_id' => $order->id,
        'event_id' => $this->event->id,
        'user_id' => $this->customer->id,
        'ticket_type_id' => $this->vipTicket->id,
        'attendee_name' => 'John Customer',
        'attendee_email' => 'customer@example.com',
        'price_paid' => 50000.00,
        'currency' => 'NGN',
        'status' => 'valid',
        'ticket_number' => 'TKT-REF-1',
        'qr_code' => 'QR-1',
    ]);

    $ticket2 = Ticket::create([
        'order_id' => $order->id,
        'event_id' => $this->event->id,
        'user_id' => $this->customer->id,
        'ticket_type_id' => $this->vipTicket->id,
        'attendee_name' => 'John Customer',
        'attendee_email' => 'customer@example.com',
        'price_paid' => 50000.00,
        'currency' => 'NGN',
        'status' => 'valid',
        'ticket_number' => 'TKT-REF-2',
        'qr_code' => 'QR-2',
    ]);

    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/orders/{$order->uuid}/refund", [
            'reason' => 'Customer requested cancellation',
            'amount' => 100000.00,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Order refunded successfully.')
        ->assertJsonPath('data.status', 'refunded');

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->fresh()->refunded_at)->not->toBeNull();

    expect($ticket1->fresh()->status)->toBe('refunded')
        ->and($ticket2->fresh()->status)->toBe('refunded');

    expect($this->vipTicket->fresh()->sold_quantity)->toBe(0);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'order.refunded',
        'organizer_id' => $this->organizer->id,
    ]);
});

test('refunding an unpaid or already refunded order returns 422', function () {
    $pendingOrder = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Pending Buyer',
        'customer_email' => 'pending@example.com',
    ]);

    $res1 = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/orders/{$pendingOrder->uuid}/refund");

    $res1->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Only paid orders can be refunded.');

    $pendingOrder->update(['status' => OrderStatus::Paid]);

    $res2 = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/orders/{$pendingOrder->uuid}/refund");
    $res2->assertStatus(200);

    $res3 = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/orders/{$pendingOrder->uuid}/refund");
    $res3->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Order has already been refunded.');
});

test('refunding an order from a different organizer returns 404', function () {
    $otherOwner = User::factory()->create(['account_type' => 'organizer']);
    $otherOrganizer = Organizer::create([
        'user_id' => $otherOwner->id,
        'name' => 'Other Organizer',
    ]);

    $otherOrder = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $otherOrganizer->id,
        'status' => OrderStatus::Paid,
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Foreign Buyer',
        'customer_email' => 'foreign@example.com',
    ]);

    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/orders/{$otherOrder->uuid}/refund");

    $response->assertStatus(404);
});

test('user without finance manage permission cannot refund order', function () {
    $staffUser = User::factory()->create();
    OrganizerMember::create([
        'organizer_id' => $this->organizer->id,
        'user_id' => $staffUser->id,
        'role' => OrganizerMemberRole::STAFF,
    ]);

    $paidOrder = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Paid,
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'VIP Buyer',
        'customer_email' => 'buyer@example.com',
    ]);

    $response = $this->actingAs($staffUser)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/orders/{$paidOrder->uuid}/refund");

    $response->assertStatus(403);
});

test('organizer can resend purchase receipt and ticket pass for a paid order', function () {
    $order = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Paid,
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Receipt Buyer',
        'customer_email' => 'receipt@example.com',
        'paid_at' => now(),
    ]);

    Ticket::create([
        'order_id' => $order->id,
        'event_id' => $this->event->id,
        'user_id' => $this->customer->id,
        'ticket_type_id' => $this->vipTicket->id,
        'attendee_name' => 'Receipt Buyer',
        'attendee_email' => 'receipt@example.com',
        'price_paid' => 50000.00,
        'currency' => 'NGN',
        'status' => 'valid',
        'ticket_number' => 'TKT-RCP-1',
        'qr_code' => 'QR-RCP-1',
    ]);

    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/orders/{$order->uuid}/resend-receipt");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Purchase receipt and ticket pass re-sent successfully.')
        ->assertJsonPath('data', null);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'order.receipt_resent',
        'organizer_id' => $this->organizer->id,
    ]);
});

test('resending receipt fails for unpaid order with no tickets', function () {
    $pendingOrder = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Pending Buyer',
        'customer_email' => 'pending@example.com',
    ]);

    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/orders/{$pendingOrder->uuid}/resend-receipt");

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('order resource does not violate strict lazy loading rules when event location is not loaded', function () {
    \Illuminate\Database\Eloquent\Model::preventLazyLoading(true);

    $order = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Paid,
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Strict Buyer',
        'customer_email' => 'strict@example.com',
    ]);

    // Load only event without location
    $order->load(['event']);

    $resource = new \Modules\Payments\app\Transformers\OrderResource($order);
    $data = $resource->toArray(request());

    expect($data)->toHaveKey('event')
        ->and($data['event']['venue'])->toBe($this->event->venue_name);

    \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
});

test('fulfillOrder dispatches OrderPaymentConfirmed on private orders channel', function () {
    \Illuminate\Support\Facades\Event::fake([
        \Modules\Payments\app\Events\OrderPaidEvent::class,
        \Modules\Payments\app\Events\OrderPaymentConfirmed::class,
    ]);

    $order = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Realtime Buyer',
        'customer_email' => 'realtime@example.com',
    ]);

    $payment = \Modules\Payments\app\Models\Payment::create([
        'order_id' => $order->id,
        'gateway' => 'paystack',
        'reference' => 'ref_realtime_' . uniqid(),
        'amount' => 50000.00,
        'currency' => 'NGN',
        'status' => 'pending',
    ]);

    app(\Modules\Payments\app\Services\PaymentService::class)->fulfillOrder($order, $payment);

    \Illuminate\Support\Facades\Event::assertDispatched(
        \Modules\Payments\app\Events\OrderPaymentConfirmed::class,
        function (\Modules\Payments\app\Events\OrderPaymentConfirmed $event) use ($order) {
            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();

            return count($channels) === 1
                && $channels[0]->name === "private-orders.{$order->uuid}"
                && $event->broadcastAs() === 'order.payment.confirmed'
                && $payload['order_uuid'] === $order->uuid
                && $payload['status'] === 'paid';
        }
    );
});

test('authenticated buyer can authorize private order channel and other users are rejected', function () {
    \Illuminate\Support\Facades\Config::set('broadcasting.default', 'reverb');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.key', 'test-key');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.secret', 'test-secret');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.app_id', 'test-app');
    \Illuminate\Support\Facades\Broadcast::purge();
    require base_path('routes/channels.php');

    $order = Order::create([
        'user_id' => $this->customer->id,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 50000.00,
        'total' => 50000.00,
        'currency' => 'NGN',
        'customer_name' => 'Auth Buyer',
        'customer_email' => $this->customer->email,
    ]);

    $otherUser = User::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-orders.{$order->uuid}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs($otherUser, 'sanctum')
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-orders.{$order->uuid}",
        ])
        ->assertForbidden();
});

test('guest buyer can authorize private order channel with valid X-Guest-Token and invalid tokens are rejected', function () {
    \Illuminate\Support\Facades\Config::set('broadcasting.default', 'reverb');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.key', 'test-key');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.secret', 'test-secret');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.app_id', 'test-app');
    \Illuminate\Support\Facades\Broadcast::purge();

    $guestToken = 'guest_tok_' . \Illuminate\Support\Str::random(24);
    $order = Order::create([
        'user_id' => null,
        'event_id' => $this->event->id,
        'organizer_id' => $this->organizer->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 25000.00,
        'total' => 25000.00,
        'currency' => 'NGN',
        'customer_name' => 'Guest Buyer',
        'customer_email' => 'guest@example.com',
        'guest_token' => $guestToken,
    ]);

    $this->withHeaders(['X-Guest-Token' => $guestToken])
        ->postJson('/api/v1/orders/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-orders.{$order->uuid}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->withHeaders(['X-Guest-Token' => 'wrong-token'])
        ->postJson('/api/v1/orders/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-orders.{$order->uuid}",
        ])
        ->assertForbidden();

    $this->postJson('/api/v1/orders/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-orders.{$order->uuid}",
    ])->assertForbidden();
});
