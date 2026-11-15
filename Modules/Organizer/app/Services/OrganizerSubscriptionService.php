<?php

declare(strict_types=1);

namespace Modules\Organizer\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Models\User;
use Modules\Credits\app\Enums\CreditTxReason;
use Modules\Credits\app\Services\CreditLedgerService;
use Modules\Organizer\app\Enum\OrganizerPlanTier;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerSubscription;
use Modules\Organizer\app\Models\SubscriptionPlan;
use Modules\Shared\app\Contracts\AppSettingsServiceInterface;

class OrganizerSubscriptionService
{
    public function __construct(
        protected CreditLedgerService $creditLedgerService,
        protected AuditService $auditService,
        protected ?AppSettingsServiceInterface $settingsService = null,
    ) {}

    /**
     * Get the current subscription status, effective fee rates, billing history, and available plans for an organizer.
     *
     * @return array<string, mixed>
     */
    public function getSubscriptionStatus(Organizer $organizer): array
    {
        $settings = ($this->settingsService ?? app(AppSettingsServiceInterface::class))?->getAll();
        $globalPercentage = $settings ? (float) $settings->platform_fee_percentage : 0.0;
        $globalFixed = $settings ? (float) $settings->platform_fee_fixed : 0.0;

        $effectiveTier = $organizer->effectivePlanTier();
        $effectiveFee = $organizer->resolveEffectiveFeeRate($globalPercentage, $globalFixed);
        $wallet = $this->creditLedgerService->getOrCreateWallet($organizer);

        $latestSub = OrganizerSubscription::where('organizer_id', $organizer->id)
            ->latest('id')
            ->first();

        $dbPlans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $plans = $dbPlans->isNotEmpty()
            ? $dbPlans->map(fn (SubscriptionPlan $plan): array => [
                'id' => $plan->id,
                'uuid' => $plan->uuid,
                'tier' => $plan->slug,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'description' => $plan->description,
                'monthly_price_ngn' => (float) $plan->monthly_price_ngn,
                'annual_price_ngn' => (float) $plan->annual_price_ngn,
                'monthly_cost_credits' => (int) $plan->monthly_cost_credits,
                'annual_cost_credits' => (int) $plan->annual_cost_credits,
                'fee_percentage' => $plan->fee_percentage !== null ? (float) $plan->fee_percentage : null,
                'fee_fixed' => $plan->fee_fixed !== null ? (float) $plan->fee_fixed : null,
                'max_staff_members' => (int) $plan->max_staff_members,
                'max_active_events' => (int) $plan->max_active_events,
                'features' => $plan->features ?? [],
            ])->values()->all()
            : OrganizerPlanTier::catalog();

        $history = OrganizerSubscription::where('organizer_id', $organizer->id)
            ->latest('id')
            ->take(10)
            ->get()
            ->map(fn (OrganizerSubscription $sub): array => [
                'uuid' => $sub->uuid,
                'reference' => $sub->reference,
                'plan_slug' => $sub->plan_slug,
                'billing_cycle' => $sub->billing_cycle,
                'payment_method' => $sub->payment_method,
                'amount_paid' => (float) $sub->amount_paid,
                'credits_paid' => (int) $sub->credits_paid,
                'status' => $sub->status,
                'auto_renew' => (bool) $sub->auto_renew,
                'starts_at' => $sub->starts_at?->toIso8601String(),
                'ends_at' => $sub->ends_at?->toIso8601String(),
                'cancelled_at' => $sub->cancelled_at?->toIso8601String(),
                'created_at' => $sub->created_at?->toIso8601String(),
            ])->values()->all();

        return [
            'current_plan' => [
                'tier' => $effectiveTier->value,
                'name' => $effectiveTier->label(),
                'is_paid_active' => $organizer->hasActivePaidPlan(),
                'expires_at' => $organizer->plan_expires_at?->toIso8601String(),
                'max_staff_members' => $organizer->maxAllowedStaffMembers(),
                'status' => $latestSub ? $latestSub->status : 'active',
                'auto_renew' => $latestSub ? (bool) $latestSub->auto_renew : false,
            ],
            'effective_fee' => [
                'percentage' => $effectiveFee['percentage'],
                'fixed' => $effectiveFee['fixed'],
                'source' => $effectiveFee['source'],
                'global_default_percentage' => $globalPercentage,
                'global_default_fixed' => $globalFixed,
            ],
            'wallet' => [
                'available_credits' => (int) $wallet->available_balance,
                'balance' => (int) $wallet->balance,
            ],
            'plans' => $plans,
            'history' => $history,
        ];
    }

