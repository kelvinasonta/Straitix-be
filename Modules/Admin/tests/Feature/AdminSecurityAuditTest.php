<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Audit\app\Models\ActivityLog;
use Modules\Auth\app\Models\User;
use Modules\Organizer\app\Models\Organizer;
use Modules\Payments\app\Models\Payout;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('API1 BOLA: regular organizer cannot access any admin endpoint', function () {
    $organizerUser = User::factory()->create([
        'is_admin' => false,
        'account_type' => 'organizer',
    ]);

    $endpoints = [
        ['GET', '/api/v1/admin/dashboard/stats'],
        ['GET', '/api/v1/admin/organizers'],
        ['GET', '/api/v1/admin/users'],
        ['GET', '/api/v1/admin/events'],
        ['GET', '/api/v1/admin/finance/orders'],
        ['GET', '/api/v1/admin/finance/payouts'],
        ['GET', '/api/v1/admin/audit-logs'],
    ];

    foreach ($endpoints as [$method, $uri]) {
        $response = $this->actingAs($organizerUser)->json($method, $uri);
        $response->assertStatus(403)
            ->assertJsonPath('code', 'ADMIN_ACCESS_REQUIRED');
    }
});

test('API1 BOLA: admin with limited role cannot access privileged admin domains', function () {
    // Support agent only has users.view, tickets.view, etc., NOT payouts.approve or system_settings.manage
    $supportAgent = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'support_agent',
        'admin_permissions' => ['users.view', 'tickets.view'],
    ]);

    $orgUser = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $orgUser->id,
        'name' => 'Org X',
        'slug' => 'org-x',
    ]);
    $payout = Payout::create([
        'organizer_id' => $org->id,
        'requested_by' => $orgUser->id,
        'amount' => 500.00,
        'status' => 'pending',
    ]);

    // Try approving payout without payouts.approve
    $payoutRes = $this->actingAs($supportAgent)->postJson("/api/v1/admin/finance/payouts/{$payout->uuid}/approve");
    $payoutRes->assertStatus(403)
        ->assertJsonPath('code', 'INSUFFICIENT_ADMIN_PERMISSIONS');

    // Try toggling feature flags without system_settings.manage
    $flagRes = $this->actingAs($supportAgent)->postJson('/api/v1/admin/feature-flags/api-rate-limiting/toggle');
    $flagRes->assertStatus(403)
        ->assertJsonPath('code', 'INSUFFICIENT_ADMIN_PERMISSIONS');
});

test('API2: admin login endpoint is strictly rate-limited to 5 attempts per minute', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'random_attacker@bad.test',
            'password' => 'WrongPass',
        ]);
    }

    // 6th attempt must trigger HTTP 429 Too Many Requests
    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'random_attacker@bad.test',
        'password' => 'WrongPass',
    ]);

    $response->assertStatus(429);
});

test('API4 Resource Exhaustion: pagination per_page is capped at 100 max', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
        'admin_permissions' => ['*'],
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/users?per_page=999999');

    $response->assertStatus(200)
        ->assertJsonPath('data.per_page', 100);
});

test('Immutable Audit: state-altering admin actions generate audit logs with before/after actor info', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
        'admin_permissions' => ['*'],
    ]);

    $targetUser = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/v1/admin/users/{$targetUser->uuid}/suspend", [
        'reason' => 'Security policy breach',
    ])->assertStatus(200);

    $auditLog = ActivityLog::where('action', 'user.suspended')
        ->where('target_id', $targetUser->uuid)
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->user_id)->toBe($admin->id)
        ->and($auditLog->reason)->toBe('Security policy breach');
});
