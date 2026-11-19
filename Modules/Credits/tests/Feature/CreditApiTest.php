<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Modules\Auth\app\Models\User;
use Modules\Credits\app\Enums\CreditTxReason;
use Modules\Credits\app\Services\CreditLedgerService;
use Modules\Credits\app\Services\CreditTopupService;
use Modules\Organizer\app\Enum\OrganizerMemberRole;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerMember;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['account_type' => 'organizer']);
    $this->adminUser = User::factory()->create(['account_type' => 'organizer']);
    $this->managerUser = User::factory()->create(['account_type' => 'organizer']);
    $this->outsider = User::factory()->create(['account_type' => 'organizer']);

    $this->organizer = Organizer::create([
        'user_id' => $this->owner->id,
        'name' => 'API Test Organizer',    ]);

    OrganizerMember::create([
        'organizer_id' => $this->organizer->id,
        'user_id' => $this->adminUser->id,
        'role' => OrganizerMemberRole::ADMIN,
    ]);

    OrganizerMember::create([
        'organizer_id' => $this->organizer->id,
        'user_id' => $this->managerUser->id,
        'role' => OrganizerMemberRole::MANAGER,
    ]);

    $this->ledgerService = app(CreditLedgerService::class);
    $this->topupService = app(CreditTopupService::class);
});

test('unauthenticated request to wallet returns 401', function () {
    $response = $this->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/wallet");

    $response->assertStatus(401);
});

test('unauthorized user cannot access wallet', function () {
    $response = $this->actingAs($this->outsider)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/wallet");

    $response->assertStatus(403);
});

test('owner, admin, and manager can get wallet details', function () {
    $wallet = $this->ledgerService->getOrCreateWallet($this->organizer);
    $this->ledgerService->creditWallet(
        wallet: $wallet,
        amount: 250,
        reason: CreditTxReason::TOPUP,
        idempotencyKey: 'api_topup_1',
        reference: 'REF-API-1',
        description: 'Initial wallet balance'
    );

    // Reserve 50
    $this->ledgerService->reserveCredits($wallet, 50, 'BROADCAST', 'B-1');

    foreach ([$this->owner, $this->adminUser, $this->managerUser] as $user) {
        $response = $this->actingAs($user)
            ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/wallet");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance', 250)
            ->assertJsonPath('data.reserved_balance', 50)
            ->assertJsonPath('data.available_balance', 200)
            ->assertJsonPath('data.currency', 'NGN');
    }
});

test('organizer can fetch paginated ledger history with filtering', function () {
    $wallet = $this->ledgerService->getOrCreateWallet($this->organizer);

    // Create 3 ledger entries: 2 topups, 1 debit
    $this->ledgerService->creditWallet(
        wallet: $wallet,
        amount: 100,
        reason: CreditTxReason::TOPUP,
        idempotencyKey: 'ledger_entry_1',
        reference: 'REF-1',
        description: 'First topup'
    );

    $this->ledgerService->creditWallet(
        wallet: $wallet,
        amount: 200,
        reason: CreditTxReason::TOPUP,
        idempotencyKey: 'ledger_entry_2',
        reference: 'REF-2',
        description: 'Second topup'
    );

    $this->ledgerService->debitCreditsSync(
        wallet: $wallet,
        amount: 50,
        reason: CreditTxReason::EMAIL_BROADCAST,
        idempotencyKey: 'ledger_entry_3',
        reference: 'REF-3',
        description: 'Debit for email'
    );

    // Default fetch
    $response = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/ledger");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3);

    // Filter by reason=TOPUP
    $filteredResponse = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/ledger?reason=TOPUP");

    $filteredResponse->assertStatus(200)
        ->assertJsonCount(2, 'data');

    // Filter by type=DEBIT
    $debitResponse = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/ledger?type=DEBIT");

    $debitResponse->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 50);
});

test('organizer can initialize top-up and obtain payment reference', function () {
    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/credits/top-up/initialize", [
            'credits' => 50,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.credits', 50)
        ->assertJsonPath('data.amount_kobo', 50000)
        ->assertJsonPath('data.currency', 'NGN');

    expect($response->json('data.reference'))->toBeString()->toStartWith('CREDIT-');
    expect($response->json('data.checkout_url'))->toBeString();
});

