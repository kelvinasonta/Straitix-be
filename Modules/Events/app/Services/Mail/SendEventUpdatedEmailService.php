<?php

declare(strict_types=1);

namespace Modules\Events\app\Services\Mail;

use Illuminate\Support\Carbon;
use Modules\Auth\app\Models\User;
use Modules\Events\app\Models\Event;
use Modules\Notification\app\Services\MailerService;
use Modules\Organizer\app\Models\Organizer;

class SendEventUpdatedEmailService
{
    public function __construct(
        private MailerService $mailerService
    ) {}

    /**
     * Send email notifications on event update to registered ticket holders/attendees
     * ONLY when material event details (date, time, venue/location, or status) change.
     *
     * @param  array<string, mixed>  $changes
     */
    public function execute(Event $event, Organizer $organizer, User $editor, array $changes = []): void
    {
        // Option B: Only notify attendees if material details changed (date, time, venue/address, or status)
        if (! $this->hasMaterialChanges($changes)) {
            return;
        }

        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');

        $schedule = $event->relationLoaded('schedule') ? $event->schedule : $event->schedule()->first();
        $location = $event->relationLoaded('location') ? $event->location : $event->location()->first();

        $startDate = 'TBA';
        if (! empty($schedule?->start_date)) {
            try {
                $time = ! empty($schedule->start_time) ? ' '.$schedule->start_time : '';
                $startDate = Carbon::parse($schedule->start_date.$time)->format('F j, Y g:i A');
            } catch (\Throwable) {
                $startDate = (string) $schedule->start_date;
            }
        }
        $locationName = $location?->name ?? $location?->address ?? ($event->format?->value === 'online' ? 'Online Event' : 'TBA');

        $changeSummary = $this->buildChangeSummary($changes);

        // Notify confirmed ticket holders / attendees of the event update
        $attendeeTickets = $event->tickets()
            ->whereIn('status', ['valid', 'Valid'])
            ->get();

        $notifiedEmails = [];

        foreach ($attendeeTickets as $ticket) {
            $attendeeEmail = $ticket->attendee_email;
            if (empty($attendeeEmail) && $ticket->user_id) {
                $attendeeEmail = $ticket->user?->email;
            }

            if (empty($attendeeEmail) || in_array(strtolower(trim($attendeeEmail)), $notifiedEmails, true)) {
                continue;
            }

            $attendeeName = $ticket->attendee_name ?: ($ticket->user?->name ?: 'Valued Attendee');
            $cleanEmail = strtolower(trim($attendeeEmail));
            $notifiedEmails[] = $cleanEmail;

            $attendeePayload = [
                'name' => $attendeeName,
                'attendeeName' => $attendeeName,
                'eventTitle' => $event->title,
                'organizerName' => $organizer->name,
                'eventStartDate' => $startDate,
                'eventLocation' => $locationName,
                'changeSummary' => $changeSummary,
                'eventPublicLink' => "{$frontendUrl}/events/{$event->slug}",
                'ticketViewLink' => "{$frontendUrl}/tickets/{$ticket->uuid}",
                'siteName' => config('app.name', 'EventsOS'),
            ];

            try {
                $this->mailerService->send($cleanEmail, 'event_updated_attendee', $attendeePayload);
            } catch (\Throwable $e) {
                logger()->error("Failed sending event_updated_attendee email to [{$cleanEmail}]: ".$e->getMessage());
            }
        }
    }

    /**
     * Determine if the update contains material changes that warrant notifying attendees.
     * Material changes: schedule (start_date, start_time, end_date), location (venue, address, city), or event status.
     *
     * @param  array<string, mixed>  $changes
     */
    public function hasMaterialChanges(array $changes): bool
    {
        // 1. Schedule changes (date or time)
        if (isset($changes['schedule']) && is_array($changes['schedule'])) {
            $scheduleKeys = array_keys($changes['schedule']);
            $materialScheduleKeys = ['start_date', 'start_time', 'end_date', 'end_time', 'schedule_timezone'];
            if (! empty(array_intersect($scheduleKeys, $materialScheduleKeys))) {
                return true;
            }
        }

        // 2. Location changes (venue or address)
        if (isset($changes['location']) && is_array($changes['location'])) {
            $locationKeys = array_keys($changes['location']);
            $materialLocationKeys = ['venue_name', 'venue_address', 'street_address', 'city', 'state', 'online_meeting_url'];
            if (! empty(array_intersect($locationKeys, $materialLocationKeys))) {
                return true;
            }
        }

        // 3. Status changes (e.g. postponed, cancelled, rescheduled)
        if (isset($changes['status'])) {
            return true;
        }

        return false;
    }

    /**
     * Build readable change summary bullet points from changes array.
     *
     * @param  array<string, mixed>  $changes
     */
    private function buildChangeSummary(array $changes): string
    {
        if (empty($changes)) {
            return 'Event details were updated.';
        }

        $items = [];
        foreach ($changes as $key => $val) {
            $label = ucfirst(str_replace('_', ' ', (string) $key));
            if (is_array($val) && isset($val['new'])) {
                $newVal = is_array($val['new']) ? json_encode($val['new']) : (string) $val['new'];
                $items[] = "<li><strong>{$label}</strong>: {$newVal}</li>";
            } elseif (is_array($val) && isset($val['count'])) {
                $items[] = "<li><strong>{$label}</strong>: updated ({$val['count']} items)</li>";
            } else {
                $items[] = "<li><strong>{$label}</strong> updated</li>";
            }
        }

        return '<ul style="margin: 8px 0; padding-left: 20px;">'.implode('', $items).'</ul>';
    }
}

