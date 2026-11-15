<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\app\Models\User;
use Modules\Organizer\app\Models\Organizer;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('admin with organizers.view can list organizers with search and pagination', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'kyc_reviewer',
        'admin_permissions' => ['organizers.view'],
    ]);

    $user = User::factory()->create();
    $org1 = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Lagos Music Fest',
        'slug' => 'lagos-music-fest',
    ]);
    $org2 = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Abuja Design Week',
        'slug' => 'abuja-design-week',
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/organizers?search=Lagos');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.name', 'Lagos Music Fest');
});

test('admin can view deep-dive organizer dossier', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'platform_admin',
        'admin_permissions' => ['organizers.view'],
    ]);

    $user = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Tech Safari',
        'slug' => 'tech-safari',
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/admin/organizers/{$org->uuid}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.organizer.name', 'Tech Safari')
        ->assertJsonStructure([
            'data' => [
                'organizer',
                'financials' => ['gmv', 'net_earnings', 'available_balance', 'credit_balance'],
                'recent_events',
                'members',
            ],
        ]);
});

test('admin with organizers.verify can suspend and unsuspend organizer', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'platform_admin',
        'admin_permissions' => ['organizers.verify'],
    ]);

    $user = User::factory()->create();
    $org = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Suspect Organizer',
        'slug' => 'suspect-organizer',
    ]);

    // Suspend
    $suspendRes = $this->actingAs($admin)->postJson("/api/v1/admin/organizers/{$org->uuid}/suspend", [
        'reason' => 'Suspected terms of service violation',
    ]);

    $suspendRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.suspension_reason', 'Suspected terms of service violation');

    expect($org->fresh()->isSuspended())->toBeTrue();

    // Unsuspend
    $unsuspendRes = $this->actingAs($admin)->postJson("/api/v1/admin/organizers/{$org->uuid}/unsuspend");

    $unsuspendRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.suspension_reason', null);

    expect($org->fresh()->isSuspended())->toBeFalse();
});
