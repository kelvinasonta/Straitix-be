<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\app\Models\User;
use Modules\Credits\app\Enums\CreditTxReason;
use Modules\Credits\app\Services\CreditLedgerService;
use Modules\Events\app\Enum\EventStatus;
use Modules\Events\app\Enum\EventType;
use Modules\Events\app\Enum\EventVisibility;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Enum\OrganizerPlanTier;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerSubscription;
use Modules\Payments\app\Services\OrderService;
use Modules\Shared\app\Contracts\AppSettingsServiceInterface;
use Modules\Shared\app\DTOs\AppSettingsDTO;
use Modules\Tickets\app\Models\TicketType;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('organizer can view subscription status and database-backed plans catalog', function () {
    $owner = User::factory()->create();
    $organizer = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Apex Events',
        'slug' => 'apex-events',
    ]);

    $response = $this->actingAs($owner)
        ->getJson("/api/v1/organizers/{$organizer->uuid}/subscription");

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.current_plan.tier', 'free')
        ->assertJsonPath('data.current_plan.is_paid_active', false)
        ->assertJsonCount(3, 'data.plans')
        ->assertJsonPath('data.plans.1.slug', 'pro')
        ->assertJsonPath('data.plans.1.monthly_cost_credits', 1500)
        ->assertJsonPath('data.plans.1.annual_cost_credits', 15000);
});

test('organizer can upgrade to pro plan using credits wallet, record subscription history, and cancel auto-renewal', function () {
    $owner = User::factory()->create();
    $organizer = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Pro Concert Series',
        'slug' => 'pro-concert-series',
    ]);

    /** @var CreditLedgerService $ledger */
    $ledger = app(CreditLedgerService::class);
    $wallet = $ledger->getOrCreateWallet($organizer);
    $ledger->creditWallet(
        wallet: $wallet,
        amount: 2000,
        reason: CreditTxReason::TOPUP,
        idempotencyKey: 'seed_topup_2000',
        reference: 'REF-SEED-2000',
        description: 'Initial credit topup'
    );

    $response = $this->actingAs($owner)
        ->postJson("/api/v1/organizers/{$organizer->uuid}/subscription/upgrade", [
            'plan_tier' => OrganizerPlanTier::Pro->value,
            'months' => 1,
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.current_plan.tier', 'pro')
        ->assertJsonPath('data.current_plan.is_paid_active', true)
        ->assertJsonPath('data.current_plan.auto_renew', true)
        ->assertJsonPath('data.effective_fee.percentage', 2.5)
        ->assertJsonPath('data.effective_fee.fixed', 50)
        ->assertJsonPath('data.effective_fee.source', 'plan:pro')
        ->assertJsonPath('data.wallet.available_credits', 500)
        ->assertJsonCount(1, 'data.history');

    // Cancel auto-renewal while keeping Pro benefits until expiry
    $cancelRes = $this->actingAs($owner)
        ->postJson("/api/v1/organizers/{$organizer->uuid}/subscription/cancel");

    $cancelRes->assertSuccessful()
        ->assertJsonPath('data.current_plan.tier', 'pro')
        ->assertJsonPath('data.current_plan.is_paid_active', true)
        ->assertJsonPath('data.current_plan.auto_renew', false)
        ->assertJsonPath('data.current_plan.status', 'cancelled');
});

test('organizer upgrade fails with 422 when credit balance is insufficient', function () {
    $owner = User::factory()->create();
    $organizer = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Low Balance Org',
        'slug' => 'low-balance-org',
    ]);

    $response = $this->actingAs($owner)
        ->postJson("/api/v1/organizers/{$organizer->uuid}/subscription/upgrade", [
            'plan_tier' => OrganizerPlanTier::Pro->value,
            'months' => 1,
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('success', false);
});

