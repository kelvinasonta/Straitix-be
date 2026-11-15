<?php

declare(strict_types=1);

namespace Modules\Admin\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\app\Services\AdminFinanceService;

class AdminFinanceController extends Controller
{
    public function __construct(
        protected AdminFinanceService $financeService
    ) {}

    /**
     * Cross-tenant platform orders.
     */
    public function orders(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'organizer_id', 'per_page']);
        $orders = $this->financeService->getOrders($filters);

        return ApiResponse::success(
            'Platform orders retrieved successfully.',
            $orders
        );
    }

    /**
     * Single order detail view.
     */
    public function showOrder(string $orderUuid): JsonResponse
    {
        $order = $this->financeService->getOrderDetails($orderUuid);

        return ApiResponse::success(
            'Order details retrieved successfully.',
            $order
        );
    }

    /**
     * Payout review queue.
     */
    public function payouts(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'organizer_id', 'per_page']);
        $payouts = $this->financeService->getPayouts($filters);

        return ApiResponse::success(
            'Payouts retrieved successfully.',
            $payouts
        );
    }

    /**
     * Approve a payout.
     */
    public function approvePayout(Request $request, string $payoutUuid): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $payout = $this->financeService->approvePayout(
                $payoutUuid,
                $request->user(),
                $validated['notes'] ?? null
            );

            return ApiResponse::success(
                'Payout approved successfully.',
                $payout
            );
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Reject a payout.
     */
    public function rejectPayout(Request $request, string $payoutUuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $payout = $this->financeService->rejectPayout(
                $payoutUuid,
                $request->user(),
                $validated['reason']
            );

            return ApiResponse::success(
                'Payout rejected successfully.',
                $payout
            );
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }
}
