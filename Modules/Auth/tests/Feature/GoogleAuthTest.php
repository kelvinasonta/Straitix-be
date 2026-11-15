<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\Auth\app\Models\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('guest can fetch google redirect url', function () {
    $response = $this->getJson('/api/v1/auth/google/redirect');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'message',
            'data' => ['url'],
        ]);

    $url = $response->json('data.url');
    expect($url)->toContain('accounts.google.com')
        ->and($url)->toContain('client_id=583225403762');
});

test('new user can authenticate via google callback and account is created', function () {
    $abstractUser = Mockery::mock(SocialiteUser::class);
    $abstractUser->shouldReceive('getId')->andReturn('google-unique-id-12345');
    $abstractUser->shouldReceive('getEmail')->andReturn('newgoogleuser@example.com');
    $abstractUser->shouldReceive('getName')->andReturn('Alex Morgan');
    $abstractUser->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/avatar/alex.jpg');

    $provider = Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($abstractUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->postJson('/api/v1/auth/google/callback', [
        'code' => 'mock-google-auth-code',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_new_user', true)
        ->assertJsonStructure([
            'message',
            'data' => [
                'token',
                'user' => ['id', 'email', 'firstName', 'lastName', 'isEmailVerified'],
                'is_new_user',
            ],
        ]);

    $user = User::where('email', 'newgoogleuser@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->google_id)->toBe('google-unique-id-12345')
        ->and($user->first_name)->toBe('Alex')
        ->and($user->last_name)->toBe('Morgan')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('existing user can authenticate via google and links google_id', function () {
    $existing = User::factory()->create([
        'email' => 'existinggoogle@example.com',
        'google_id' => null,
        'email_verified_at' => null,
    ]);

    $abstractUser = Mockery::mock(SocialiteUser::class);
    $abstractUser->shouldReceive('getId')->andReturn('google-id-existing-999');
    $abstractUser->shouldReceive('getEmail')->andReturn('existinggoogle@example.com');
    $abstractUser->shouldReceive('getName')->andReturn('Existing User');
    $abstractUser->shouldReceive('getAvatar')->andReturn(null);

    $provider = Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($abstractUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->postJson('/api/v1/auth/callback/google', [
        'code' => 'mock-google-auth-code-existing',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_new_user', false);

    $refreshed = $existing->fresh();
    expect($refreshed->google_id)->toBe('google-id-existing-999')
        ->and($refreshed->email_verified_at)->not->toBeNull();
});

test('google redirect requires captcha when recaptcha is active', function () {
    $settingsService = app(\Modules\Shared\app\Contracts\AppSettingsServiceInterface::class);
    $settings = $settingsService->getAll()->toArray();
    $settings['auth_captcha_provider'] = 'recaptcha';
    $settings['recaptcha_secret_key'] = 'test-secret';
    $settingsService->updateSettings(\Modules\Shared\app\DTOs\AppSettingsDTO::fromArray($settings));

    // Missing token should fail validation
    $response = $this->getJson('/api/v1/auth/google/redirect');
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['captcha_token']);

    // Mock HTTP responses
    \Illuminate\Support\Facades\Http::fake([
        'https://www.google.com/recaptcha/api/siteverify*' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['success' => false], 200)
            ->push(['success' => true], 200),
    ]);

    $failResponse = $this->postJson('/api/v1/auth/google/redirect', [
        'captcha_token' => 'invalid-token',
    ]);
    $failResponse->assertStatus(422)
        ->assertJsonValidationErrors(['captcha_token']);

    $successResponse = $this->postJson('/api/v1/auth/google/redirect', [
        'captcha_token' => 'valid-token',
    ]);
    $successResponse->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('google redirect requires captcha when cloudflare turnstile is active', function () {
    $settingsService = app(\Modules\Shared\app\Contracts\AppSettingsServiceInterface::class);
    $settings = $settingsService->getAll()->toArray();
    $settings['auth_captcha_provider'] = 'turnstile';
    $settings['cloudflare_turnstile_secret_key'] = 'turnstile-secret';
    $settingsService->updateSettings(\Modules\Shared\app\DTOs\AppSettingsDTO::fromArray($settings));

    // Missing token should fail validation
    $response = $this->getJson('/api/v1/auth/google/redirect');
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['captcha_token']);

    // Mock HTTP success
    \Illuminate\Support\Facades\Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
    ]);

    $successResponse = $this->postJson('/api/v1/auth/google/redirect', [
        'captcha_token' => 'valid-turnstile-token',
    ]);
    $successResponse->assertStatus(200)
        ->assertJsonPath('success', true);
});