    /**
     * Upgrade or renew an organizer's subscription tier using their Credits wallet.
     *
     * @return array<string, mixed>
     */
    public function upgradeWithCredits(
        Organizer $organizer,
        User $actor,
        OrganizerPlanTier $targetTier,
        int $months = 1,
        string $billingCycle = 'monthly'
    ): array {
        if ($targetTier === OrganizerPlanTier::Free) {
            throw new InvalidArgumentException('Cannot purchase the Free tier with credits.');
        }

        if ($targetTier === OrganizerPlanTier::Enterprise) {
            throw new InvalidArgumentException('Enterprise is a custom plan. Please contact sales or administration for bespoke pricing and terms.');
        }

        $months = min(max($months, 1), 12);
        $dbPlan = SubscriptionPlan::where('slug', $targetTier->value)->first();

        if ($billingCycle === 'annual') {
            $months = 12;
            $totalCreditsRequired = $dbPlan
                ? (int) $dbPlan->annual_cost_credits
                : $targetTier->monthlyCostCredits() * 10;
            $equivalentNgn = $dbPlan
                ? (float) $dbPlan->annual_price_ngn
                : $targetTier->monthlyPriceNgn() * 10;
            $durationDays = 365;
        } else {
            $unitCredits = $dbPlan
                ? (int) $dbPlan->monthly_cost_credits
                : $targetTier->monthlyCostCredits();
            $unitNgn = $dbPlan
                ? (float) $dbPlan->monthly_price_ngn
                : $targetTier->monthlyPriceNgn();
            $totalCreditsRequired = $unitCredits * $months;
            $equivalentNgn = $unitNgn * $months;
            $durationDays = 30 * $months;
        }

        return DB::transaction(function () use (
            $organizer,
            $actor,
            $targetTier,
            $dbPlan,
            $months,
            $billingCycle,
            $totalCreditsRequired,
            $equivalentNgn,
            $durationDays
        ): array {
            /** @var Organizer $lockedOrganizer */
            $lockedOrganizer = Organizer::where('id', $organizer->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->creditLedgerService->getOrCreateWallet($lockedOrganizer);

            $reference = 'SUB-'.strtoupper(Str::random(12));
            $idempotencyKey = "org_sub_{$lockedOrganizer->id}_{$targetTier->value}_{$months}_".Str::uuid();

            $this->creditLedgerService->debitCreditsSync(
                wallet: $wallet,
                amount: $totalCreditsRequired,
                reason: CreditTxReason::PLAN_SUBSCRIPTION,
                idempotencyKey: $idempotencyKey,
                reference: $reference,
                description: "Subscribed to {$targetTier->label()} plan ({$billingCycle}) for {$months} month(s)",
                metadata: [
                    'plan_tier' => $targetTier->value,
                    'billing_cycle' => $billingCycle,
                    'months' => $months,
                    'actor_id' => $actor->id,
                ]
            );

            $startsAt = ($lockedOrganizer->hasActivePaidPlan()
                && $lockedOrganizer->effectivePlanTier() === $targetTier
                && $lockedOrganizer->plan_expires_at !== null
                && $lockedOrganizer->plan_expires_at->isFuture())
                ? $lockedOrganizer->plan_expires_at
                : now();

            $newExpiresAt = $startsAt->copy()->addDays($durationDays);

            // Mark previous active subscriptions as superseded if upgrading to a different tier
            OrganizerSubscription::where('organizer_id', $lockedOrganizer->id)
                ->where('status', 'active')
                ->update(['auto_renew' => false]);

            OrganizerSubscription::create([
                'organizer_id' => $lockedOrganizer->id,
                'subscription_plan_id' => $dbPlan?->id,
                'created_by' => $actor->id,
                'plan_slug' => $targetTier->value,
                'billing_cycle' => $billingCycle,
                'payment_method' => 'credits',
                'amount_paid' => $equivalentNgn,
                'credits_paid' => $totalCreditsRequired,
                'currency' => 'NGN',
                'status' => 'active',
                'auto_renew' => true,
                'reference' => $reference,
                'starts_at' => now(),
                'ends_at' => $newExpiresAt,
                'metadata' => [
                    'months' => $months,
                    'duration_days' => $durationDays,
                ],
            ]);

            $lockedOrganizer->update([
                'plan_tier' => $targetTier->value,
                'plan_expires_at' => $newExpiresAt,
            ]);

            $this->auditService->log(
                action: 'organizer.subscription_upgraded',
                description: "Organizer [{$lockedOrganizer->name}] upgraded to {$targetTier->label()} for {$months} month(s) using {$totalCreditsRequired} credits.",
                organizer: $lockedOrganizer,
                user: $actor,
                metadata: [
                    'plan_tier' => $targetTier->value,
                    'billing_cycle' => $billingCycle,
                    'months' => $months,
                    'credits_debited' => $totalCreditsRequired,
                    'expires_at' => $newExpiresAt->toIso8601String(),
                ],
                targetType: 'Organizer',
                targetId: $lockedOrganizer->uuid,
            );

            return $this->getSubscriptionStatus($lockedOrganizer->fresh());
        });
    }

    /**
     * Cancel auto-renewal on the organizer's active subscription while retaining benefits until expiry.
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(Organizer $organizer, User $actor): array
    {
        return DB::transaction(function () use ($organizer, $actor): array {
            /** @var Organizer $lockedOrganizer */
            $lockedOrganizer = Organizer::where('id', $organizer->id)->lockForUpdate()->firstOrFail();

            $activeSub = OrganizerSubscription::where('organizer_id', $lockedOrganizer->id)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            if ($activeSub) {
                $activeSub->update([
                    'status' => 'cancelled',
                    'auto_renew' => false,
                    'cancelled_at' => now(),
                ]);
            }

            $this->auditService->log(
                action: 'organizer.subscription_cancelled',
                description: "Organizer [{$lockedOrganizer->name}] cancelled subscription auto-renewal.",
                organizer: $lockedOrganizer,
                user: $actor,
                metadata: [
                    'plan_tier' => $lockedOrganizer->plan_tier,
                    'expires_at' => $lockedOrganizer->plan_expires_at?->toIso8601String(),
                ],
                targetType: 'Organizer',
                targetId: $lockedOrganizer->uuid,
            );

            return $this->getSubscriptionStatus($lockedOrganizer->fresh());
        });
    }

    /**
     * Process expired organizer subscriptions: auto-renew via credits if enabled and funded, or revert to free.
     */
    public function processExpiredSubscriptions(): int
    {
        $expiredOrganizers = Organizer::with('owner')
            ->where('plan_tier', '!=', 'free')
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', now())
            ->get();

        $processed = 0;

        foreach ($expiredOrganizers as $organizer) {
            DB::transaction(function () use ($organizer, &$processed): void {
                $activeSub = OrganizerSubscription::where('organizer_id', $organizer->id)
                    ->whereIn('status', ['active', 'cancelled'])
                    ->latest('id')
                    ->first();

                $renewed = false;
                if ($activeSub && $activeSub->auto_renew && $activeSub->status === 'active' && $organizer->owner) {
                    $tier = OrganizerPlanTier::tryFrom($organizer->plan_tier);
                    if ($tier && $tier !== OrganizerPlanTier::Free) {
                        try {
                            $this->upgradeWithCredits(
                                organizer: $organizer,
                                actor: $organizer->owner,
                                targetTier: $tier,
                                months: 1,
                                billingCycle: $activeSub->billing_cycle === 'annual' ? 'annual' : 'monthly'
                            );
                            $renewed = true;
                        } catch (\Throwable) {
                            $renewed = false;
                        }
                    }
                }

                if (! $renewed) {
                    if ($activeSub) {
                        $activeSub->update([
                            'status' => 'expired',
                            'auto_renew' => false,
                        ]);
                    }

                    $organizer->update([
                        'plan_tier' => 'free',
                        'plan_expires_at' => null,
                    ]);
                }

                $processed++;
            });
        }

        return $processed;
    }
}
