<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\app\Models\User;
use Modules\Events\app\Enum\EventStatus;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Models\Organizer;
use Modules\Tickets\app\Models\Ticket;
use Modules\Tickets\app\Models\TicketType;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('admin with events.moderate can list cross-tenant events', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'trust_safety',
        'admin_permissions' => ['events.moderate'],
    ]);

    $user = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Music Nation',
        'slug' => 'music-nation',
    ]);

    Event::create([
        'organizer_id' => $org->id,
        'title' => 'Afrobeats Fest 2026',
        'slug' => 'afrobeats-fest-2026',
        'status' => EventStatus::PUBLISHED->value,
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/events?search=Afrobeats');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.title', 'Afrobeats Fest 2026');
});

test('admin can force-delist and force-cancel an event with audit log', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'trust_safety',
        'admin_permissions' => ['events.moderate'],
    ]);

    $user = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Underground Promotions',
        'slug' => 'underground-promotions',
    ]);

    $event = Event::create([
        'organizer_id' => $org->id,
        'title' => 'Unlicensed Rave',
        'slug' => 'unlicensed-rave',
        'status' => EventStatus::PUBLISHED->value,
        'is_featured' => true,
    ]);

    // Force delist
    $delistRes = $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->uuid}/delist", [
        'reason' => 'Failure to provide required venue security permits',
    ]);

    $delistRes->assertStatus(200)
        ->assertJsonPath('data.status', EventStatus::DRAFT->value)
        ->assertJsonPath('data.is_featured', false);

    expect($event->fresh()->status)->toBe(EventStatus::DRAFT);

    // Force cancel
    $cancelRes = $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->uuid}/cancel", [
        'reason' => 'Venue contract terminated',
    ]);

    $cancelRes->assertStatus(200)
        ->assertJsonPath('data.status', EventStatus::CANCELLED->value);

    expect($event->fresh()->status)->toBe(EventStatus::CANCELLED);
});

test('admin can toggle event featured status', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'platform_admin',
        'admin_permissions' => ['events.moderate'],
    ]);

    $user = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Tech Summit Hub',
        'slug' => 'tech-summit-hub',
    ]);

    $event = Event::create([
        'organizer_id' => $org->id,
        'title' => 'Web3 Hackathon',
        'slug' => 'web3-hackathon',
        'status' => EventStatus::PUBLISHED->value,
        'is_featured' => false,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->uuid}/feature", [
        'is_featured' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.is_featured', true);

    expect($event->fresh()->is_featured)->toBeTrue();
});

test('admin can retrieve full attendee manifest for an event', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'support_agent',
        'admin_permissions' => ['events.moderate'],
    ]);

    $user = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Developer Circles',
        'slug' => 'developer-circles',
    ]);

    $event = Event::create([
        'organizer_id' => $org->id,
        'title' => 'DevFest Africa',
        'slug' => 'devfest-africa',
        'status' => EventStatus::PUBLISHED->value,
    ]);

    $ticketType = TicketType::create([
        'event_id' => $event->id,
        'name' => 'VIP Ticket',
        'type' => 'paid',
        'price' => 50.00,
        'status' => 'active',
    ]);

    Ticket::create([
        'event_id' => $event->id,
        'ticket_type_id' => $ticketType->id,
        'attendee_name' => 'Kofi Mensah',
        'attendee_email' => 'kofi@example.com',
        'price_paid' => 50.00,
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/admin/events/{$event->uuid}/attendees");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.attendee_name', 'Kofi Mensah');
});
