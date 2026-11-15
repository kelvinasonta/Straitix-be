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
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('admin with orders.view can list cross-tenant orders', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'finance_admin',
        'admin_permissions' => ['orders.view'],
    ]);

    $user = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Concert Pro',
        'slug' => 'concert-pro',
    ]);
    $event = Event::create([
        'organizer_id' => $org->id,
        'title' => 'Live in Nairobi',
        'slug' => 'live-in-nairobi',
        'status' => EventStatus::PUBLISHED->value,
    ]);

    $order = Order::create([
        'organizer_id' => $org->id,
        'event_id' => $event->id,
        'order_number' => 'ORD-FINANCE-99',
        'customer_name' => 'Fatima Aliyu',
        'customer_email' => 'fatima@example.com',
        'status' => OrderStatus::Paid,
        'subtotal' => 200.00,
        'discount' => 0.00,
        'service_fee' => 10.00,
        'total' => 210.00,
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/finance/orders?search=ORD-FINANCE-99');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.order_number', 'ORD-FINANCE-99');
});

test('admin can inspect single order details', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'finance_admin',
        'admin_permissions' => ['orders.view'],
    ]);

    $user = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Festival Ops',
        'slug' => 'festival-ops',
    ]);
    $event = Event::create([
        'organizer_id' => $org->id,
        'title' => 'Summer Beats',
        'slug' => 'summer-beats',
        'status' => EventStatus::PUBLISHED->value,
    ]);

    $order = Order::create([
        'organizer_id' => $org->id,
        'event_id' => $event->id,
        'customer_name' => 'David Adeleke',
        'customer_email' => 'david@example.com',
        'status' => OrderStatus::Paid,
        'subtotal' => 50.00,
        'discount' => 0.00,
        'service_fee' => 2.50,
        'total' => 52.50,
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/admin/finance/orders/{$order->uuid}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.customer_name', 'David Adeleke')
        ->assertJsonPath('data.total', '52.50');
});

test('admin with payouts.approve can approve and reject pending payouts', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'finance_admin',
        'admin_permissions' => ['payouts.view', 'payouts.approve'],
    ]);

    $organizerUser = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $organizerUser->id,
        'name' => 'Kigali Arena Events',
        'slug' => 'kigali-arena-events',
    ]);

    $payout = Payout::create([
        'organizer_id' => $org->id,
        'requested_by' => $organizerUser->id,
        'amount' => 1500.00,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    // View payouts queue
    $listRes = $this->actingAs($admin)->getJson('/api/v1/admin/finance/payouts?status=pending');
    $listRes->assertStatus(200)
        ->assertJsonPath('data.total', 1);

    // Approve payout
    $approveRes = $this->actingAs($admin)->postJson("/api/v1/admin/finance/payouts/{$payout->uuid}/approve", [
        'notes' => 'Settlement verified against bank clearing account',
    ]);

    $approveRes->assertStatus(200)
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.admin_notes', 'Settlement verified against bank clearing account');

    expect($payout->fresh()->status)->toBe('approved');
});
