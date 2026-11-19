<?php

declare(strict_types=1);

namespace Modules\Payments\app\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Models\User;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Models\Organizer;
use Modules\Payments\app\Enums\OrderStatus;
use Modules\Payments\app\Enums\PaymentStatus;
use Modules\Payments\app\Exceptions\OrderException;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Models\OrderItem;
use Modules\Payments\app\Models\Payment;
use Modules\Payments\app\Services\Mail\SendOrderConfirmationEmailService;
use Modules\Payments\app\Services\Mail\SendOrderRefundedEmailService;
use Modules\Shared\app\Contracts\AppSettingsServiceInterface;
use Modules\Tickets\app\Events\TicketInventoryUpdated;
use Modules\Tickets\app\Models\TicketType;

class OrderService
{
    public function __construct(
        protected PaymentService $paymentService,
        protected ?AppSettingsServiceInterface $settingsService = null,
        protected ?AuditService $auditService = null,
        protected ?SendOrderConfirmationEmailService $orderConfirmationEmailService = null,
        protected ?SendOrderRefundedEmailService $orderRefundedEmailService = null,
    ) {}

    /**
     * Create a new order with reserved ticket quantities.
     *
     * @param  array<int, array{ticket_type_uuid: string, quantity: int, attendee_name?: string|null, attendee_email?: string|null}>  $itemsData
     * @param  array{customer_name?: string|null, customer_email?: string|null, customer_phone?: string|null}  $customerData
     */
    public function createOrder(
        ?User $buyer,
        Event $event,
        array $itemsData,
        array $customerData = [],
        ?string $notes = null
    ): Order {
        $statusValue = is_object($event->status) ? ($event->status->value ?? '') : (string) $event->status;
        if (strtolower($statusValue) !== 'published') {
            throw new OrderException('Orders can only be placed for published events.');
        }

        if (empty($itemsData)) {
            throw new OrderException('Order must contain at least one ticket item.');
        }

        $customerEmail = $customerData['customer_email'] ?? ($buyer?->email ?? null);
        if (! $buyer && ! empty($customerEmail)) {
            $buyer = User::where('email', strtolower(trim($customerEmail)))->first();
        }

        return DB::transaction(function () use ($buyer, $event, $itemsData, $customerData, $notes): Order {
            $subtotal = 0.00;
            $preparedItems = [];
            $currency = 'NGN';

            foreach ($itemsData as $item) {
                $quantity = (int) ($item['quantity'] ?? 1);
                if ($quantity <= 0) {
                    throw new OrderException('Ticket quantity must be at least 1.');
                }

                $ticketTypeUuid = $item['ticket_type_uuid'] ?? '';
                /** @var TicketType|null $ticketType */
                $ticketType = TicketType::where('uuid', $ticketTypeUuid)
                    ->where('event_id', $event->id)
                    ->lockForUpdate()
                    ->first();

                if (! $ticketType) {
                    throw new OrderException("Ticket type not found for this event: {$ticketTypeUuid}");
                }

                if (! $ticketType->hasAvailableCapacity($quantity)) {
                    throw new OrderException("Not enough available tickets for '{$ticketType->name}'.");
                }

                $currency = $ticketType->currency ?: 'NGN';
                $unitPrice = (float) $ticketType->price;
                $itemTotal = $unitPrice * $quantity;
                $subtotal += $itemTotal;

                // Hold reservation for the ticket
                $ticketType->increment('reserved_quantity', $quantity);

                $preparedItems[] = [
                    'ticket_type' => $ticketType,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $itemTotal,
                    'currency' => $currency,
                    'attendee_name' => $item['attendee_name'] ?? null,
                    'attendee_email' => $item['attendee_email'] ?? null,
                ];
            }

            $discount = 0.00;

            // Calculate platform service fee dynamically from organizer plan / custom rate / app settings (if total subtotal > 0)
            $serviceFee = 0.00;
            if ($subtotal > 0.00) {
                $totalTickets = (int) array_sum(array_column($preparedItems, 'quantity'));
                $organizer = $event->relationLoaded('organizer')
                    ? $event->organizer
                    : Organizer::find($event->organizer_id);
                $serviceFee = $this->calculatePlatformFee($subtotal, max(1, $totalTickets), $organizer);
            }

            // Check if fee is passed through to buyer or absorbed
            $isPassthrough = $this->isFeePassthrough();
            $total = $isPassthrough
                ? max(0.00, round($subtotal - $discount + $serviceFee, 2))
                : max(0.00, round($subtotal - $discount, 2));

            $customerName = $customerData['customer_name'] ?? ($buyer?->name ?? 'Guest Buyer');
            $customerEmail = $customerData['customer_email'] ?? ($buyer?->email ?? 'guest@reservation.hold');
            $customerPhone = $customerData['customer_phone'] ?? null;

            // Generate secure guest token for unauthenticated access & verification
            $guestToken = $buyer ? null : bin2hex(random_bytes(24));

            $order = Order::create([
                'user_id' => $buyer?->id,
                'event_id' => $event->id,
                'organizer_id' => $event->organizer_id,
                'status' => OrderStatus::Pending,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'service_fee' => $serviceFee,
                'total' => $total,
                'currency' => $currency,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'guest_token' => $guestToken,
                'notes' => $notes,
                'reserved_until' => now()->addMinutes(15),
            ]);

            foreach ($preparedItems as $prep) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'ticket_type_id' => $prep['ticket_type']->id,
                    'quantity' => $prep['quantity'],
                    'unit_price' => $prep['unit_price'],
                    'total_price' => $prep['total_price'],
                    'currency' => $prep['currency'],
                    'attendee_name' => $prep['attendee_name'] ?: $customerName,
                    'attendee_email' => $prep['attendee_email'] ?: $customerEmail,
                ]);
            }

