<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\app\Models\User;
use Modules\Organizer\app\Enum\OrganizerMemberRole;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerMember;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('authenticated user can create an organizer and is assigned as owner', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/organizers', [
        'name' => 'Acme Events Inc',        'description' => 'Leading event organizer',
        'email' => 'contact@acme.com',
        'phone' => '+1234567890',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Acme Events Inc');

    $organizer = Organizer::where('name', 'Acme Events Inc')->first();
    expect($organizer)->not->toBeNull();
    expect($organizer->user_id)->toBe($user->id);

    $member = OrganizerMember::where('organizer_id', $organizer->id)
        ->where('user_id', $user->id)
        ->first();
    expect($member)->not->toBeNull();
    expect($member->role)->toBe(OrganizerMemberRole::OWNER);
});

test('user can list their organizers', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/organizers', [
        'name' => 'First Tech Conference',
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/organizers');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.data');
});

test('owner can update their organizer', function () {
    $user = User::factory()->create();
    $organizer = Organizer::create([
        'user_id' => $user->id,
        'name' => 'Old Name',    ]);

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/organizers/{$organizer->uuid}", [
        'name' => 'Updated Organizer Name',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Updated Organizer Name');
});

test('organizer admin can also update organizer details', function () {
    $owner = User::factory()->create();
    $adminUser = User::factory()->create();

    $organizer = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Initial Name',    ]);

    OrganizerMember::create([
        'organizer_id' => $organizer->id,
        'user_id' => $adminUser->id,
        'role' => OrganizerMemberRole::ADMIN,
    ]);

    $response = $this->actingAs($adminUser, 'sanctum')->putJson("/api/v1/organizers/{$organizer->uuid}", [
        'name' => 'Updated by Admin',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Updated by Admin');
});

test('owner can soft delete their organizer', function () {
    $user = User::factory()->create();
    $organizer = Organizer::create([
        'user_id' => $user->id,
        'name' => 'To Delete',    ]);

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/organizers/{$organizer->uuid}");

    $response->assertStatus(200);
    expect(Organizer::find($organizer->id))->toBeNull();
    expect(Organizer::withTrashed()->find($organizer->id))->not->toBeNull();
});

test('can add member to organizer and update role', function () {
    $owner = User::factory()->create();
    $newMemberUser = User::factory()->create();

    $organizer = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'Team Organization',    ]);

    // Add member
    $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/organizers/{$organizer->uuid}/members", [
        'email' => $newMemberUser->email,
        'role' => OrganizerMemberRole::MANAGER->value,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.role', 'manager');

    $member = OrganizerMember::where('organizer_id', $organizer->id)
        ->where('user_id', $newMemberUser->id)
        ->first();

    // Update member role
    $updateResponse = $this->actingAs($owner, 'sanctum')->putJson("/api/v1/organizers/{$organizer->uuid}/members/{$member->id}", [
        'role' => OrganizerMemberRole::ADMIN->value,
    ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.role', 'admin');

    // Remove member
    $deleteResponse = $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/organizers/{$organizer->uuid}/members/{$member->id}");
    $deleteResponse->assertStatus(200);

    expect(OrganizerMember::find($member->id))->toBeNull();
    expect($newMemberUser->fresh()->account_type)->toBe('customer');
});

test('admin cannot assign admin or owner role to others', function () {
    $owner = User::factory()->create();
    $adminUser = User::factory()->create();
    $newUser = User::factory()->create();

    $organizer = Organizer::create([
        'user_id' => $owner->id,
        'name' => 'RBAC Organization',    ]);

    OrganizerMember::create([
        'organizer_id' => $organizer->id,
        'user_id' => $adminUser->id,
        'role' => OrganizerMemberRole::ADMIN,
    ]);

    // Admin tries to create another Admin (Should fail with 403)
    $response = $this->actingAs($adminUser, 'sanctum')->postJson("/api/v1/organizers/{$organizer->uuid}/members", [
        'email' => $newUser->email,
        'role' => OrganizerMemberRole::ADMIN->value,
    ]);

    $response->assertStatus(403);

    // Admin CAN create a Staff member (Should succeed with 201)
    $staffResponse = $this->actingAs($adminUser, 'sanctum')->postJson("/api/v1/organizers/{$organizer->uuid}/members", [
        'email' => $newUser->email,
        'role' => OrganizerMemberRole::STAFF->value,
    ]);

    $staffResponse->assertStatus(201)
        ->assertJsonPath('data.role', 'staff');

    expect($newUser->fresh()->account_type)->toBe('organizer');
});

test('user cannot belong to multiple organizations at a time and removed member downgrades to attendee and can later convert to organizer', function () {
    $ownerA = User::factory()->create(['account_type' => 'organizer']);
    $orgA = Organizer::create([
        'user_id' => $ownerA->id,
        'name' => 'Alpha Events',
    ]);
    OrganizerMember::create([
        'organizer_id' => $orgA->id,
        'user_id' => $ownerA->id,
        'role' => OrganizerMemberRole::OWNER,
    ]);

    $ownerB = User::factory()->create(['account_type' => 'organizer']);
    $orgB = Organizer::create([
        'user_id' => $ownerB->id,
        'name' => 'Beta Events',
    ]);
    OrganizerMember::create([
        'organizer_id' => $orgB->id,
        'user_id' => $ownerB->id,
        'role' => OrganizerMemberRole::OWNER,
    ]);

    // 1. Owner A cannot create a second organization
    $this->actingAs($ownerA, 'sanctum')
        ->postJson('/api/v1/organizers', ['name' => 'Second Org'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_ALREADY_IN_ORGANIZATION');

    // 2. Add a team member to Org A -> account_type becomes 'organizer'
    $memberUser = User::factory()->create(['account_type' => 'customer']);
    $addResponse = $this->actingAs($ownerA, 'sanctum')
        ->postJson("/api/v1/organizers/{$orgA->uuid}/members", [
            'email' => $memberUser->email,
            'role' => OrganizerMemberRole::MANAGER->value,
        ]);
    $addResponse->assertCreated();
    expect($memberUser->fresh()->account_type)->toBe('organizer');

    // 3. Member of Org A cannot create their own organization while in Org A
    $this->actingAs($memberUser, 'sanctum')
        ->postJson('/api/v1/organizers', ['name' => 'Member Side Org'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_ALREADY_IN_ORGANIZATION');

    // 4. Org B cannot add or invite a user who is already in Org A
    $this->actingAs($ownerB, 'sanctum')
        ->postJson("/api/v1/organizers/{$orgB->uuid}/members", [
            'email' => $memberUser->email,
            'role' => OrganizerMemberRole::STAFF->value,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_ALREADY_IN_ORGANIZATION');

    $this->actingAs($ownerB, 'sanctum')
        ->postJson("/api/v1/organizers/{$orgB->uuid}/invitations", [
            'email' => $memberUser->email,
            'role' => OrganizerMemberRole::STAFF->value,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'USER_ALREADY_IN_ORGANIZATION');

    // 5. Removing member from Org A downgrades their account_type to 'customer' (Attendee)
    $memberRecord = OrganizerMember::where('organizer_id', $orgA->id)
        ->where('user_id', $memberUser->id)
        ->firstOrFail();

    $this->actingAs($ownerA, 'sanctum')
        ->deleteJson("/api/v1/organizers/{$orgA->uuid}/members/{$memberRecord->id}")
        ->assertSuccessful();

    expect($memberUser->fresh()->account_type)->toBe('customer');

    // 6. Now that the user is an Attendee with no org, they can convert their account to an Organizer
    $convertResponse = $this->actingAs($memberUser->fresh(), 'sanctum')
        ->postJson('/api/v1/organizers', [
            'name' => 'Converted Organizer Studio',
            'email' => $memberUser->email,
            'phone' => '+2348012345678',
        ]);

    $convertResponse->assertCreated()
        ->assertJsonPath('data.name', 'Converted Organizer Studio');

    expect($memberUser->fresh()->account_type)->toBe('organizer');
});

