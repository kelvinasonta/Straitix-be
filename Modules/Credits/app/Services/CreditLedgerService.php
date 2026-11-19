<?php

namespace Modules\Credits\app\Services;

use Illuminate\Support\Facades\DB;
use Modules\Credits\app\Enums\CreditTxReason;
use Modules\Credits\app\Enums\CreditTxType;
use Modules\Credits\app\Enums\ReservationStatus;
use Modules\Credits\app\Exceptions\InsufficientCreditsException;
use Modules\Credits\app\Exceptions\InvalidReservationException;
use Modules\Credits\app\Models\CreditLedger;
use Modules\Credits\app\Models\CreditReservation;
use Modules\Credits\app\Models\OrganizerCreditWallet;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Models\Organizer;

class CreditLedgerService
{
    /**
     * Get or create the credit wallet for an organizer.
     */
    public function getOrCreateWallet(Organizer $organizer): OrganizerCreditWallet
    {
        return OrganizerCreditWallet::firstOrCreate(
            ['organizer_id' => $organizer->id],
            [
                'balance' => 0,
                'reserved_balance' => 0,
                'low_balance_threshold' => 500,
                'low_balance_alert_enabled' => true,
                'currency' => 'NGN',
                'version' => 1,
            ]
        );
    }

    /**
     * Update low-balance threshold and alert settings for a credit wallet.
     */
    public function updateWalletSettings(
        OrganizerCreditWallet $wallet,
        int $lowBalanceThreshold,
        bool $lowBalanceAlertEnabled
    ): OrganizerCreditWallet {
        $wallet->update([
            'low_balance_threshold' => max(0, $lowBalanceThreshold),
            'low_balance_alert_enabled' => $lowBalanceAlertEnabled,
        ]);

        return $wallet->fresh();
    }

    /**
     * Credit the organizer's wallet and record a ledger entry.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function creditWallet(
        OrganizerCreditWallet $wallet,
        int $amount,
        CreditTxReason $reason,
        string $idempotencyKey,
        string $reference,
        string $description,
        array $metadata = [],
        ?Event $event = null
    ): CreditLedger {
        return DB::transaction(function () use ($wallet, $amount, $reason, $idempotencyKey, $reference, $description, $metadata, $event): CreditLedger {
            // Check idempotency first: if already processed, return existing ledger entry
            $existing = CreditLedger::where('wallet_id', $wallet->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            // Pessimistic Row Lock
            /** @var OrganizerCreditWallet $lockedWallet */
            $lockedWallet = OrganizerCreditWallet::where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = $lockedWallet->balance;
            $balanceAfter = $balanceBefore + $amount;

            $threshold = (int) ($lockedWallet->low_balance_threshold ?? 500);
            $walletUpdates = [
                'balance' => $balanceAfter,
                'version' => $lockedWallet->version + 1,
            ];
            if ($balanceAfter > $threshold && $lockedWallet->last_low_balance_alert_at !== null) {
                $walletUpdates['last_low_balance_alert_at'] = null;
            }

            $lockedWallet->update($walletUpdates);

