<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{uuid}', function ($user, string $uuid): bool {
    return (string) $user->uuid === $uuid;
});

Broadcast::channel('orders.{orderUuid}', function ($user, string $orderUuid): bool {
    $order = \Modules\Payments\app\Models\Order::where('uuid', $orderUuid)->first();
    if (! $order) {
        return false;
    }

    if ($order->user_id !== null && (int) $order->user_id === (int) $user->id) {
        return true;
    }

    return $order->customer_email !== null
        && strtolower((string) $order->customer_email) === strtolower((string) $user->email);
});

Broadcast::channel('organizers.{organizerUuid}.jobs', function ($user, string $organizerUuid): bool {
    $organizer = \Modules\Organizer\app\Models\Organizer::where('uuid', $organizerUuid)->first();
    if (! $organizer) {
        return false;
    }

    return (int) $organizer->user_id === (int) $user->id
        || $organizer->members()->where('user_id', $user->id)->exists();
});

Broadcast::channel('events.{eventUuid}.gate', function ($user, string $eventUuid): bool {
    $event = \Modules\Events\app\Models\Event::with('organizer')->where('uuid', $eventUuid)->first();
    if (! $event || ! $event->organizer) {
        return false;
    }

    $organizer = $event->organizer;

    return (int) $organizer->user_id === (int) $user->id
        || $organizer->members()->where('user_id', $user->id)->exists();
});

Broadcast::channel('App.Models.User.{id}', function ($user, $id): bool {
    return (int) $user->id === (int) $id;
});
