<?php

declare(strict_types=1);

namespace Modules\Organizer\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Models\User;
use Modules\Credits\app\Enums\CreditTxReason;
use Modules\Credits\app\Models\CreditTopupTransaction;
use Modules\Credits\app\Services\CreditLedgerService;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Enum\OrganizerPlanTier;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerInvitation;
use Modules\Organizer\app\Models\OrganizerMember;
use Modules\Organizer\app\Models\OrganizerSubscription;
use Modules\Organizer\app\Models\SubscriptionPlan;
use Modules\Shared\app\Contracts\AppSettingsServiceInterface;

class OrganizerSubscriptionService
{
    public const GRACE_PERIOD_DAYS = 3;

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
            ->take(20)
            ->get()
            ->map(fn (OrganizerSubscription $sub): array => [
                'uuid' => $sub->uuid,
                'reference' => $sub->reference,
                'invoice_number' => $sub->invoice_number ?? ('INV-'.strtoupper(substr(md5((string) $sub->reference), 0, 8))),
                'plan_slug' => $sub->plan_slug,
                'billing_cycle' => $sub->billing_cycle,
                'payment_method' => $sub->payment_method,
                'amount_paid' => (float) $sub->amount_paid,
                'credits_paid' => (int) $sub->credits_paid,
                'currency' => $sub->currency ?: 'NGN',
                'status' => $sub->status,
                'auto_renew' => (bool) $sub->auto_renew,
                'starts_at' => $sub->starts_at?->toIso8601String(),
                'ends_at' => $sub->ends_at?->toIso8601String(),
                'grace_ends_at' => $sub->grace_ends_at?->toIso8601String(),
                'cancelled_at' => $sub->cancelled_at?->toIso8601String(),
                'created_at' => $sub->created_at?->toIso8601String(),
            ])->values()->all();

        $topupHistory = CreditTopupTransaction::where('wallet_id', $wallet->id)
            ->latest('id')
            ->take(20)
            ->get()
            ->map(fn (CreditTopupTransaction $tx): array => [
                'uuid' => $tx->uuid,
                'reference' => $tx->provider_ref,
                'invoice_number' => 'TOP-'.strtoupper(substr(md5((string) $tx->provider_ref), 0, 8)),
                'provider' => $tx->provider,
                'amount_ngn' => round(((int) $tx->amount_kobo) / 100, 2),
                'credits_awarded' => (int) $tx->credits_awarded,
                'status' => strtolower((string) $tx->status),
                'created_at' => $tx->created_at?->toIso8601String(),
            ])->values()->all();

        $maxStaff = $organizer->maxAllowedStaffMembers();
        $usedStaff = OrganizerMember::where('organizer_id', $organizer->id)
            ->where('role', '!=', 'owner')
            ->count()
            + OrganizerInvitation::where('organizer_id', $organizer->id)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->count();

        $maxActiveEvents = $organizer->maxAllowedActiveEvents();
        $usedActiveEvents = Event::where('organizer_id', $organizer->id)
            ->whereIn('status', ['draft', 'published'])
            ->count();

        $threshold = (int) ($wallet->low_balance_threshold ?? 500);
        $alertEnabled = (bool) ($wallet->low_balance_alert_enabled ?? true);
        $inGracePeriod = $organizer->isInGracePeriod();

