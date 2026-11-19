<?php

declare(strict_types=1);

namespace Modules\Payments\app\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Transformers\OrderResource;

class OrderPaymentConfirmed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("orders.{$this->order->uuid}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.payment.confirmed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->order->loadMissing(['items.ticketType', 'event.location', 'tickets.ticketType']);

        return [
            'status' => 'paid',
            'order_uuid' => $this->order->uuid,
            'order_number' => $this->order->order_number,
            'paid_at' => $this->order->paid_at?->toISOString(),
            'order' => (new OrderResource($this->order))->resolve(),
        ];
    }
}
