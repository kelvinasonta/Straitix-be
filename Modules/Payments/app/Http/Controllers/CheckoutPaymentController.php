<?php

declare(strict_types=1);

namespace Modules\Payments\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Modules\Payments\app\Exceptions\OrderException;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Services\PaymentService;
use Modules\Payments\app\Transformers\OrderResource;

class CheckoutPaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Initialize payment for an order.
     */
    public function initialize(Request $request, string $orderUuid): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => 'nullable|string|in:paystack,stripe,free',
            'callback_url' => 'nullable|url',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:50',
        ]);

        $order = Order::where('uuid', $orderUuid)->firstOrFail();

        // Ensure user is authorized to pay for this order
        $user = $request->user() ?? auth('sanctum')->user();
        if ($user) {
            if ($order->user_id && $order->user_id !== $user->id) {
                return ApiResponse::error('Unauthorized to initiate payment for this order.', 403);
            }
        } else {
            $guestToken = $request->header('X-Guest-Token') ?? $request->input('guest_token') ?? $request->query('guest_token');
            if ($order->guest_token && $order->guest_token !== $guestToken) {
                return ApiResponse::error('Unauthorized to initiate payment for this order. Valid guest token required.', 403);
            }
        }

        // Update customer details on order if provided during checkout review
        $updates = [];
        if (! empty($validated['customer_name'])) {
            $updates['customer_name'] = $validated['customer_name'];
        }
        if (! empty($validated['customer_email'])) {
            $updates['customer_email'] = $validated['customer_email'];
        }
        if (array_key_exists('customer_phone', $validated) && $validated['customer_phone'] !== null) {
            $updates['customer_phone'] = $validated['customer_phone'];
        }
        if (! empty($updates)) {
            $order->update($updates);
            if (! empty($updates['customer_name']) || ! empty($updates['customer_email'])) {
                $order->items()->update([
                    'attendee_name' => $order->customer_name,
                    'attendee_email' => $order->customer_email,
                ]);
            }
        }

        try {
            $result = $this->paymentService->initializePayment(
                order: $order,
                gateway: $validated['gateway'] ?? 'paystack',
                callbackUrl: $validated['callback_url'] ?? null
            );

            return ApiResponse::success('Payment initialized successfully.', $result);
        } catch (OrderException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Verify payment status using gateway reference.
     */
    public function verify(Request $request, string $reference): JsonResponse
    {
        try {
            $order = $this->paymentService->verifyPayment($reference);

            return ApiResponse::success(
                'Payment verified successfully.',
                new OrderResource($order)
            );
        } catch (OrderException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Payment gateway webhook receiver.
     */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        $payload = $request->all();

        if (strtolower($gateway) === 'paystack') {
            $secretKey = config('paystack.secret_key');
            $signature = $request->header('x-paystack-signature');

            // Strictly fail closed if secret key is missing
            if (empty($secretKey)) {
                Log::error('Paystack webhook received but secret key is not configured.');

                return response()->json(['status' => 'error', 'message' => 'Webhook verification unavailable.'], 500);
            }

            // Verify Paystack HMAC SHA512 signature
            $computedSignature = hash_hmac('sha512', $request->getContent(), $secretKey);
            if (! hash_equals($computedSignature, (string) $signature)) {
                Log::warning('Paystack webhook invalid signature detected.', [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 400);
            }

            $event = $payload['event'] ?? '';
            $reference = $payload['data']['reference'] ?? null;

            if ($event === 'charge.success' && $reference) {
                try {
                    $this->paymentService->verifyPayment($reference, $payload['data']);
                } catch (\Exception $e) {
                    Log::error("Payment webhook verification failed for reference {$reference}: {$e->getMessage()}");
                }
            }
        }

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Authorize private-orders.{orderUuid} WebSocket channel for guest or authenticated buyers.
     */
    public function broadcastAuth(Request $request): mixed
    {
        $validated = $request->validate([
            'socket_id' => 'required|string',
            'channel_name' => 'required|string',
        ]);

        $channelName = (string) $validated['channel_name'];
        if (! preg_match('/^private-orders\.([a-zA-Z0-9-]+)$/', $channelName, $matches)) {
            return ApiResponse::error('Invalid order broadcast channel.', 403);
        }

        $orderUuid = $matches[1];
        $order = Order::where('uuid', $orderUuid)->first();
        if (! $order) {
            return ApiResponse::error('Order not found.', 404);
        }

        $user = $request->user() ?? auth('sanctum')->user();
        $authorized = false;

        if ($user) {
            $authorized = ($order->user_id !== null && (int) $order->user_id === (int) $user->id)
                || ($order->customer_email !== null && strtolower((string) $order->customer_email) === strtolower((string) $user->email));
        }

        if (! $authorized) {
            $guestToken = (string) ($request->header('X-Guest-Token') ?? $request->input('guest_token') ?? '');
            if ($order->guest_token && $guestToken !== '' && hash_equals((string) $order->guest_token, $guestToken)) {
                $authorized = true;
            }
        }

        if (! $authorized) {
            return ApiResponse::error('Unauthorized to subscribe to this order channel.', 403);
        }

        return Broadcast::driver()->validAuthenticationResponse($request, [
            'user_id' => $user ? (string) $user->uuid : 'guest',
        ]);
    }
}
