<?php

namespace Modules\Tickets\app\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Events\app\Models\Event;
use Modules\Tickets\app\Models\TicketType;
use Throwable;

class TicketInventoryUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array{
     *     id: string,
     *     name: string,
     *     ticket_capacity: string|null,
     *     quantity: int|null,
     *     sold_quantity: int,
     *     reserved_quantity: int,
     *     available_quantity: int|null,
     *     is_available: bool,
     *     status: string|null
     * }>  $ticketTypes
     */
    public function __construct(
        public string $eventUuid,
        public array $ticketTypes
    ) {}

    /**
     * Dispatch inventory update for an event after the current DB transaction commits.
     */
    public static function dispatchForEvent(int $eventId, ?string $eventUuid = null): void
    {
        $dispatch = function () use ($eventId, $eventUuid): void {
            try {
                $resolvedUuid = $eventUuid ?? Event::where('id', $eventId)->value('uuid');
                if (! $resolvedUuid) {
                    return;
                }

                $ticketTypes = TicketType::where('event_id', $eventId)
                    ->get()
                    ->map(fn (TicketType $tt): array => [
                        'id' => (string) $tt->uuid,
                        'name' => (string) $tt->name,
                        'ticket_capacity' => $tt->ticket_capacity,
                        'quantity' => $tt->quantity !== null ? (int) $tt->quantity : null,
                        'sold_quantity' => (int) $tt->sold_quantity,
                        'reserved_quantity' => (int) $tt->reserved_quantity,
                        'available_quantity' => $tt->remainingQuantity(),
                        'is_available' => $tt->isAvailable(),
                        'status' => $tt->status,
                    ])
                    ->values()
                    ->all();

                event(new self((string) $resolvedUuid, $ticketTypes));
            } catch (Throwable $e) {
                Log::warning("Failed to broadcast TicketInventoryUpdated for event [{$eventId}]: {$e->getMessage()}");
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("events.{$this->eventUuid}.inventory"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.inventory.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event_uuid' => $this->eventUuid,
            'ticket_types' => $this->ticketTypes,
        ];
    }
}
