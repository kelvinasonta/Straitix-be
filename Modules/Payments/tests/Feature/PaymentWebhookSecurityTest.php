<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('paystack payment webhook fails closed when secret key is empty', function () {
    Config::set('paystack.secret_key', null);

    $response = $this->postJson('/api/v1/webhooks/payments/paystack', [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST-REF-999',
        ],
    ], [
        'x-paystack-signature' => 'any-signature',
    ]);

    $response->assertStatus(500)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Webhook verification unavailable.');
});

test('paystack payment webhook rejects invalid signature when secret key is set', function () {
    Config::set('paystack.secret_key', 'test_secret_key_12345');

    $response = $this->postJson('/api/v1/webhooks/payments/paystack', [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'TEST-REF-999',
        ],
    ], [
        'x-paystack-signature' => 'invalid_forged_signature',
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Invalid signature.');
});

test('paystack payment webhook accepts valid HMAC SHA512 signature', function () {
    $secret = 'test_secret_key_12345';
    Config::set('paystack.secret_key', $secret);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'NON_EXISTENT_REF',
        ],
    ];

    $rawContent = json_encode($payload);
    $validSignature = hash_hmac('sha512', $rawContent, $secret);

    $response = $this->call(
        'POST',
        '/api/v1/webhooks/payments/paystack',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $validSignature,
        ],
        $rawContent
    );

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');
});
