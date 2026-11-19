<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\app\Models\User;
use Modules\Notification\app\Channels\EmailChannel;
use Modules\Notification\app\Channels\InAppChannel;
use Modules\Notification\app\DTOs\NotificationPayload;
use Modules\Notification\app\Models\InAppNotification;
use Modules\Notification\app\Models\NotificationPreference;
use Modules\Notification\app\Services\InAppNotificationService;
use Modules\Notification\app\Services\MailerService;
use Modules\Notification\app\Services\NotificationManager;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('delivers in-app and email notifications via NotificationManager', function () {
    $user = User::factory()->create([
        'email' => 'attendee@example.com',
        'first_name' => 'John',
    ]);

    $mailerMock = Mockery::mock(MailerService::class);
    $mailerMock->shouldReceive('send')
        ->once()
        ->with('attendee@example.com', 'welcome', Mockery::any())
        ->andReturn(true);

    $emailChannel = new EmailChannel($mailerMock);
    $inAppChannel = new InAppChannel;
    $manager = new NotificationManager($inAppChannel, $emailChannel);

    $payload = NotificationPayload::make([
        'type' => 'auth.welcome',
        'category' => 'account',
        'title' => 'Welcome to Strayt Tix',
        'message' => 'Your account is active.',
        'action_url' => 'https://strayttix.vercel.app/dashboard',
        'icon' => 'check-circle',
        'email_template_slug' => 'welcome',
        'data' => ['name' => 'John'],
        'channels' => ['in_app', 'email'],
    ]);

    $results = $manager->send($user, null, $payload);

    expect($results['in_app'])->toBeTrue()
        ->and($results['email'])->toBeTrue();

    expect(InAppNotification::where('user_id', $user->id)->count())->toBe(1);
    $dbNotification = InAppNotification::where('user_id', $user->id)->first();
    expect($dbNotification->title)->toBe('Welcome to Strayt Tix')
        ->and($dbNotification->action_url)->toBe('https://strayttix.vercel.app/dashboard');
});

it('respects user opt-out preferences for non-mandatory notifications', function () {
    $user = User::factory()->create(['email' => 'busy@example.com']);

    // User opted out of marketing emails
    NotificationPreference::create([
        'user_id' => $user->id,
        'category' => 'marketing',
        'channel' => 'email',
        'is_enabled' => false,
    ]);

    $mailerMock = Mockery::mock(MailerService::class);
    $mailerMock->shouldNotReceive('send');

    $emailChannel = new EmailChannel($mailerMock);
    $inAppChannel = new InAppChannel;
    $manager = new NotificationManager($inAppChannel, $emailChannel);

    $payload = NotificationPayload::make([
        'type' => 'marketing.newsletter',
        'category' => 'marketing',
        'title' => 'Upcoming Weekend Events',
        'message' => 'Check out events this weekend',
        'email_template_slug' => 'weekly_digest',
        'channels' => ['email', 'in_app'],
        'is_mandatory' => false,
    ]);

    $results = $manager->send($user, null, $payload);

    expect($results['email'])->toBeFalse()
        ->and($results['in_app'])->toBeTrue();
});

it('allows user to query and mark notifications as read via API', function () {
    $user = User::factory()->create();

    $notification = InAppNotification::create([
        'user_id' => $user->id,
        'type' => 'order.paid',
        'title' => 'Ticket Purchased',
        'message' => 'Your order #1234 has been confirmed.',
        'action_url' => '/tickets/1234',
    ]);

    $this->actingAs($user, 'sanctum');

    // 1. Get notifications
    $response = $this->getJson('/api/v1/notifications');
    $response->assertOk()
        ->assertJsonPath('data.unread_count', 1)
        ->assertJsonCount(1, 'data.notifications');

    // 2. Mark as read
    $patchResponse = $this->patchJson("/api/v1/notifications/{$notification->uuid}/read");
    $patchResponse->assertOk();

    // 3. Verify unread count is 0
    $countResponse = $this->getJson('/api/v1/notifications/unread-count');
    $countResponse->assertOk()
        ->assertJsonPath('data.unread_count', 0);
});

it('saves and updates notification preferences', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $response = $this->putJson('/api/v1/notifications/preferences', [
        'preferences' => [
            'orders' => [
                'email' => true,
                'in_app' => true,
                'sms' => false,
            ],
            'marketing' => [
                'email' => false,
                'in_app' => false,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.preferences.orders.sms', false)
        ->assertJsonPath('data.preferences.marketing.email', false);

    expect(NotificationPreference::where('user_id', $user->id)->count())->toBe(5);
});

it('broadcasts InAppNotificationCreated on private user channel and authorizes sanctum users', function () {
    \Illuminate\Support\Facades\Config::set('broadcasting.default', 'reverb');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.key', 'test-key');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.secret', 'test-secret');
    \Illuminate\Support\Facades\Config::set('broadcasting.connections.reverb.app_id', 'test-app');
    \Illuminate\Support\Facades\Broadcast::purge();
    require base_path('routes/channels.php');

    \Illuminate\Support\Facades\Event::fake([\Modules\Notification\app\Events\InAppNotificationCreated::class]);

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $notification = InAppNotification::create([
        'user_id' => $user->id,
        'type' => 'order.paid',
        'title' => 'Ticket Purchased',
        'message' => 'Your ticket order was confirmed.',
        'action_url' => '/dashboard/tickets',
        'icon' => 'ticket',
    ]);

    \Illuminate\Support\Facades\Event::assertDispatched(
        \Modules\Notification\app\Events\InAppNotificationCreated::class,
        function ($event) use ($user, $notification) {
            return $event->userUuid === (string) $user->uuid
                && $event->notification->id === $notification->id
                && $event->broadcastAs() === 'notification.created'
                && $event->broadcastOn()[0]->name === "private-users.{$user->uuid}";
        }
    );

    // Ensure /api/v1/broadcasting/auth authorizes the owner of the private channel and denies other users
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-users.{$user->uuid}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs($otherUser, 'sanctum')
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-users.{$user->uuid}",
        ])
        ->assertStatus(403);
});