            return CreditLedger::create([
                'wallet_id' => $lockedWallet->id,
                'organizer_id' => $lockedWallet->organizer_id,
                'event_id' => $event?->id,
                'type' => CreditTxType::CREDIT,
                'reason' => $reason,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'idempotency_key' => $idempotencyKey,
                'reference' => $reference,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Synchronously debit the organizer's wallet and record a ledger entry.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InsufficientCreditsException
     */
    public function debitCreditsSync(
        OrganizerCreditWallet $wallet,
        int $amount,
        CreditTxReason $reason,
        string $idempotencyKey,
        string $reference,
        string $description,
        array $metadata = [],
        ?Event $event = null
    ): CreditLedger {
        return DB::transaction(function () use ($wallet, $amount, $reason, $idempotencyKey, $reference, $description, $metadata, $event): CreditLedger {
            // Check idempotency first: if already processed, return existing ledger entry
            $existing = CreditLedger::where('wallet_id', $wallet->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            // Pessimistic Row Lock
            /** @var OrganizerCreditWallet $lockedWallet */
            $lockedWallet = OrganizerCreditWallet::where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $available = $lockedWallet->balance - $lockedWallet->reserved_balance;
            if ($available < $amount) {
                throw new InsufficientCreditsException($amount, $available);
            }

            $balanceBefore = $lockedWallet->balance;
            $balanceAfter = $balanceBefore - $amount;

            $lockedWallet->update([
                'balance' => $balanceAfter,
                'version' => $lockedWallet->version + 1,
            ]);

            $this->checkAndDispatchLowBalanceAlert($lockedWallet, $balanceBefore, $balanceAfter);

            return CreditLedger::create([
                'wallet_id' => $lockedWallet->id,
                'organizer_id' => $lockedWallet->organizer_id,
                'event_id' => $event?->id,
                'type' => CreditTxType::DEBIT,
                'reason' => $reason,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'idempotency_key' => $idempotencyKey,
                'reference' => $reference,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Reserve credits from the wallet (Phase 1).
     *
     * @throws InsufficientCreditsException
     */
    public function reserveCredits(
        OrganizerCreditWallet $wallet,
        int $amount,
        string $referenceType,
        string $referenceId,
        int $ttlMinutes = 60
    ): CreditReservation {
        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $ttlMinutes): CreditReservation {
            /** @var OrganizerCreditWallet $lockedWallet */
            $lockedWallet = OrganizerCreditWallet::where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $available = $lockedWallet->balance - $lockedWallet->reserved_balance;
            if ($available < $amount) {
                throw new InsufficientCreditsException($amount, $available);
            }

            $lockedWallet->increment('reserved_balance', $amount);

            return CreditReservation::create([
                'wallet_id' => $lockedWallet->id,
                'amount' => $amount,
                'status' => ReservationStatus::PENDING,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
        });
    }

    /**
     * Settle a pending credit reservation (Phase 2 Commit).
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidReservationException
     */
    public function settleReservation(
        CreditReservation $reservation,
        int $actualConsumedAmount,
        CreditTxReason $reason,
        string $idempotencyKey,
        string $reference,
        string $description,
        array $metadata = [],
        ?Event $event = null
    ): ?CreditLedger {
        return DB::transaction(function () use (
            $reservation,
            $actualConsumedAmount,
            $reason,
            $idempotencyKey,
            $reference,
            $description,
            $metadata,
            $event
        ): ?CreditLedger {
            /** @var CreditReservation $lockedReservation */
            $lockedReservation = CreditReservation::where('id', $reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->status !== ReservationStatus::PENDING) {
                throw new InvalidReservationException('Reservation is not in PENDING state.');
            }

            if ($actualConsumedAmount > $lockedReservation->amount) {
                throw new InvalidReservationException(
                    "Actual consumed amount ({$actualConsumedAmount}) cannot exceed reserved amount ({$lockedReservation->amount})."
                );
            }

            if ($actualConsumedAmount < 0) {
                throw new InvalidReservationException('Actual consumed amount cannot be negative.');
            }

            /** @var OrganizerCreditWallet $lockedWallet */
            $lockedWallet = OrganizerCreditWallet::where('id', $lockedReservation->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = $lockedWallet->balance;
            $newBalance = $balanceBefore - $actualConsumedAmount;
            $newReserved = max(0, $lockedWallet->reserved_balance - $lockedReservation->amount);

            $lockedWallet->update([
                'balance' => $newBalance,
                'reserved_balance' => $newReserved,
                'version' => $lockedWallet->version + 1,
            ]);

            $lockedReservation->update([
                'status' => ReservationStatus::COMMITTED,
            ]);

            if ($actualConsumedAmount > 0) {
                $this->checkAndDispatchLowBalanceAlert($lockedWallet, $balanceBefore, $newBalance);

                $ledgerMetadata = array_merge($metadata, [
                    'reservation_id' => $lockedReservation->id,
                    'reserved_amount' => $lockedReservation->amount,
                ]);

                return CreditLedger::create([
                    'wallet_id' => $lockedWallet->id,
                    'organizer_id' => $lockedWallet->organizer_id,
                    'event_id' => $event?->id,
                    'type' => CreditTxType::DEBIT,
                    'reason' => $reason,
                    'amount' => $actualConsumedAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $newBalance,
                    'idempotency_key' => $idempotencyKey,
                    'reference' => $reference,
                    'description' => $description,
                    'metadata' => $ledgerMetadata,
                ]);
            }

            return null;
        });
    }

    /**
     * Release a reserved credit amount back to available balance.
     *
     * @throws InvalidReservationException
     */
    public function releaseReservation(CreditReservation $reservation, string $reasonText = 'Explicit release'): void
    {
        DB::transaction(function () use ($reservation): void {
            /** @var CreditReservation $lockedReservation */
            $lockedReservation = CreditReservation::where('id', $reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->status !== ReservationStatus::PENDING) {
                throw new InvalidReservationException('Reservation is not in PENDING state.');
            }

            /** @var OrganizerCreditWallet $lockedWallet */
            $lockedWallet = OrganizerCreditWallet::where('id', $lockedReservation->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $newReserved = max(0, $lockedWallet->reserved_balance - $lockedReservation->amount);

            $lockedWallet->update([
                'reserved_balance' => $newReserved,
                'version' => $lockedWallet->version + 1,
            ]);

            $lockedReservation->update([
                'status' => ReservationStatus::RELEASED,
            ]);
        });
    }

    /**
     * Zombie Reaper: Find all expired pending reservations, release reserved balance, and mark EXPIRED.
     */
    public function reapExpiredReservations(): int
    {
        $expiredReservations = CreditReservation::where('status', ReservationStatus::PENDING)
            ->where('expires_at', '<', now())
            ->get();

        $reapedCount = 0;

        foreach ($expiredReservations as $reservation) {
            DB::transaction(function () use ($reservation, &$reapedCount): void {
                /** @var CreditReservation|null $lockedReservation */
                $lockedReservation = CreditReservation::where('id', $reservation->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedReservation || $lockedReservation->status !== ReservationStatus::PENDING) {
                    return;
                }

                /** @var OrganizerCreditWallet $lockedWallet */
                $lockedWallet = OrganizerCreditWallet::where('id', $lockedReservation->wallet_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newReserved = max(0, $lockedWallet->reserved_balance - $lockedReservation->amount);

                $lockedWallet->update([
                    'reserved_balance' => $newReserved,
                    'version' => $lockedWallet->version + 1,
                ]);

                $lockedReservation->update([
                    'status' => ReservationStatus::EXPIRED,
                ]);

                $reapedCount++;
            });
        }

        return $reapedCount;
    }

    /**
     * Reconciliation Invariant Check:
     * Check if stored balance matches the computed sum from credit_ledger.
     *
     * @return array{valid: bool, stored_balance: int, computed_balance: int, difference: int}
     */
    public function reconcileWallet(OrganizerCreditWallet $wallet): array
    {
        $result = CreditLedger::where('wallet_id', $wallet->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'CREDIT' THEN amount ELSE -amount END), 0) as computed_balance")
            ->first();

        $computedBalance = (int) ($result->computed_balance ?? 0);
        $storedBalance = (int) $wallet->balance;
        $difference = $storedBalance - $computedBalance;

        return [
            'valid' => $difference === 0,
            'stored_balance' => $storedBalance,
            'computed_balance' => $computedBalance,
            'difference' => $difference,
        ];
    }

    /**
     * Compute 30-day usage metering, daily burn rate, estimated runway, and spend breakdown by reason.
     *
     * @return array<string, mixed>
     */
    public function getUsageMetering(OrganizerCreditWallet $wallet, int $days = 30): array
    {
        $since = now()->subDays($days);

        $consumed = (int) CreditLedger::where('wallet_id', $wallet->id)
            ->where('type', CreditTxType::DEBIT)
            ->where('created_at', '>=', $since)
            ->sum('amount');

        $toppedUp = (int) CreditLedger::where('wallet_id', $wallet->id)
            ->where('type', CreditTxType::CREDIT)
            ->where('created_at', '>=', $since)
            ->sum('amount');

        $dailyBurnRate = $days > 0 ? round($consumed / $days, 2) : 0.0;
        $available = (int) $wallet->available_balance;
        $estimatedRunwayDays = $dailyBurnRate > 0.0
            ? (int) floor($available / $dailyBurnRate)
            : null;

        $breakdownRows = CreditLedger::where('wallet_id', $wallet->id)
            ->where('type', CreditTxType::DEBIT)
            ->where('created_at', '>=', $since)
            ->selectRaw('reason, COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as total_credits')
            ->groupBy('reason')
            ->orderByDesc('total_credits')
            ->get();

        $breakdown = $breakdownRows->map(fn ($row): array => [
            'reason' => is_object($row->reason) ? $row->reason->value : (string) $row->reason,
            'tx_count' => (int) $row->tx_count,
            'total_credits' => (int) $row->total_credits,
            'percentage' => $consumed > 0 ? round(((int) $row->total_credits / $consumed) * 100, 1) : 0.0,
        ])->values()->all();

        return [
            'window_days' => $days,
            'credits_consumed_30d' => $consumed,
            'credits_topped_up_30d' => $toppedUp,
            'daily_burn_rate' => $dailyBurnRate,
            'estimated_runway_days' => $estimatedRunwayDays,
            'breakdown_by_reason' => $breakdown,
        ];
    }

    /**
     * Evaluate and dispatch low-balance threshold alert if enabled and breached.
     */
    protected function checkAndDispatchLowBalanceAlert(
        OrganizerCreditWallet $lockedWallet,
        int $balanceBefore,
        int $balanceAfter
    ): void {
        $alertEnabled = (bool) ($lockedWallet->low_balance_alert_enabled ?? true);
        if (! $alertEnabled) {
            return;
        }

        $threshold = (int) ($lockedWallet->low_balance_threshold ?? 500);
        if ($balanceAfter > $threshold) {
            return;
        }

        $crossedDownward = $balanceBefore > $threshold;
        $cooldownElapsed = $lockedWallet->last_low_balance_alert_at === null
            || $lockedWallet->last_low_balance_alert_at->diffInHours(now()) >= 24;

        if (! $crossedDownward && ! $cooldownElapsed) {
            return;
        }

        $lockedWallet->update([
            'last_low_balance_alert_at' => now(),
        ]);

        try {
            $organizer = $lockedWallet->relationLoaded('organizer')
                ? $lockedWallet->organizer
                : Organizer::find($lockedWallet->organizer_id);

            if ($organizer && $organizer->user_id && class_exists(\Modules\Notification\app\Models\InAppNotification::class)) {
                \Modules\Notification\app\Models\InAppNotification::create([
                    'user_id' => $organizer->user_id,
                    'type' => 'billing.low_balance',
                    'title' => 'Low Credit Balance Alert',
                    'message' => "Your {$organizer->name} wallet balance is {$balanceAfter} credits (below your {$threshold} credit alert threshold). Top up to prevent campaign or renewal interruptions.",
                    'action_url' => '/organizer/credits',
                    'icon' => 'alert-triangle',
                    'data' => [
                        'organizer_id' => $organizer->id,
                        'organizer_uuid' => $organizer->uuid,
                        'balance_after' => $balanceAfter,
                        'threshold' => $threshold,
                    ],
                ]);
            }
        } catch (\Throwable) {
            // Never fail the financial ledger transaction if notification dispatch encounters an issue
        }
    }
}
