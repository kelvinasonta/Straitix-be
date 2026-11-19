<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\app\Models\User;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerBankAccounts;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organizer = Organizer::create([
        'user_id' => $this->owner->id,
        'name' => 'Premier Events Global',
    ]);
});

test('organizer owner can store and decrypt settlement bank account with encrypted fields', function () {
    $payload = [
        'bank_name' => 'Zenith Bank',
        'bank_code' => '057',
        'account_number' => '0123456789',
        'account_name' => 'Premier Events Global Ltd',
    ];

    // 1. Post to save bank account
    $postResponse = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/bank-account", $payload);

    $postResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.bank_name', 'Zenith Bank')
        ->assertJsonPath('data.bank_code', '057')
        ->assertJsonPath('data.account_number', '0123456789')
        ->assertJsonPath('data.account_name', 'Premier Events Global Ltd')
        ->assertJsonPath('data.is_default', true);

    // 2. Verify raw database record is encrypted and longer than plain text length
    $rawRecord = DB::table('organizer_bank_accounts')
        ->where('organizer_id', $this->organizer->id)
        ->first();

    expect($rawRecord)->not->toBeNull()
        ->and($rawRecord->account_number)->not->toBe('0123456789')
        ->and(strlen($rawRecord->account_number))->toBeGreaterThan(50)
        ->and($rawRecord->bank_name)->not->toBe('Zenith Bank')
        ->and($rawRecord->account_name)->not->toBe('Premier Events Global Ltd');

    // 3. Retrieve via GET endpoint to ensure clean decryption
    $getResponse = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/bank-account");

    $getResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.bank_name', 'Zenith Bank')
        ->assertJsonPath('data.bank_code', '057')
        ->assertJsonPath('data.account_number', '0123456789')
        ->assertJsonPath('data.account_name', 'Premier Events Global Ltd')
        ->assertJsonPath('data.is_default', true);
});

test('updating organizer bank account replaces details cleanly and handles encrypted fields', function () {
    OrganizerBankAccounts::create([
        'organizer_id' => $this->organizer->id,
        'bank_name' => 'Access Bank',
        'bank_code' => '044',
        'account_number' => '9876543210',
        'account_name' => 'Old Account Name',
        'is_default' => true,
    ]);

    $updatedPayload = [
        'bank_name' => 'Guaranty Trust Bank (GTBank)',
        'bank_code' => '058',
        'account_number' => '1122334455',
        'account_name' => 'Premier Events Global Updated',
    ];

    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/organizers/{$this->organizer->uuid}/bank-account", $updatedPayload);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.bank_name', 'Guaranty Trust Bank (GTBank)')
        ->assertJsonPath('data.account_number', '1122334455')
        ->assertJsonPath('data.account_name', 'Premier Events Global Updated');

    $getResponse = $this->actingAs($this->owner)
        ->getJson("/api/v1/organizers/{$this->organizer->uuid}/bank-account");

    $getResponse->assertStatus(200)
        ->assertJsonPath('data.bank_name', 'Guaranty Trust Bank (GTBank)')
        ->assertJsonPath('data.account_number', '1122334455')
        ->assertJsonPath('data.account_name', 'Premier Events Global Updated');
});
