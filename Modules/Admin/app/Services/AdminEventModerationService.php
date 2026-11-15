<?php

declare(strict_types=1);

namespace Modules\Admin\app\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Models\User;
use Modules\Events\app\Enum\EventStatus;
use Modules\Events\app\Models\Event;
use Modules\Tickets\app\Models\Ticket;

class AdminEventModerationService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Cross-tenant event directory with search and status filtering.
     *
     * @param  array{search?: string, status?: string, category?: string, is_featured?: string, per_page?: int}  $filters
     */
    public function getEvents(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        $query = Event::query()->with([
            'organizer:id,uuid,name,slug',
            'location',
            'schedule',
        ])->withCount(['tickets']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%")
                    ->orWhere('uuid', 'LIKE', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['is_featured'])) {
            $query->where('is_featured', filter_var($filters['is_featured'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Get single event detail for administration.
     */
    public function getEventDetails(string $eventUuid): Event
    {
        return Event::with([
            'organizer',
            'location',
            'schedule',
            'scheduleItems',
            'lineup',
            'ticketTypes',
            'settings',
        ])->withCount(['tickets'])->where('uuid', $eventUuid)->firstOrFail();
    }

    /**
     * Force delist / unpublish an event.
     */
    public function delistEvent(string $eventUuid, User $adminUser, string $reason): Event
    {
        $event = Event::where('uuid', $eventUuid)->firstOrFail();

        $event->update([
            'status' => EventStatus::DRAFT->value,
            'is_featured' => false,
        ]);

        $this->auditService->log(
            action: 'event.moderation_delisted',
            description: "Force-delisted event [{$event->title}] ({$event->uuid}) with reason: {$reason}",
            user: $adminUser,
            targetType: 'Event',
            targetId: $event->uuid,
            reason: $reason,
        );

        return $event;
    }

    /**
     * Force cancel an event.
     */
    public function cancelEvent(string $eventUuid, User $adminUser, string $reason): Event
    {
        $event = Event::where('uuid', $eventUuid)->firstOrFail();

        $event->update([
            'status' => EventStatus::CANCELLED->value,
            'is_featured' => false,
        ]);

        $this->auditService->log(
            action: 'event.moderation_cancelled',
            description: "Force-cancelled event [{$event->title}] ({$event->uuid}) with reason: {$reason}",
            user: $adminUser,
            targetType: 'Event',
            targetId: $event->uuid,
            reason: $reason,
        );

        return $event;
    }

    /**
     * Toggle featured status on explore page.
     */
    public function toggleFeature(string $eventUuid, User $adminUser, ?bool $isFeatured = null): Event
    {
        $event = Event::where('uuid', $eventUuid)->firstOrFail();

        $newState = $isFeatured !== null ? $isFeatured : ! (bool) $event->is_featured;

        $event->update([
            'is_featured' => $newState,
        ]);

        $this->auditService->log(
            action: $newState ? 'event.featured' : 'event.unfeatured',
            description: "Updated featured status for event [{$event->title}] to: ".($newState ? 'FEATURED' : 'STANDARD'),
            user: $adminUser,
            targetType: 'Event',
            targetId: $event->uuid,
        );

        return $event;
    }

    /**
     * Retrieve attendee manifest for emergency or administrative inspection.
     *
     * @param  array{search?: string, per_page?: int}  $filters
     */
    public function getEventAttendees(string $eventUuid, array $filters = []): LengthAwarePaginator
    {
        $event = Event::where('uuid', $eventUuid)->firstOrFail();
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        $query = Ticket::query()
            ->with(['ticketType:id,name,price', 'order:id,order_number,status,total'])
            ->where('event_id', $event->id);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('attendee_name', 'LIKE', "%{$search}%")
                    ->orWhere('attendee_email', 'LIKE', "%{$search}%")
                    ->orWhere('ticket_number', 'LIKE', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }
}
