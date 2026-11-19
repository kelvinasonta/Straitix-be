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
        // Stopped sending email to attendees when an event is edited.
        return;
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

