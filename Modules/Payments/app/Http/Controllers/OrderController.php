<?php

declare(strict_types=1);

namespace Modules\Payments\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Models\Organizer;
use Modules\Payments\app\Exceptions\OrderException;
use Modules\Payments\app\Services\OrderService;
use Modules\Payments\app\Transformers\OrderResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * Create a new order (ticket purchase or reservation).
     */
    public function store(Request $request, string $eventUuid): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.ticket_type_uuid' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.attendee_name' => 'nullable|string|max:255',
            'items.*.attendee_email' => 'nullable|email|max:255',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);

        $event = Event::where('uuid', $eventUuid)->orWhere('slug', $eventUuid)->firstOrFail();

        $buyer = $request->user() ?? auth('sanctum')->user();
        if (! $buyer && ! empty($validated['customer_email'])) {
            $buyer = \Modules\Auth\app\Models\User::where('email', strtolower(trim($validated['customer_email'])))->first();
        }

        try {
            $order = $this->orderService->createOrder(
                buyer: $buyer,
                event: $event,
                itemsData: $validated['items'],
                customerData: [
                    'customer_name' => $validated['customer_name'] ?? null,
                    'customer_email' => $validated['customer_email'] ?? null,
                    'customer_phone' => $validated['customer_phone'] ?? null,
                ],
                notes: $validated['notes'] ?? null
            );

            return ApiResponse::success(
                'Order created successfully.',
                new OrderResource($order),
                201
            );
        } catch (OrderException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Get a specific order by UUID.
     */
    public function show(Request $request, string $orderUuid): JsonResponse
    {
        $order = $this->orderService->getOrder($orderUuid);

        // Ensure user is authorized to view this order (buyer, guest with guest_token, or organizer member)
        $user = $request->user() ?? auth('sanctum')->user();
        if ($user) {
            // If the order belongs to an account and it is not this user, check organizer membership
            if ($order->user_id && $order->user_id !== $user->id) {
                $organizer = $order->relationLoaded('organizer')
                    ? $order->organizer
                    : Organizer::with(['members'])->find($order->organizer_id);

                if (! $organizer || ! $organizer->isMember($user)) {
                    return ApiResponse::error('Unauthorized to view this order.', 403);
                }
            } elseif (! $order->user_id) {
                // For guest orders, check if the authenticated user is an organizer member
                $organizer = $order->relationLoaded('organizer')
                    ? $order->organizer
                    : Organizer::with(['members'])->find($order->organizer_id);

                $isOrganizer = $organizer && $organizer->isMember($user);
                $guestToken = $request->header('X-Guest-Token') ?? $request->query('guest_token');

                if (! $isOrganizer && $order->guest_token && ($order->guest_token !== $guestToken || empty($guestToken))) {
                    return ApiResponse::error('Unauthorized to view this order. Valid guest token required.', 403);
                }
            }
        } else {
            // For unauthenticated guest access, require guest_token header or query param if set on order
            $guestToken = $request->header('X-Guest-Token') ?? $request->query('guest_token');
            if ($order->guest_token && ($order->guest_token !== $guestToken || empty($guestToken))) {
                return ApiResponse::error('Unauthorized to view this order. Valid guest token required.', 403);
            }
        }

        return ApiResponse::success(
            'Order retrieved successfully.',
            new OrderResource($order)
        );
    }

    /**
     * Cancel a pending order and release held ticket reservations.
     */
    public function cancel(Request $request, string $orderUuid): JsonResponse
    {
        $order = $this->orderService->getOrder($orderUuid);

        // Authorization check: buyer or guest with valid guest_token
        $user = $request->user() ?? auth('sanctum')->user();
        if ($user) {
            if ($order->user_id && $order->user_id !== $user->id) {
                return ApiResponse::error('Unauthorized to cancel this order.', 403);
            }
        } else {
            $guestToken = $request->header('X-Guest-Token') ?? $request->input('guest_token') ?? $request->query('guest_token');
            if ($order->guest_token && ($order->guest_token !== $guestToken || empty($guestToken))) {
                return ApiResponse::error('Unauthorized to cancel this order. Valid guest token required.', 403);
            }
        }

        try {
            $cancelled = $this->orderService->cancelOrder($order);

            return ApiResponse::success(
                'Order reservation cancelled and tickets released.',
                new OrderResource($cancelled)
            );
        } catch (OrderException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Get orders for the authenticated customer.
     */
    public function userOrders(Request $request): JsonResponse
    {
        $orders = $this->orderService->getUserOrders($request->user(), $request->all());

        return ApiResponse::success(
            'Orders retrieved successfully.',
            OrderResource::collection($orders)->response()->getData(true)
        );
    }

    /**
     * Get orders for an organizer (optionally filtered by event).
     */
    public function organizerOrders(Request $request, string $organizerUuid, ?string $eventUuid = null): JsonResponse
    {
        $organizer = $request->attributes->get('active_organizer')
            ?? Organizer::where('uuid', $organizerUuid)->firstOrFail();

        $orders = $this->orderService->getOrganizerOrders($organizer, $eventUuid, $request->all());

        return ApiResponse::success(
            'Organizer orders retrieved successfully.',
            OrderResource::collection($orders)->response()->getData(true)
        );
    }

    /**
     * Export organizer orders as CSV.
     */
    public function exportOrganizerOrders(Request $request, string $organizerUuid, ?string $eventUuid = null): StreamedResponse
    {
        $organizer = $request->attributes->get('active_organizer')
            ?? Organizer::where('uuid', $organizerUuid)->firstOrFail();

        $csv = $this->orderService->exportOrdersCsv($organizer, $eventUuid);

        $filename = 'orders-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Refund an organizer's order.
     */
    public function refund(Request $request, string $organizerUuid, string $orderUuid): JsonResponse
    {
        $organizer = $request->attributes->get('active_organizer')
            ?? Organizer::where('uuid', $organizerUuid)
                ->orWhere('slug', $organizerUuid)
                ->when(is_numeric($organizerUuid), fn ($q) => $q->orWhere('id', (int) $organizerUuid))
                ->firstOrFail();

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'amount' => 'nullable|numeric|min:0',
        ]);

        try {
            $refundedOrder = $this->orderService->refundOrder($organizer, $orderUuid, $validated);

            return ApiResponse::success(
                'Order refunded successfully.',
                new OrderResource($refundedOrder)
            );
        } catch (OrderException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Resend purchase receipt and ticket pass to the customer.
     */
    public function resendReceipt(Request $request, string $organizerUuid, string $orderUuid): JsonResponse
    {
        $organizer = $request->attributes->get('active_organizer')
            ?? Organizer::where('uuid', $organizerUuid)
                ->orWhere('slug', $organizerUuid)
                ->when(is_numeric($organizerUuid), fn ($q) => $q->orWhere('id', (int) $organizerUuid))
                ->firstOrFail();

        try {
            $this->orderService->resendOrderReceipt($organizer, $orderUuid);

            return ApiResponse::success(
                'Purchase receipt and ticket pass re-sent successfully.',
                null
            );
        } catch (OrderException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }
}