test('subscription plan enforces max staff member limits and unlocks higher limits on upgrade', function () {
    $owner = User::factory()->create(['account_type' => 'organizer']);
    $organizer = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Limited Staff Org',
        'slug' => 'limited-staff-org',
        'plan_tier' => 'free',
    ]);

    $staff1 = User::factory()->create();
    $staff2 = User::factory()->create();
    $staff3 = User::factory()->create();
    $staff4 = User::factory()->create();

    // Free plan allows up to 3 staff members
    $this->actingAs($owner)->postJson("/api/v1/organizers/{$organizer->uuid}/members", [
        'email' => $staff1->email,
        'role' => 'staff',
    ])->assertSuccessful();

    $this->actingAs($owner)->postJson("/api/v1/organizers/{$organizer->uuid}/members", [
        'email' => $staff2->email,
        'role' => 'staff',
    ])->assertSuccessful();

    $this->actingAs($owner)->postJson("/api/v1/organizers/{$organizer->uuid}/members", [
        'email' => $staff3->email,
        'role' => 'staff',
    ])->assertSuccessful();

    // 4th staff member on Free plan is blocked with 422 PLAN_LIMIT_EXCEEDED
    $this->actingAs($owner)->postJson("/api/v1/organizers/{$organizer->uuid}/members", [
        'email' => $staff4->email,
        'role' => 'staff',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');

    // Creating an invitation also checks staff plan capacity
    $this->actingAs($owner)->postJson("/api/v1/organizers/{$organizer->uuid}/invitations", [
        'email' => 'overlimit@example.com',
        'role' => 'staff',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');

    // Upgrade to Pro -> 4th staff member succeeds
    $organizer->update([
        'plan_tier' => 'pro',
        'plan_expires_at' => now()->addDays(30),
    ]);

    $this->actingAs($owner)->postJson("/api/v1/organizers/{$organizer->uuid}/members", [
        'email' => $staff4->email,
        'role' => 'staff',
    ])->assertSuccessful();
});

test('scheduled subscriptions:process-expired auto-renews funded subscriptions and expires cancelled ones', function () {
    $owner = User::factory()->create();
    $fundedOrg = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Funded Auto-Renew Org',
        'slug' => 'funded-auto-renew-org',
        'plan_tier' => 'pro',
        'plan_expires_at' => now()->subMinute(),
    ]);

    OrganizerSubscription::create([
        'organizer_id' => $fundedOrg->id,
        'plan_slug' => 'pro',
        'billing_cycle' => 'monthly',
        'payment_method' => 'credits',
        'status' => 'active',
        'auto_renew' => true,
        'starts_at' => now()->subDays(30),
        'ends_at' => now()->subMinute(),
    ]);

    /** @var CreditLedgerService $ledger */
    $ledger = app(CreditLedgerService::class);
    $wallet = $ledger->getOrCreateWallet($fundedOrg);
    $ledger->creditWallet(
        wallet: $wallet,
        amount: 2000,
        reason: CreditTxReason::TOPUP,
        idempotencyKey: 'auto_renew_seed',
        reference: 'REF-AUTORENEW',
        description: 'Wallet funding for auto-renewal'
    );

    $unfundedOrg = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Unfunded Org',
        'slug' => 'unfunded-org',
        'plan_tier' => 'pro',
        'plan_expires_at' => now()->subMinute(),
    ]);

    OrganizerSubscription::create([
        'organizer_id' => $unfundedOrg->id,
        'plan_slug' => 'pro',
        'billing_cycle' => 'monthly',
        'payment_method' => 'credits',
        'status' => 'cancelled',
        'auto_renew' => false,
        'starts_at' => now()->subDays(30),
        'ends_at' => now()->subMinute(),
    ]);

    $this->artisan('subscriptions:process-expired')->assertExitCode(0);

    // Funded org was auto-renewed for another 30 days and debited 1,500 credits
    expect($fundedOrg->fresh()->plan_tier)->toBe('pro')
        ->and($fundedOrg->fresh()->plan_expires_at->isFuture())->toBeTrue()
        ->and($wallet->fresh()->balance)->toBe(500);

    // Unfunded/cancelled org reverted to free
    expect($unfundedOrg->fresh()->plan_tier)->toBe('free')
        ->and($unfundedOrg->fresh()->plan_expires_at)->toBeNull();
});

