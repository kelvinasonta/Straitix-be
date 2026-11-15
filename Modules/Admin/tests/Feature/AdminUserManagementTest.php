<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\app\Enum\UserStatus;
use Modules\Auth\app\Models\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('admin with users.view can list and search users', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'support_agent',
        'admin_permissions' => ['users.view'],
    ]);

    User::factory()->create([
        'first_name' => 'Chioma',
        'last_name' => 'Okeke',
        'email' => 'chioma@example.com',
    ]);
    User::factory()->create([
        'first_name' => 'Emeka',
        'last_name' => 'Adeyemi',
        'email' => 'emeka@example.com',
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/users?search=Chioma');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.email', 'chioma@example.com');
});

test('admin can inspect user details with active sessions count', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'platform_admin',
        'admin_permissions' => ['users.view'],
    ]);

    $targetUser = User::factory()->create();
    $targetUser->createToken('device-1');
    $targetUser->createToken('device-2');

    $response = $this->actingAs($admin)->getJson("/api/v1/admin/users/{$targetUser->uuid}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', $targetUser->email)
        ->assertJsonPath('data.active_sessions_count', 2);
});

test('admin with users.suspend can suspend user and revoke tokens', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'trust_safety',
        'admin_permissions' => ['users.suspend'],
    ]);

    $targetUser = User::factory()->create([
        'status' => UserStatus::ACTIVE,
    ]);
    $targetUser->createToken('mobile-session');

    expect($targetUser->tokens()->count())->toBe(1);

    $suspendRes = $this->actingAs($admin)->postJson("/api/v1/admin/users/{$targetUser->uuid}/suspend", [
        'reason' => 'Fraudulent ticket resale activity',
    ]);

    $suspendRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('data.suspension_reason', 'Fraudulent ticket resale activity');

    expect($targetUser->fresh()->tokens()->count())->toBe(0)
        ->and($targetUser->fresh()->suspended_at)->not->toBeNull();

    // Unsuspend
    $unsuspendRes = $this->actingAs($admin)->postJson("/api/v1/admin/users/{$targetUser->uuid}/unsuspend");

    $unsuspendRes->assertStatus(200)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.suspension_reason', null);

    expect($targetUser->fresh()->suspended_at)->toBeNull();
});

test('admin can force-revoke all active sessions of a user', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'trust_safety',
        'admin_permissions' => ['users.suspend'],
    ]);

    $targetUser = User::factory()->create();
    $targetUser->createToken('web');
    $targetUser->createToken('tablet');

    $response = $this->actingAs($admin)->postJson("/api/v1/admin/users/{$targetUser->uuid}/revoke-sessions");

    $response->assertStatus(200)
        ->assertJsonPath('data.revoked_count', 2);

    expect($targetUser->tokens()->count())->toBe(0);
});

test('admin can manually verify user email address', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'support_agent',
        'admin_permissions' => ['users.suspend'],
    ]);

    $targetUser = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/v1/admin/users/{$targetUser->uuid}/verify-email");

    $response->assertStatus(200);
    expect($targetUser->fresh()->email_verified_at)->not->toBeNull();
});