            // If totally free, immediately fulfill order and issue tickets
            if ($total <= 0.00) {
                $this->paymentService->fulfillFreeOrder($order);
            }

            TicketInventoryUpdated::dispatchForEvent((int) $event->id, (string) $event->uuid);

            return $order->fresh(['items.ticketType', 'event.organizer', 'event.location', 'tickets.ticketType']);
        });
    }

    /**
     * Get a specific order by UUID.
     */
    public function getOrder(string $orderUuid): Order
    {
        return Order::with(['items.ticketType', 'event.organizer', 'event.location', 'tickets.ticketType', 'payments'])
            ->where(function ($query) use ($orderUuid) {
                $query->where('uuid', $orderUuid)
                    ->orWhere('order_number', $orderUuid);
            })
            ->firstOrFail();
    }

    /**
     * Get orders for the authenticated customer.
     */
    public function getUserOrders(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = Order::with(['items.ticketType', 'event.location', 'tickets'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('customer_email', $user->email);
            })
            ->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? $filters['perPage'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    /**
     * Get orders for an organizer, optionally filtered by event.
     */
    public function getOrganizerOrders(Organizer $organizer, ?string $eventUuid = null, array $filters = []): LengthAwarePaginator
    {
        $query = Order::with(['items.ticketType', 'event.location', 'user', 'tickets.ticketType'])
            ->where('organizer_id', $organizer->id)
            ->latest();

        if ($eventUuid) {
            $event = Event::where('uuid', $eventUuid)->where('organizer_id', $organizer->id)->firstOrFail();
            $query->where('event_id', $event->id);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', $search)
                    ->orWhere('customer_name', 'like', $search)
                    ->orWhere('customer_email', 'like', $search);
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? $filters['perPage'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    /**
     * Export organizer orders as CSV.
     */
    public function exportOrdersCsv(Organizer $organizer, ?string $eventUuid = null): string
    {
        $query = Order::with(['event'])
            ->where('organizer_id', $organizer->id)
            ->latest();

        if ($eventUuid) {
            $event = Event::where('uuid', $eventUuid)->where('organizer_id', $organizer->id)->firstOrFail();
            $query->where('event_id', $event->id);
        }

        $orders = $query->get();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, [
            'Order Number',
            'Event',
            'Customer Name',
            'Customer Email',
            'Customer Phone',
            'Status',
            'Subtotal',
            'Discount',
            'Service Fee',
            'Total',
            'Currency',
            'Created At',
            'Paid At',
        ]);

        foreach ($orders as $order) {
            $statusValue = is_object($order->status) ? ($order->status->value ?? '') : (string) $order->status;
            fputcsv($output, [
                $order->order_number,
                $order->event ? $order->event->title : 'N/A',
                $order->customer_name,
                $order->customer_email,
                $order->customer_phone ?? '',
                $statusValue,
                $order->subtotal,
                $order->discount,
                $order->service_fee,
                $order->total,
                $order->currency,
                $order->created_at ? $order->created_at->toIso8601String() : '',
                $order->paid_at ? $order->paid_at->toIso8601String() : '',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    /**
     * Cancel an active pending order, releasing any held ticket reservations.
     */
    public function cancelOrder(Order $order): Order
    {
        if ($order->status !== OrderStatus::Pending) {
            throw new OrderException('Only pending orders can be cancelled.');
        }

        return DB::transaction(function () use ($order): Order {
            foreach ($order->items as $item) {
                if ($item->ticketType) {
                    $newReserved = max(0, $item->ticketType->reserved_quantity - $item->quantity);
                    $item->ticketType->update(['reserved_quantity' => $newReserved]);
                }
            }

            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

            TicketInventoryUpdated::dispatchForEvent((int) $order->event_id);

            return $order->fresh(['items.ticketType']);
        });
    }

    /**
     * Refund a paid order, voiding tickets and restoring ticket capacity.
     *
     * @param  array{reason?: string|null, amount?: float|int|null}  $data
     */
    public function refundOrder(Organizer $organizer, string $orderUuid, array $data = []): Order
    {
        /** @var Order $order */
        $order = Order::with(['items.ticketType', 'event.location', 'organizer', 'user', 'tickets.ticketType', 'payments'])
            ->where('organizer_id', $organizer->id)
            ->where(function ($query) use ($orderUuid): void {
                $query->where('uuid', $orderUuid)
                    ->orWhere('order_number', $orderUuid);
                if (is_numeric($orderUuid)) {
                    $query->orWhere('id', (int) $orderUuid);
                }
            })
            ->firstOrFail();

        if ($order->status !== OrderStatus::Paid) {
            throw new OrderException(
                $order->status === OrderStatus::Refunded
                    ? 'Order has already been refunded.'
                    : 'Only paid orders can be refunded.'
            );
        }

        return DB::transaction(function () use ($order, $organizer, $data): Order {
            // 1. Void/cancel issued tickets
            foreach ($order->tickets as $ticket) {
                $ticket->update(['status' => 'refunded']);
            }

            // 2. Decrement sold_quantity on corresponding TicketType rows
            foreach ($order->items as $item) {
                if ($item->ticketType) {
                    $newSold = max(0, $item->ticketType->sold_quantity - $item->quantity);
                    $item->ticketType->update(['sold_quantity' => $newSold]);
                }
            }

            // 3. Mark payments as refunded
            foreach ($order->payments as $payment) {
                $payment->update(['status' => PaymentStatus::Refunded]);
            }

            // 4. Update order status and refunded_at timestamp
            $order->update([
                'status' => OrderStatus::Refunded,
                'refunded_at' => now(),
            ]);

            // 5. Record audit log
            $this->auditService?->log(
                action: 'order.refunded',
                description: "Order '{$order->order_number}' was refunded",
                organizer: $organizer,
                user: auth()->user(),
                metadata: [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'order_number' => $order->order_number,
                    'reason' => $data['reason'] ?? null,
                    'amount' => $data['amount'] ?? (float) $order->total,
                ],
                targetType: Order::class,
                targetId: (string) $order->id,
                reason: $data['reason'] ?? null
            );

            // 6. Dispatch refund confirmation email to customer
            $this->orderRefundedEmailService?->execute(
                $order,
                $data['reason'] ?? null,
                isset($data['amount']) ? (float) $data['amount'] : null
            );

            TicketInventoryUpdated::dispatchForEvent((int) $order->event_id);

            return $order->fresh(['items.ticketType', 'event.location', 'user', 'tickets']);
        });
    }

    /**
     * Re-send order purchase receipt and ticket pass email to customer.
     */
    public function resendOrderReceipt(Organizer $organizer, string $orderUuid): void
    {
        /** @var Order $order */
        $order = Order::with(['items.ticketType', 'event.location', 'event.schedule', 'organizer', 'user', 'tickets.ticketType', 'payments'])
            ->where('organizer_id', $organizer->id)
            ->where(function ($query) use ($orderUuid): void {
                $query->where('uuid', $orderUuid)
                    ->orWhere('order_number', $orderUuid);
                if (is_numeric($orderUuid)) {
                    $query->orWhere('id', (int) $orderUuid);
                }
            })
            ->firstOrFail();

        if ($order->status !== OrderStatus::Paid && $order->tickets->isEmpty()) {
            throw new OrderException('Receipt can only be resent for paid orders or orders with issued tickets.');
        }

        $payment = $order->payments()->where('status', PaymentStatus::Successful)->latest()->first()
            ?? $order->payments()->latest()->first()
            ?? new Payment([
                'order_id' => $order->id,
                'reference' => 'RCP-'.strtoupper(Str::random(10)),
                'status' => PaymentStatus::Successful,
                'amount' => $order->total,
                'currency' => $order->currency,
            ]);

        $this->orderConfirmationEmailService?->execute($order, $payment);

        $this->auditService?->log(
            action: 'order.receipt_resent',
            description: "Purchase receipt and ticket pass re-sent for order '{$order->order_number}'",
            organizer: $organizer,
            user: auth()->user(),
            metadata: [
                'order_id' => $order->id,
                'order_uuid' => $order->uuid,
                'order_number' => $order->order_number,
                'customer_email' => $order->customer_email,
            ],
            targetType: Order::class,
            targetId: (string) $order->id
        );
    }

    /**
     * Cancel pending orders that have exceeded their reservation window.
     */
    public function cancelExpiredReservations(): int
    {
        $expiredOrders = Order::with('items.ticketType')
            ->where('status', OrderStatus::Pending)
            ->where('reserved_until', '<', now())
            ->get();

        $count = 0;

        foreach ($expiredOrders as $order) {
            DB::transaction(function () use ($order): void {
                foreach ($order->items as $item) {
                    if ($item->ticketType) {
                        $newReserved = max(0, $item->ticketType->reserved_quantity - $item->quantity);
                        $item->ticketType->update(['reserved_quantity' => $newReserved]);
                    }
                }

                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                ]);

                TicketInventoryUpdated::dispatchForEvent((int) $order->event_id);
            });

            $count++;
        }

        return $count;
    }

    /**
     * Compute platform service fee based on organizer tier/custom rate or global app settings.
     */
    protected function calculatePlatformFee(float $subtotal, int $ticketCount, ?Organizer $organizer = null): float
    {
        $settings = $this->getSettings();
        $globalPercentage = $settings ? (float) $settings->platform_fee_percentage : 0.0;
        $globalFixed = $settings ? (float) $settings->platform_fee_fixed : 0.0;

        if ($organizer) {
            $effective = $organizer->resolveEffectiveFeeRate($globalPercentage, $globalFixed);
            $percentage = $effective['percentage'];
            $fixed = $effective['fixed'];
        } else {
            if (! $settings) {
                return 0.00;
            }
            $percentage = $globalPercentage;
            $fixed = $globalFixed;
        }

        $percentageFee = ($subtotal * $percentage) / 100;
        $totalFixedFee = $fixed * max(1, $ticketCount);

        return round($percentageFee + $totalFixedFee, 2);
    }

    /**
     * Check if the platform fee should be passed onto the ticket buyer.
     */
    protected function isFeePassthrough(): bool
    {
        $settings = $this->getSettings();

        return $settings ? (bool) $settings->platform_fee_passthrough : true;
    }

    /**
     * Helper to resolve app settings service.
     */
    protected function getSettings()
    {
        $service = $this->settingsService ?? app(AppSettingsServiceInterface::class);

        return $service?->getAll();
    }
}