test('order service applies discounted fee from database plan, global fee for expired plan, and admin can edit plan rates dynamically', function () {
    /** @var AppSettingsServiceInterface $settingsService */
    $settingsService = app(AppSettingsServiceInterface::class);
    $settingsService->updateSettings(new AppSettingsDTO(
        sitename: 'EventsOS',
        site_tagline: 'Ticketing',
        ticket_fee_percentage: 5.0,
        ticket_fee_fixed: 100.0,
        platform_fee_percentage: 5.0,
        platform_fee_fixed: 100.0,
        platform_fee_passthrough: true,
    ));

    $owner = User::factory()->create(['account_type' => 'organizer']);
    $organizer = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Festival House',
        'slug' => 'festival-house',
        'plan_tier' => 'pro',
        'plan_expires_at' => now()->addDays(30),
    ]);

    $event = Event::create([
        'organizer_id' => $organizer->id,
        'user_id' => $owner->id,
        'title' => 'Summer Rave',
        'slug' => 'summer-rave',
        'description' => 'Main stage party',
        'event_country' => 'Nigeria',
        'event_type' => EventType::PHYSICAL,
        'event_format' => 'Conference',
        'visibility' => EventVisibility::PUBLIC,
        'status' => EventStatus::PUBLISHED,
    ]);

    $ticketType = TicketType::create([
        'event_id' => $event->id,
        'name' => 'General Admission',
        'type' => 'paid',
        'price' => 10000.00,
        'quantity' => 100,
        'currency' => 'NGN',
    ]);

    /** @var OrderService $orderService */
    $orderService = app(OrderService::class);

    // 1. Active Pro Plan: 2.5% of 10,000 (250) + 50 fixed = 300 NGN fee
    $proOrder = $orderService->createOrder($owner, $event, [
        ['ticket_type_uuid' => $ticketType->uuid, 'quantity' => 1],
    ]);
    expect((float) $proOrder->service_fee)->toBe(300.0)
        ->and((float) $proOrder->total)->toBe(10300.0);

    // 2. Admin dynamically updates the 'pro' plan fee_percentage in database to 2.0% + 0 fixed
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
        'admin_permissions' => ['system_settings.manage', 'organizers.verify'],
    ]);

    $this->actingAs($admin)
        ->putJson('/api/v1/admin/subscription-plans/pro', [
            'fee_percentage' => 2.0,
            'fee_fixed' => 0.0,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.fee_percentage', 2);

    $event->unsetRelation('organizer');
    $updatedProOrder = $orderService->createOrder($owner, $event, [
        ['ticket_type_uuid' => $ticketType->uuid, 'quantity' => 1],
    ]);
    expect((float) $updatedProOrder->service_fee)->toBe(200.0)
        ->and((float) $updatedProOrder->total)->toBe(10200.0);

    // 3. Expired Pro Plan: falls back to global 5% of 10,000 (500) + 100 fixed = 600 NGN fee
    $organizer->update(['plan_expires_at' => now()->subDay()]);
    $event->unsetRelation('organizer');

    $expiredOrder = $orderService->createOrder($owner, $event, [
        ['ticket_type_uuid' => $ticketType->uuid, 'quantity' => 1],
    ]);
    expect((float) $expiredOrder->service_fee)->toBe(600.0)
        ->and((float) $expiredOrder->total)->toBe(10600.0);

    // 4. Admin sets custom negotiated fee override (1.0% + 0 fixed = 100 NGN fee)
    $this->actingAs($admin)
        ->postJson("/api/v1/admin/organizers/{$organizer->uuid}/plan", [
            'plan_tier' => 'enterprise',
            'duration_days' => 90,
            'custom_fee_percentage' => 1.0,
            'custom_fee_fixed' => 0.0,
        ])
        ->assertSuccessful();

    $event->unsetRelation('organizer');
    $customOrder = $orderService->createOrder($owner, $event, [
        ['ticket_type_uuid' => $ticketType->uuid, 'quantity' => 1],
    ]);
    expect((float) $customOrder->service_fee)->toBe(100.0)
        ->and((float) $customOrder->total)->toBe(10100.0);
});
