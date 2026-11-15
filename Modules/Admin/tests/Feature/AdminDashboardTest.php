<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\app\Models\User;
use Modules\Events\app\Enum\EventStatus;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Models\Organizer;
use Modules\Payments\app\Enums\OrderStatus;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Models\Payout;
use Modules\Tickets\app\Models\Ticket;
use Modules\Tickets\app\Models\TicketType;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('admin can retrieve platform KPI stats with correct calculations', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'platform_admin',
        'admin_permissions' => ['dashboard.view'],
    ]);

    $organizerUser = User::factory()->create();
    $organizer = Organizer::create([
        'user_id' => $organizerUser->id,
        'name' => 'African Tech Summit',
        'type' => 'organization',
    ]);

    $event = Event::create([
        'organizer_id' => $organizer->id,
        'title' => 'African Tech Summit 2026',
        'slug' => 'african-tech-summit-2026',
        'status' => EventStatus::PUBLISHED->value,
    ]);

    // Create paid orders
    Order::create([
        'organizer_id' => $organizer->id,
        'event_id' => $event->id,
        'customer_name' => 'John Doe',
        'customer_email' => 'john@example.com',
        'customer_phone' => '+1234567890',
        'status' => OrderStatus::Paid,
        'subtotal' => 100.00,
        'discount' => 10.00,
        'service_fee' => 5.00,
        'total' => 95.00,
        'paid_at' => now(),
    ]);

    $ticketType = TicketType::create([
        'event_id' => $event->id,
        'name' => 'General Admission',
        'type' => 'paid',
        'price' => 95.00,
        'status' => 'active',
    ]);

    // Create tickets
    Ticket::create([
        'event_id' => $event->id,
        'ticket_type_id' => $ticketType->id,
        'price_paid' => 95.00,
    ]);

    // Create payout
    Payout::create([
        'organizer_id' => $organizer->id,
        'requested_by' => $organizerUser->id,
        'amount' => 50.00,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard/stats');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.financials.paid_orders_count', 1)
        ->assertJsonPath('data.tickets.total_sold', 1)
        ->assertJsonPath('data.events.published', 1);

    expect((float) $response->json('data.financials.gmv'))->toEqual(95.0)
        ->and((float) $response->json('data.financials.net_revenue'))->toEqual(5.0)
        ->and((float) $response->json('data.financials.pending_payouts_amount'))->toEqual(50.0);
});

test('admin can retrieve time-series chart data', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'platform_admin',
        'admin_permissions' => ['dashboard.view'],
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard/charts?days=7');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.days', 7)
        ->assertJsonStructure([
            'data' => [
                'days',
                'start_date',
                'end_date',
                'timeline' => [
                    '*' => ['date', 'gmv', 'net_revenue', 'orders_count', 'tickets_count'],
                ],
            ],
        ]);
});

test('user without dashboard.view permission is denied access to stats', function () {
    $limitedAdmin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'kyc_reviewer',
        'admin_permissions' => ['organizers.view'],
    ]);

    $response = $this->actingAs($limitedAdmin)->getJson('/api/v1/admin/dashboard/stats');

    $response->assertStatus(403)
        ->assertJsonPath('code', 'INSUFFICIENT_ADMIN_PERMISSIONS');
});
