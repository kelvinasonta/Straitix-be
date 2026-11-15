<?php

declare(strict_types=1);

namespace Modules\Admin\app\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Models\User;
use Modules\Credits\app\Models\OrganizerCreditWallet;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerSubscription;
use Modules\Organizer\app\Models\SubscriptionPlan;
use Modules\Payments\app\Enums\OrderStatus;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Models\Payout;

class AdminOrganizerService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Paginated list of organizers with filters and metrics.
     *
     * @param  array{search?: string, status?: string, is_verified?: string, per_page?: int}  $filters
     */
    public function getOrganizers(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        $query = Organizer::query()->with([
            'user:id,first_name,last_name,email',
            'bank',
            'members.user:id,first_name,last_name,email',
        ]);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%")
                    ->orWhere('uuid', 'LIKE', "%{$search}%");
            });
        }

        if (isset($filters['status'])) {
            if ($filters['status'] === 'suspended') {
                $query->whereNotNull('suspended_at');
            } elseif ($filters['status'] === 'active') {
                $query->whereNull('suspended_at')->where('is_active', true);
            }
        }

        if (isset($filters['is_verified'])) {
            $isVerified = filter_var($filters['is_verified'], FILTER_VALIDATE_BOOLEAN);
            if ($isVerified) {
                $query->where('verification_status', 'verified');
            } else {
                $query->where('verification_status', '!=', 'verified');
            }
        }

        return $query->withCount(['events'])->latest()->paginate($perPage);
    }

    /**
     * Get deep-dive tenant dossier.
     *
     * @return array<string, mixed>
     */
    public function getOrganizerDossier(string $organizerUuid): array
    {
        $organizer = Organizer::with(['user:id,first_name,last_name,email,created_at', 'bank', 'members.user:id,first_name,last_name,email'])
            ->where('uuid', $organizerUuid)
            ->firstOrFail();

        // Financial metrics
        $paidOrders = Order::query()
            ->where('organizer_id', $organizer->id)
            ->where('status', OrderStatus::Paid);

        $totalGmv = (float) (clone $paidOrders)->sum('total');
        $totalSubtotal = (float) (clone $paidOrders)->sum('subtotal');
        $totalDiscount = (float) (clone $paidOrders)->sum('discount');
        $totalFees = (float) (clone $paidOrders)->sum('service_fee');
        $paidOrdersCount = (int) (clone $paidOrders)->count();
        $netEarnings = max(0.0, round($totalSubtotal - $totalDiscount, 2));

        $payoutsQuery = Payout::query()->where('organizer_id', $organizer->id);
        $totalPaidOut = (float) (clone $payoutsQuery)->where('status', 'processed')->sum('amount');
        $pendingPayouts = (float) (clone $payoutsQuery)->where('status', 'pending')->sum('amount');
        $availableBalance = max(0.0, round($netEarnings - $totalPaidOut, 2));

        // Credit Wallet balance
        $wallet = OrganizerCreditWallet::where('organizer_id', $organizer->id)->first();
        $creditBalance = $wallet ? (float) $wallet->balance : 0.0;

        // Recent events
        $events = Event::query()
            ->where('organizer_id', $organizer->id)
            ->latest()
            ->take(5)
            ->get(['id', 'uuid', 'title', 'slug', 'status', 'is_featured', 'created_at']);

        return [
            'organizer' => $organizer,
            'subscription' => [
                'effective_tier' => $organizer->effectivePlanTier()->value,
                'effective_tier_label' => $organizer->effectivePlanTier()->label(),
                'is_paid_active' => $organizer->hasActivePaidPlan(),
                'plan_expires_at' => $organizer->plan_expires_at?->toIso8601String(),
                'custom_fee_percentage' => $organizer->custom_fee_percentage,
                'custom_fee_fixed' => $organizer->custom_fee_fixed,
            ],
            'financials' => [
                'gmv' => round($totalGmv, 2),
                'net_earnings' => $netEarnings,
                'total_fees_paid' => round($totalFees, 2),
                'paid_orders_count' => $paidOrdersCount,
                'available_balance' => $availableBalance,
                'total_paid_out' => round($totalPaidOut, 2),
                'pending_payouts' => round($pendingPayouts, 2),
                'credit_balance' => $creditBalance,
            ],
            'recent_events' => $events,
            'members' => $organizer->members,
        ];
    }

    /**
     * Update an organizer's subscription plan tier or custom fee rate override.
     *
     * @param  array{plan_tier?: string, duration_days?: int|null, custom_fee_percentage?: float|null, custom_fee_fixed?: float|null}  $data
     */
    public function updatePlanAndFees(string $organizerUuid, User $adminUser, array $data): Organizer
    {
        $organizer = Organizer::where('uuid', $organizerUuid)->firstOrFail();

        $updates = [];

        if (isset($data['plan_tier'])) {
            $updates['plan_tier'] = strtolower((string) $data['plan_tier']);

            if ($updates['plan_tier'] === 'free') {
                $updates['plan_expires_at'] = null;
            } elseif (array_key_exists('duration_days', $data) && $data['duration_days'] !== null) {
                $days = max(1, (int) $data['duration_days']);
                $updates['plan_expires_at'] = now()->addDays($days);
            } elseif ($organizer->plan_expires_at === null || $organizer->plan_expires_at->isPast()) {
                $updates['plan_expires_at'] = now()->addDays(30);
            }
        }

        if (array_key_exists('custom_fee_percentage', $data)) {
            $updates['custom_fee_percentage'] = $data['custom_fee_percentage'] !== null
                ? (float) $data['custom_fee_percentage']
                : null;
        }

        if (array_key_exists('custom_fee_fixed', $data)) {
            $updates['custom_fee_fixed'] = $data['custom_fee_fixed'] !== null
                ? (float) $data['custom_fee_fixed']
                : null;
        }

        if (! empty($updates)) {
            $organizer->update($updates);
        }

        if (isset($updates['plan_tier']) && $updates['plan_tier'] !== 'free') {
            $dbPlan = SubscriptionPlan::where('slug', $updates['plan_tier'])->first();
            OrganizerSubscription::create([
                'organizer_id' => $organizer->id,
                'subscription_plan_id' => $dbPlan?->id,
                'created_by' => $adminUser->id,
                'plan_slug' => $updates['plan_tier'],
                'billing_cycle' => 'custom',
                'payment_method' => 'admin_grant',
                'amount_paid' => 0.00,
                'credits_paid' => 0,
                'currency' => 'NGN',
                'status' => 'active',
                'auto_renew' => false,
                'starts_at' => now(),
                'ends_at' => $organizer->plan_expires_at,
                'metadata' => [
                    'granted_by_admin' => $adminUser->email,
                    'duration_days' => $data['duration_days'] ?? 30,
                ],
            ]);
        }

        $this->auditService->log(
            action: 'organizer.plan_updated',
            description: "Updated subscription plan / custom fee configuration for organizer [{$organizer->name}] ({$organizer->uuid})",
            organizer: $organizer,
            user: $adminUser,
            metadata: $updates,
            targetType: 'Organizer',
            targetId: $organizer->uuid,
        );

        return $organizer->fresh();
    }

    /**
     * List all subscription plans configured on the platform.
     *
     * @return array<int, SubscriptionPlan>
     */
    public function getSubscriptionPlans(): array
    {
        return SubscriptionPlan::orderBy('sort_order')->get()->all();
    }

    /**
     * Update a subscription plan's pricing, credit cost, fee rate, or limits.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSubscriptionPlan(string $slug, User $adminUser, array $data): SubscriptionPlan
    {
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();
        $plan->update($data);

        $this->auditService->log(
            action: 'subscription_plan.updated',
            description: "Updated subscription plan [{$plan->name}] ({$plan->slug})",
            user: $adminUser,
            metadata: $data,
            targetType: 'SubscriptionPlan',
            targetId: $plan->slug,
        );

        return $plan->fresh();
    }

    /**
     * Suspend an organizer tenant.
     */
    public function suspendOrganizer(string $organizerUuid, User $adminUser, string $reason): Organizer
    {
        $organizer = Organizer::where('uuid', $organizerUuid)->firstOrFail();

        $organizer->update([
            'is_active' => false,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);

        $this->auditService->log(
            action: 'organizer.suspended',
            description: "Suspended organizer [{$organizer->name}] ({$organizer->uuid}) with reason: {$reason}",
            user: $adminUser,
            targetType: 'Organizer',
            targetId: $organizer->uuid,
            reason: $reason,
        );

        return $organizer;
    }

    /**
     * Unsuspend / reactivate an organizer tenant.
     */
    public function unsuspendOrganizer(string $organizerUuid, User $adminUser): Organizer
    {
        $organizer = Organizer::where('uuid', $organizerUuid)->firstOrFail();

        $organizer->update([
            'is_active' => true,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        $this->auditService->log(
            action: 'organizer.unsuspended',
            description: "Reactivated suspended organizer [{$organizer->name}] ({$organizer->uuid})",
            user: $adminUser,
            targetType: 'Organizer',
            targetId: $organizer->uuid,
        );

        return $organizer;
    }
}