test('organizer can trigger wallet reconcile endpoint', function () {
    $wallet = $this->ledgerService->getOrCreateWallet($this->organizer);
    $this->ledgerService->creditWallet(
        wallet: $wallet,
        amount: 300,
        reason: CreditTxReason::TOPUP,
        idempotencyKey: 'recon_api_topup',
        reference: 'REF-RECON',
        description: 'Reconcile test'
    );

    $response = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/reconcile");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.stored_balance', 300)
        ->assertJsonPath('data.computed_balance', 300)
        ->assertJsonPath('data.difference', 0);

    // Tamper with balance
    $wallet->update(['balance' => 9999]);

    $tamperedResponse = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/reconcile");

    $tamperedResponse->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'CREDIT_RECONCILIATION_MISMATCH');
});

test('public paystack webhook verifies signature and credits wallet', function () {
    $secret = 'test_paystack_secret_key_abcdef';
    Config::set('paystack.secret_key', $secret);

    $wallet = $this->ledgerService->getOrCreateWallet($this->organizer);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'PAYSTACK_WEBHOOK_REF_100',
            'amount' => 50000, // 500 NGN = 50 credits
            'currency' => 'NGN',
            'metadata' => [
                'wallet_id' => $wallet->id,
                'organizer_id' => $this->organizer->id,
                'credits_purchased' => 50,
            ],
        ],
    ];

    $rawJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha512', $rawJson, $secret);

    $response = $this->withHeaders([
        'x-paystack-signature' => $signature,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/credits/webhooks/paystack', $payload);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('credits_awarded', 50);

    $wallet->refresh();
    expect($wallet->balance)->toBe(50);
});

test('public paystack webhook rejects invalid signature', function () {
    Config::set('paystack.secret_key', 'test_paystack_secret_key_abcdef');

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'PAYSTACK_REF_FAKE',
            'amount' => 10000,
        ],
    ];

    $response = $this->withHeaders([
        'x-paystack-signature' => 'invalid_signature_hash',
    ])->postJson('/api/v1/credits/webhooks/paystack', $payload);

    $response->assertStatus(400);
});

test('organizer can retrieve active credit packages from /credits/packages and /credits/gateways', function () {
    $packagesRes = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/packages");

    $packagesRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', 'starter')
        ->assertJsonPath('data.0.credits', 100)
        ->assertJsonPath('data.0.price_kobo', 100000)
        ->assertJsonPath('data.1.id', 'growth')
        ->assertJsonPath('data.1.credits', 500)
        ->assertJsonPath('data.1.price_kobo', 450000)
        ->assertJsonPath('data.1.discount_label', 'Save 10%')
        ->assertJsonPath('data.1.is_popular', true)
        ->assertJsonPath('data.2.id', 'power')
        ->assertJsonPath('data.2.credits', 1500)
        ->assertJsonPath('data.2.price_kobo', 1200000)
        ->assertJsonPath('data.2.discount_label', 'Save 20%');

    $gatewaysRes = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/gateways");

    $gatewaysRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.rate_per_credit_kobo', 1000)
        ->assertJsonCount(3, 'data.packages');
});

test('initializing top-up with package_id enforces server package pricing and verify awards exact package credits', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*/transaction/initialize' => \Illuminate\Support\Facades\Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test_power_pack',
                'access_code' => 'acc_power_123',
                'reference' => 'CREDIT-PSTK-POWER-1500',
            ],
        ], 200),
        '*/transaction/verify/*' => \Illuminate\Support\Facades\Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'reference' => 'CREDIT-PSTK-POWER-1500',
                'amount' => 1200000,
                'currency' => 'NGN',
            ],
        ], 200),
    ]);

    // Pass package_id = 'power' (1500 credits for 1,200,000 kobo) even with a forged client credits value
    $initRes = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/credits/top-up/initialize", [
            'package_id' => 'power',
            'credits' => 999999,
        ]);

    $initRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.credits', 1500)
        ->assertJsonPath('data.amount_kobo', 1200000);

    $reference = $initRes->json('data.reference');
    expect($reference)->toBe('CREDIT-PSTK-POWER-1500');

    // Verify the reference and ensure the exact 1,500 credits recorded on the initialized transaction are awarded
    // (Notice the mock verify response above omitted metadata.credits_requested, proving verifyAndSettle uses the authoritative DB record!)
    $verifyRes = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/credits/top-up/verify/{$reference}");

    $verifyRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.credits_awarded', 1500)
        ->assertJsonPath('data.balance', 1500);
});

test('initializing top-up with invalid package_id returns 422', function () {
    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/credits/top-up/initialize", [
            'package_id' => 'non_existent_pack',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});
