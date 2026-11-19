<?php

declare(strict_types=1);

namespace Modules\Payments\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Models\User;
use Modules\Payments\app\Enums\OrderStatus;
use Modules\Payments\app\Enums\PaymentGateway;
use Modules\Payments\app\Enums\PaymentStatus;
use Modules\Payments\app\Events\OrderPaidEvent;
use Modules\Payments\app\Events\OrderPaymentConfirmed;
use Modules\Payments\app\Exceptions\OrderException;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Models\Payment;
use Modules\Tickets\app\Models\Ticket;

class PaymentService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Initialize payment for an order.
     *
     * @return array<string, mixed>
     */
    public function initializePayment(Order $order, string $gateway = 'paystack', ?string $callbackUrl = null): array
    {
        if ($order->isExpired()) {
            throw new OrderException('Order ticket reservation has expired. Please create a new order.');
        }

        if ($order->isPaid()) {
            return [
                'status' => 'paid',
                'gateway' => 'free',
                'payment_url' => null,
                'reference' => null,
            ];
        }

        // Handle completely free orders
        if ((float) $order->total <= 0.00 || $gateway === 'free') {
            $this->fulfillFreeOrder($order);

            return [
                'status' => 'paid',
                'gateway' => 'free',
                'payment_url' => null,
                'reference' => null,
            ];
        }

        $reference = 'PAY-'.strtoupper(Str::random(12));
        $resolvedGateway = PaymentGateway::tryFrom(strtolower($gateway)) ?? PaymentGateway::Paystack;

        $payment = Payment::create([
            'order_id' => $order->id,
            'reference' => $reference,
            'gateway' => $resolvedGateway,
            'amount' => $order->total,
            'currency' => $order->currency,
            'status' => PaymentStatus::Pending,
        ]);

        $paymentUrl = $this->generateGatewayUrl($payment, $order, $callbackUrl);

        return [
            'status' => 'pending',
            'gateway' => $resolvedGateway->value,
            'reference' => $payment->reference,
            'payment_url' => $paymentUrl,
            'amount' => (float) $order->total,
            'currency' => $order->currency,
        ];
    }

    /**
     * Verify payment by reference and fulfill the order if successful.
     */
    public function verifyPayment(string $reference, array $gatewayData = []): Order
    {
        // First check payment state inside a transaction or find payment
        $payment = Payment::with('order.items.ticketType', 'order.event', 'order.organizer', 'order.user')
            ->where('reference', $reference)
            ->firstOrFail();

        if ($payment->status === PaymentStatus::Successful && $payment->order?->status === OrderStatus::Paid) {
            return $payment->order->load(['items.ticketType', 'tickets.ticketType', 'payments']);
        }

        // If gateway is Paystack and secret key exists, optionally verify against Paystack API
        $secretKey = config('paystack.secret_key');
        if ($payment->gateway === PaymentGateway::Paystack && ! empty($secretKey) && ! app()->environment('testing')) {
            try {
                $response = Http::withToken($secretKey)
                    ->get(config('paystack.payment_url')."/transaction/verify/{$reference}");

                if ($response->successful() && $response->json('data.status') === 'success') {
                    $gatewayData = array_merge($gatewayData, $response->json('data') ?? []);
                } else {
                    $payment->update([
                        'status' => PaymentStatus::Failed,
                        'gateway_response' => $response->json() ?? [],
                    ]);
                    $payment->order->update(['status' => OrderStatus::Failed]);

                    throw new OrderException('Payment verification failed on gateway.');
                }
            } catch (\Exception $e) {
                if ($e instanceof OrderException) {
                    throw $e;
                }
                throw new OrderException('Failed to communicate with payment gateway: '.$e->getMessage());
            }
        }

        return $this->fulfillOrder($payment->order, $payment, $gatewayData);
    }

    /**
     * Fulfill order and generate tickets inside a database transaction.
     */
    public function fulfillOrder(Order $order, Payment $payment, array $gatewayData = []): Order
    {
        return DB::transaction(function () use ($order, $payment, $gatewayData): Order {
            // Lock payment record for update to prevent concurrent fulfilling
            /** @var Payment|null $lockedPayment */
            $lockedPayment = Payment::where('id', $payment->id)
                ->lockForUpdate()
                ->first();

            // Lock order record for update to serialize parallel webhook and browser redirect collision
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::with(['items.ticketType', 'event', 'organizer', 'user'])
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            $targetOrder = $lockedOrder ?? $order;
            $targetPayment = $lockedPayment ?? $payment;

            // Check if already fulfilled - idempotent check under lock
            if ($targetOrder->status === OrderStatus::Paid || $targetOrder->tickets()->exists()) {
                return $targetOrder->load(['items.ticketType', 'tickets.ticketType', 'payments']);
            }

            $targetPayment->update([
                'status' => PaymentStatus::Successful,
                'paid_at' => now(),
                'gateway_response' => $gatewayData,
            ]);

            $targetUserId = $targetOrder->user_id;
            if (! $targetUserId && ! empty($targetOrder->customer_email)) {
                $targetUserId = User::where('email', strtolower(trim($targetOrder->customer_email)))->value('id');
            }

            $orderUpdates = [
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
            ];
            if (! $targetOrder->user_id && $targetUserId) {
                $orderUpdates['user_id'] = $targetUserId;
            }

            $targetOrder->update($orderUpdates);

            // Issue tickets for each order item
            foreach ($targetOrder->items as $item) {
                $ticketType = $item->ticketType;
                $attendeeEmail = $item->attendee_email ?: $targetOrder->customer_email;
                $ticketUserId = $targetUserId;
                if (! empty($attendeeEmail)) {
                    $ticketUserId = User::where('email', strtolower(trim($attendeeEmail)))->value('id') ?? $targetUserId;
                }

                for ($i = 0; $i < $item->quantity; $i++) {
                    Ticket::create([
                        'order_id' => $targetOrder->id,
                        'event_id' => $targetOrder->event_id,
                        'user_id' => $ticketUserId,
                        'ticket_type_id' => $item->ticket_type_id,
                        'attendee_name' => $item->attendee_name ?: $targetOrder->customer_name,
                        'attendee_email' => $attendeeEmail,
                        'price_paid' => $item->unit_price,
                        'currency' => $item->currency,
                        'status' => 'valid',
                        'ticket_number' => 'TKT-'.strtoupper(Str::random(8)),
                        'qr_code' => 'EVT-'.$targetOrder->event->uuid.'-TKT-'.Str::uuid(),
                    ]);
                }

                if ($ticketType) {
                    $ticketType->increment('sold_quantity', $item->quantity);
                    $newReserved = max(0, $ticketType->reserved_quantity - $item->quantity);
                    $ticketType->update(['reserved_quantity' => $newReserved]);
                }
            }

            $this->auditService->log(
                action: 'order.paid',
                description: "Order '{$targetOrder->order_number}' was marked as paid and tickets were issued",
                organizer: $targetOrder->organizer,
                user: $targetOrder->user,
                metadata: [
                    'order_id' => $targetOrder->id,
                    'order_uuid' => $targetOrder->uuid,
                    'order_number' => $targetOrder->order_number,
                    'total' => (float) $targetOrder->total,
                    'payment_reference' => $targetPayment->reference,
                ]
            );

            // Dispatch OrderPaidEvent for attendee tickets email & organizer sale alert
            event(new OrderPaidEvent($targetOrder, $targetPayment));

            // Push real-time payment confirmation over WebSocket to private-orders.{orderUuid}
            try {
                event(new OrderPaymentConfirmed($targetOrder));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("OrderPaymentConfirmed broadcast skipped for order [{$targetOrder->uuid}]: {$e->getMessage()}");
            }

            \Modules\Tickets\app\Events\TicketInventoryUpdated::dispatchForEvent((int) $targetOrder->event_id);

            return $targetOrder->fresh(['items.ticketType', 'tickets.ticketType', 'payments']);
        });
    }

    /**
     * Fulfill a completely free order (0 cost).
     */
    public function fulfillFreeOrder(Order $order): Order
    {
        $payment = Payment::create([
            'order_id' => $order->id,
            'reference' => 'FREE-'.strtoupper(Str::random(10)),
            'gateway' => PaymentGateway::Free,
            'amount' => 0.00,
            'currency' => $order->currency,
            'status' => PaymentStatus::Successful,
            'paid_at' => now(),
        ]);

        return $this->fulfillOrder($order, $payment, ['note' => 'Free ticket registration']);
    }

    /**
     * Generate checkout payment URL for the chosen gateway.
     */
    protected function generateGatewayUrl(Payment $payment, Order $order, ?string $callbackUrl = null): string
    {
        $secretKey = config('paystack.secret_key');
        $resolvedCallback = $callbackUrl ?? config('app.frontend_url', config('app.url'))."/checkout/callback?reference={$payment->reference}";

        if ($payment->gateway === PaymentGateway::Paystack && ! empty($secretKey) && ! app()->environment('testing')) {
            try {
                $response = Http::withToken($secretKey)->post(config('paystack.payment_url').'/transaction/initialize', [
                    'email' => $order->customer_email,
                    'amount' => (int) round(((float) $order->total) * 100), // in kobo
                    'reference' => $payment->reference,
                    'currency' => $order->currency,
                    'callback_url' => $resolvedCallback,
                    'metadata' => [
                        'order_uuid' => $order->uuid,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name,
                    ],
                ]);

                if ($response->successful() && $response->json('data.authorization_url')) {
                    return (string) $response->json('data.authorization_url');
                }
            } catch (\Exception) {
                // Fall back to direct callback URL
            }
        }

        // Return sandbox/mock redirect URL for local development or testing
        return $resolvedCallback;
    }
}