        return [
            'current_plan' => [
                'tier' => $effectiveTier->value,
                'name' => $effectiveTier->label(),
                'is_paid_active' => $organizer->hasActivePaidPlan(),
                'in_grace_period' => $inGracePeriod,
                'expires_at' => $organizer->plan_expires_at?->toIso8601String(),
                'grace_ends_at' => $organizer->plan_grace_ends_at?->toIso8601String(),
                'max_staff_members' => $maxStaff,
                'max_active_events' => $maxActiveEvents,
                'status' => $inGracePeriod ? 'past_due' : ($latestSub ? $latestSub->status : 'active'),
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
                'reserved_balance' => (int) $wallet->reserved_balance,
                'low_balance_threshold' => $threshold,
                'low_balance_alert_enabled' => $alertEnabled,
                'is_below_threshold' => $alertEnabled && (int) $wallet->available_balance <= $threshold,
            ],
            'usage_quotas' => [
                'staff_members' => [
                    'used' => $usedStaff,
                    'limit' => $maxStaff,
                    'percentage' => round(min(100.0, ($usedStaff / max(1, $maxStaff)) * 100), 1),
                ],
                'active_events' => [
                    'used' => $usedActiveEvents,
                    'limit' => $maxActiveEvents,
                    'percentage' => round(min(100.0, ($usedActiveEvents / max(1, $maxActiveEvents)) * 100), 1),
                ],
            ],
            'usage_metering' => $this->creditLedgerService->getUsageMetering($wallet, 30),
            'plans' => $plans,
            'history' => $history,
            'topup_history' => $topupHistory,
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

            // Mark previous active or past_due subscriptions as superseded
            OrganizerSubscription::where('organizer_id', $lockedOrganizer->id)
                ->whereIn('status', ['active', 'past_due'])
                ->update([
                    'status' => 'superseded',
                    'auto_renew' => false,
                ]);

            $invoiceNumber = 'INV-'.now()->format('Ym').'-'.strtoupper(Str::random(6));

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
                'invoice_number' => $invoiceNumber,
                'starts_at' => now(),
                'ends_at' => $newExpiresAt,
                'grace_ends_at' => null,
                'metadata' => [
                    'months' => $months,
                    'duration_days' => $durationDays,
                ],
            ]);

            $lockedOrganizer->update([
                'plan_tier' => $targetTier->value,
                'plan_expires_at' => $newExpiresAt,
                'plan_grace_ends_at' => null,
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
                ->whereIn('status', ['active', 'past_due'])
                ->latest('id')
                ->first();

            if ($activeSub) {
                $activeSub->update([
                    'status' => 'cancelled',
                    'auto_renew' => false,
                    'cancelled_at' => now(),
                ]);
            }

            if ($lockedOrganizer->isInGracePeriod()) {
                $lockedOrganizer->update([
                    'plan_tier' => 'free',
                    'plan_expires_at' => null,
                    'plan_grace_ends_at' => null,
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
     * Resume auto-renewal on a cancelled subscription or retry renewal if in grace period.
     *
     * @return array<string, mixed>
     */
    public function resumeSubscription(Organizer $organizer, User $actor): array
    {
        $latestSub = OrganizerSubscription::where('organizer_id', $organizer->id)
            ->whereIn('status', ['cancelled', 'past_due', 'active'])
            ->latest('id')
            ->first();

        if (! $latestSub) {
            throw new InvalidArgumentException('No active or cancelled subscription found to resume.');
        }

        // If currently in grace period (past_due), immediately attempt renewal with credits
        if ($organizer->isInGracePeriod() || $latestSub->status === 'past_due') {
            $tier = OrganizerPlanTier::tryFrom((string) $organizer->plan_tier) ?? OrganizerPlanTier::Pro;

            return $this->upgradeWithCredits(
                organizer: $organizer,
                actor: $actor,
                targetTier: $tier,
                months: $latestSub->billing_cycle === 'annual' ? 12 : 1,
                billingCycle: $latestSub->billing_cycle === 'annual' ? 'annual' : 'monthly'
            );
        }

        return DB::transaction(function () use ($organizer, $actor, $latestSub): array {
            /** @var Organizer $lockedOrganizer */
            $lockedOrganizer = Organizer::where('id', $organizer->id)->lockForUpdate()->firstOrFail();

            $latestSub->update([
                'status' => 'active',
                'auto_renew' => true,
                'cancelled_at' => null,
            ]);

            $this->auditService->log(
                action: 'organizer.subscription_resumed',
                description: "Organizer [{$lockedOrganizer->name}] resumed subscription auto-renewal.",
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
     * Process expired organizer subscriptions:
     * 1. Auto-renew via credits if enabled and funded.
     * 2. If auto-renew fails due to insufficient credits, grant a 3-day grace period ('past_due') while retaining plan benefits.
     * 3. Once grace period expires (or if auto-renew was cancelled), downgrade to Free tier.
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
                    ->whereIn('status', ['active', 'past_due', 'cancelled'])
                    ->latest('id')
                    ->first();

                $renewed = false;
                if (
                    $activeSub
                    && $activeSub->auto_renew
                    && in_array($activeSub->status, ['active', 'past_due'], true)
                    && $organizer->owner
                ) {
                    $tier = OrganizerPlanTier::tryFrom((string) $organizer->plan_tier);
                    if ($tier && $tier !== OrganizerPlanTier::Free) {
                        try {
                            $this->upgradeWithCredits(
                                organizer: $organizer,
                                actor: $organizer->owner,
                                targetTier: $tier,
                                months: $activeSub->billing_cycle === 'annual' ? 12 : 1,
                                billingCycle: $activeSub->billing_cycle === 'annual' ? 'annual' : 'monthly'
                            );
                            $renewed = true;
                        } catch (\Throwable) {
                            $renewed = false;
                        }
                    }
                }

                if (! $renewed) {
                    // Case A: Auto-renew was enabled and organizer has not yet entered grace period -> enter 3-day grace period
                    if (
                        $activeSub
                        && $activeSub->auto_renew
                        && $activeSub->status === 'active'
                        && $organizer->plan_grace_ends_at === null
                    ) {
                        $graceEndsAt = now()->addDays(self::GRACE_PERIOD_DAYS);

                        $activeSub->update([
                            'status' => 'past_due',
                            'grace_ends_at' => $graceEndsAt,
                        ]);

                        $organizer->update([
                            'plan_grace_ends_at' => $graceEndsAt,
                        ]);

                        $this->auditService->log(
                            action: 'organizer.subscription_past_due',
                            description: "Organizer [{$organizer->name}] subscription auto-renewal failed (insufficient credits). Entered ".self::GRACE_PERIOD_DAYS.'-day grace period.',
                            organizer: $organizer,
                            user: $organizer->owner,
                            metadata: [
                                'plan_tier' => $organizer->plan_tier,
                                'grace_ends_at' => $graceEndsAt->toIso8601String(),
                            ],
                            targetType: 'Organizer',
                            targetId: $organizer->uuid,
                        );

                        try {
                            if ($organizer->user_id && class_exists(\Modules\Notification\app\Models\InAppNotification::class)) {
                                \Modules\Notification\app\Models\InAppNotification::create([
                                    'user_id' => $organizer->user_id,
                                    'type' => 'billing.subscription_past_due',
                                    'title' => 'Subscription Renewal Past Due (Grace Period)',
                                    'message' => "Your {$organizer->name} Pro subscription could not auto-renew due to insufficient credits. You have a ".self::GRACE_PERIOD_DAYS.'-day grace period to top up your wallet before your plan reverts to Free.',
                                    'action_url' => '/organizer/credits',
                                    'icon' => 'alert-triangle',
                                    'data' => [
                                        'organizer_id' => $organizer->id,
                                        'organizer_uuid' => $organizer->uuid,
                                        'grace_ends_at' => $graceEndsAt->toIso8601String(),
                                    ],
                                ]);
                            }
                        } catch (\Throwable) {
                            // Ignore notification error
                        }
                    } elseif (
                        $activeSub
                        && $activeSub->auto_renew
                        && $organizer->plan_grace_ends_at !== null
                        && $organizer->plan_grace_ends_at->isFuture()
                    ) {
                        // Case B: Still inside active grace window; retain Pro benefits until grace_ends_at expires
                    } else {
                        // Case C: Cancelled auto-renew OR grace period has elapsed -> expire subscription & revert to Free
                        if ($activeSub) {
                            $activeSub->update([
                                'status' => 'expired',
                                'auto_renew' => false,
                            ]);
                        }

                        $organizer->update([
                            'plan_tier' => 'free',
                            'plan_expires_at' => null,
                            'plan_grace_ends_at' => null,
                        ]);
                    }
                }

                $processed++;
            });
        }

        return $processed;
    }
}
