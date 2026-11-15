<?php

declare(strict_types=1);

namespace Modules\Admin\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\app\Services\AdminOrganizerService;

class AdminOrganizerController extends Controller
{
    public function __construct(
        protected AdminOrganizerService $organizerService
    ) {}

    /**
     * List all platform organizers with pagination and search.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'is_verified', 'per_page']);
        $organizers = $this->organizerService->getOrganizers($filters);

        return ApiResponse::success(
            'Organizers retrieved successfully.',
            $organizers
        );
    }

    /**
     * Retrieve deep-dive tenant dossier.
     */
    public function show(string $organizerUuid): JsonResponse
    {
        $dossier = $this->organizerService->getOrganizerDossier($organizerUuid);

        return ApiResponse::success(
            'Organizer dossier retrieved successfully.',
            $dossier
        );
    }

    /**
     * Suspend an organizer tenant.
     */
    public function suspend(Request $request, string $organizerUuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $organizer = $this->organizerService->suspendOrganizer(
            $organizerUuid,
            $request->user(),
            $validated['reason']
        );

        return ApiResponse::success(
            'Organizer suspended successfully.',
            $organizer
        );
    }

    /**
     * Reactivate / unsuspend an organizer tenant.
     */
    public function unsuspend(Request $request, string $organizerUuid): JsonResponse
    {
        $organizer = $this->organizerService->unsuspendOrganizer(
            $organizerUuid,
            $request->user()
        );

        return ApiResponse::success(
            'Organizer reactivated successfully.',
            $organizer
        );
    }

    /**
     * Update an organizer's subscription tier and/or custom commission rate.
     */
    public function updatePlan(Request $request, string $organizerUuid): JsonResponse
    {
        $validated = $request->validate([
            'plan_tier' => ['sometimes', 'string', 'in:free,pro,enterprise'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'custom_fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'custom_fee_fixed' => ['nullable', 'numeric', 'min:0'],
        ]);

        $organizer = $this->organizerService->updatePlanAndFees(
            $organizerUuid,
            $request->user(),
            $validated
        );

        return ApiResponse::success(
            'Organizer subscription plan and fee settings updated successfully.',
            $organizer
        );
    }

    /**
     * List all platform subscription plans.
     */
    public function plans(): JsonResponse
    {
        return ApiResponse::success(
            'Subscription plans retrieved successfully.',
            $this->organizerService->getSubscriptionPlans()
        );
    }

    /**
     * Update a platform subscription plan's pricing, credit costs, or fee rates.
     */
    public function updateSubscriptionPlan(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price_ngn' => ['sometimes', 'numeric', 'min:0'],
            'annual_price_ngn' => ['sometimes', 'numeric', 'min:0'],
            'monthly_cost_credits' => ['sometimes', 'integer', 'min:0'],
            'annual_cost_credits' => ['sometimes', 'integer', 'min:0'],
            'fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'max_staff_members' => ['sometimes', 'integer', 'min:1'],
            'max_active_events' => ['sometimes', 'integer', 'min:1'],
            'features' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plan = $this->organizerService->updateSubscriptionPlan(
            $slug,
            $request->user(),
            $validated
        );

        return ApiResponse::success(
            'Subscription plan updated successfully.',
            $plan
        );
    }
}
