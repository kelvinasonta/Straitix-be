<?php

declare(strict_types=1);

namespace Modules\Organizer\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Credits\app\Exceptions\InsufficientCreditsException;
use Modules\Organizer\app\Enum\OrganizerPlanTier;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Services\OrganizerSubscriptionService;

class OrganizerSubscriptionController extends Controller
{
    public function __construct(
        protected OrganizerSubscriptionService $subscriptionService
    ) {}

    /**
     * Get the organizer's current subscription tier, effective fee rate, history, and available plans.
     */
    public function show(string $organizerUuid): JsonResponse
    {
        $organizer = Organizer::where('uuid', $organizerUuid)->firstOrFail();

        return ApiResponse::success(
            'Organizer subscription details retrieved successfully.',
            $this->subscriptionService->getSubscriptionStatus($organizer)
        );
    }

    /**
     * Upgrade or renew the organizer's subscription plan using their Credits wallet.
     */
    public function upgrade(Request $request, string $organizerUuid): JsonResponse
    {
        $validated = $request->validate([
            'plan_tier' => ['required', 'string', Rule::in([OrganizerPlanTier::Pro->value])],
            'months' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,annual'],
        ]);

        $organizer = Organizer::where('uuid', $organizerUuid)->firstOrFail();
        $targetTier = OrganizerPlanTier::from($validated['plan_tier']);
        $months = (int) ($validated['months'] ?? 1);
        $billingCycle = (string) ($validated['billing_cycle'] ?? 'monthly');

        try {
            $result = $this->subscriptionService->upgradeWithCredits(
                organizer: $organizer,
                actor: $request->user(),
                targetTier: $targetTier,
                months: $months,
                billingCycle: $billingCycle
            );

            return ApiResponse::success(
                "Successfully upgraded to {$targetTier->label()} plan.",
                $result
            );
        } catch (InsufficientCreditsException $e) {
            return ApiResponse::error(
                $e->getMessage(),
                422,
                'INSUFFICIENT_CREDITS'
            );
        }
    }

    /**
     * Cancel auto-renewal on the organizer's active subscription (retains benefits until period end).
     */
    public function cancel(Request $request, string $organizerUuid): JsonResponse
    {
        $organizer = Organizer::where('uuid', $organizerUuid)->firstOrFail();

        $result = $this->subscriptionService->cancelSubscription(
            organizer: $organizer,
            actor: $request->user()
        );

        return ApiResponse::success(
            'Subscription auto-renewal cancelled. Your plan benefits remain active until the end of the current billing period.',
            $result
        );
    }
}
