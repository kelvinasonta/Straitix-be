<?php

namespace Modules\Tickets\app\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Tickets\app\Models\Ticket;

class AttendeeCheckedIn implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $stats
     */
    public function __construct(
        public string $eventUuid,
        public Ticket $ticket,
        public array $stats
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("events.{$this->eventUuid}.gate"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'attendee.checked_in';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event_uuid' => $this->eventUuid,
            'ticket' => [
                'id' => $this->ticket->uuid,
                'ticket_number' => $this->ticket->ticket_number,
                'attendee_name' => $this->ticket->attendee_name,
                'attendee_email' => $this->ticket->attendee_email,
                'ticket_tier' => $this->ticket->ticketType?->name ?? 'General',
                'status' => $this->ticket->status,
                'checked_in_at' => $this->ticket->checked_in_at?->toISOString(),
            ],
            'stats' => $this->stats,
        ];
    }
}
