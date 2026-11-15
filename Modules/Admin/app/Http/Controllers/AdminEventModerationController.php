<?php

declare(strict_types=1);

namespace Modules\Admin\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\app\Services\AdminEventModerationService;

class AdminEventModerationController extends Controller
{
    public function __construct(
        protected AdminEventModerationService $eventService
    ) {}

    /**
     * Cross-tenant event directory.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'category', 'is_featured', 'per_page']);
        $events = $this->eventService->getEvents($filters);

        return ApiResponse::success(
            'Events retrieved successfully.',
            $events
        );
    }

    /**
     * Single event administrative detail view.
     */
    public function show(string $eventUuid): JsonResponse
    {
        $event = $this->eventService->getEventDetails($eventUuid);

        return ApiResponse::success(
            'Event details retrieved successfully.',
            $event
        );
    }

    /**
     * Force-delist an event.
     */
    public function delist(Request $request, string $eventUuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $event = $this->eventService->delistEvent(
            $eventUuid,
            $request->user(),
            $validated['reason']
        );

        return ApiResponse::success(
            'Event delisted successfully.',
            $event
        );
    }

    /**
     * Force-cancel an event.
     */
    public function cancel(Request $request, string $eventUuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $event = $this->eventService->cancelEvent(
            $eventUuid,
            $request->user(),
            $validated['reason']
        );

        return ApiResponse::success(
            'Event cancelled successfully.',
            $event
        );
    }

    /**
     * Toggle featured status on explore page.
     */
    public function feature(Request $request, string $eventUuid): JsonResponse
    {
        $validated = $request->validate([
            'is_featured' => ['nullable', 'boolean'],
        ]);

        $event = $this->eventService->toggleFeature(
            $eventUuid,
            $request->user(),
            $validated['is_featured'] ?? null
        );

        return ApiResponse::success(
            'Event featured status updated successfully.',
            $event
        );
    }

    /**
     * Get attendee manifest for an event.
     */
    public function attendees(Request $request, string $eventUuid): JsonResponse
    {
        $filters = $request->only(['search', 'per_page']);
        $attendees = $this->eventService->getEventAttendees($eventUuid, $filters);

        return ApiResponse::success(
            'Event attendees retrieved successfully.',
            $attendees
        );
    }
}
