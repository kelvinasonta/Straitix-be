<?php

declare(strict_types=1);

namespace Modules\Credits\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Credits\app\DTOs\GatewayCheckoutDTO;
use Modules\Credits\app\Enums\CreditTxReason;
use Modules\Credits\app\Models\CreditTopupTransaction;
use Modules\Credits\app\Models\OrganizerCreditWallet;
use RuntimeException;

class CreditTopupService
{
    public function __construct(
        protected CreditLedgerService $ledgerService,
        protected CreditGatewayManager $gatewayManager
    ) {}

    /**
     * Retrieve all active credit top-up packages.
     *
     * @return array<int, array{id: string, name: string, credits: int, price_kobo: int, currency: string, discount_label: string|null, is_popular: bool}>
     */
    public function getActivePackages(): array
    {
        $configured = config('credits.packages');
        if (! is_array($configured) || empty($configured)) {
            $configured = [
                [
                    'id' => 'starter',
                    'name' => 'Starter Pack',
                    'credits' => 100,
                    'price_kobo' => 100000,
                    'currency' => 'NGN',
                    'discount_label' => null,
                    'is_popular' => false,
                    'is_active' => true,
                ],
                [
                    'id' => 'growth',
                    'name' => 'Growth Pack',
                    'credits' => 500,
                    'price_kobo' => 450000,
                    'currency' => 'NGN',
                    'discount_label' => 'Save 10%',
                    'is_popular' => true,
                    'is_active' => true,
                ],
                [
                    'id' => 'power',
                    'name' => 'Pro Accelerator',
                    'credits' => 1500,
                    'price_kobo' => 1200000,
                    'currency' => 'NGN',
                    'discount_label' => 'Save 20%',
                    'is_popular' => false,
                    'is_active' => true,
                ],
            ];
        }

        $active = [];
        foreach ($configured as $pkg) {
            if (($pkg['is_active'] ?? true) === false) {
                continue;
            }
            $active[] = [
                'id' => (string) $pkg['id'],
                'name' => (string) $pkg['name'],
                'credits' => (int) $pkg['credits'],
                'price_kobo' => (int) $pkg['price_kobo'],
                'currency' => (string) ($pkg['currency'] ?? 'NGN'),
                'discount_label' => $pkg['discount_label'] ?? null,
                'is_popular' => (bool) ($pkg['is_popular'] ?? false),
            ];
        }

        return $active;
    }

    /**
     * Find an active credit package by its ID.
     *
     * @return array{id: string, name: string, credits: int, price_kobo: int, currency: string, discount_label: string|null, is_popular: bool}|null
     */
    public function findActivePackage(string $packageId): ?array
    {
        $normalized = strtolower(trim($packageId));
        foreach ($this->getActivePackages() as $pkg) {
            if (strtolower($pkg['id']) === $normalized) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * Initialize a credit top-up checkout session using the resolved gateway.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function initializeTopup(
        OrganizerCreditWallet $wallet,
        int $credits,
        string $callbackUrl,
        string $customerEmail,
        ?string $gatewayName = null,
        array $metadata = [],
        ?string $packageId = null
    ): GatewayCheckoutDTO {
        $gateway = $this->gatewayManager->driver($gatewayName);

        if ($packageId !== null && trim($packageId) !== '') {
            $package = $this->findActivePackage($packageId);
            if (! $package) {
                throw new InvalidArgumentException("Invalid or inactive credit package '{$packageId}'.");
            }
            $credits = $package['credits'];
            $amountKobo = $package['price_kobo'];
            $metadata['package_id'] = $package['id'];
            $metadata['package_name'] = $package['name'];
        } else {
            if ($credits < 10) {
                throw new InvalidArgumentException('Credit amount must be at least 10.');
            }
            $ratePerCreditKobo = (int) (config('credits.rate_per_credit_kobo') ?: 1000);
            $amountKobo = $credits * $ratePerCreditKobo;
        }

        $checkout = $gateway->initializeCheckout(
            wallet: $wallet,
            credits: $credits,
            amountKobo: $amountKobo,
            callbackUrl: $callbackUrl,
            customerEmail: $customerEmail,
            metadata: $metadata
        );

        // Pre-record pending topup transaction for tracing and idempotency lock
        CreditTopupTransaction::create([
            'wallet_id' => $wallet->id,
            'provider' => $gateway->getIdentifier(),
            'provider_ref' => $checkout->reference,
            'amount_kobo' => $amountKobo,
            'credits_awarded' => $credits,
            'status' => 'PENDING',
            'metadata' => array_merge($metadata, [
                'authorization_url' => $checkout->authorizationUrl,
            ]),
        ]);

        return $checkout;
    }

    /**
     * Process an incoming webhook for any supported gateway.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function processWebhook(string $gatewayName, array $payload, string $signature, string $rawPayload = ''): array
    {
        $gateway = $this->gatewayManager->driver($gatewayName);

        // 1. Verify Webhook Signature
        $rawToVerify = ! empty($rawPayload) ? $rawPayload : (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! $gateway->verifyWebhookSignature($rawToVerify, $signature)) {
            // Fallback standard json_encode formatting check
            if (! $gateway->verifyWebhookSignature((string) json_encode($payload), $signature)) {
                Log::warning("Invalid {$gateway->getIdentifier()} webhook signature received.");

                return [
                    'status' => 'invalid_signature',
                    'message' => "Invalid webhook signature for gateway {$gateway->getIdentifier()}.",
                ];
            }
        }

        // 2. Parse & Normalize Event
        $eventDto = $gateway->parseWebhookEvent($payload);

        if (! $eventDto->isSuccessful) {
            return [
                'status' => 'ignored',
                'message' => "Webhook event for {$eventDto->providerReference} ignored (non-successful state).",
            ];
        }

        if (empty($eventDto->providerReference)) {
            return [
                'status' => 'error',
                'message' => 'Missing provider transaction reference.',
            ];
        }

        // 3. Atomically Ingest & Credit Wallet with Idempotency Guard
        return DB::transaction(function () use ($eventDto) {
            /** @var CreditTopupTransaction $topupTx */
            $topupTx = CreditTopupTransaction::where('provider_ref', $eventDto->providerReference)
                ->lockForUpdate()
                ->first();

            // Resolve wallet
            $wallet = null;
            if ($topupTx) {
                $wallet = OrganizerCreditWallet::where('id', $topupTx->wallet_id)->lockForUpdate()->first();
            } elseif ($eventDto->walletId) {
                $wallet = OrganizerCreditWallet::where('id', $eventDto->walletId)->lockForUpdate()->first();
            } elseif ($eventDto->walletUuid) {
                $wallet = OrganizerCreditWallet::where('uuid', $eventDto->walletUuid)->lockForUpdate()->first();
            }

            if (! $wallet) {
                Log::error("Credit Top-up webhook: Wallet not found for reference {$eventDto->providerReference}");

                throw new RuntimeException("Wallet not found for transaction reference {$eventDto->providerReference}");
            }

            // Create or update topup transaction row
            if (! $topupTx) {
                $topupTx = CreditTopupTransaction::create([
                    'wallet_id' => $wallet->id,
                    'provider' => $eventDto->gateway,
                    'provider_ref' => $eventDto->providerReference,
                    'amount_kobo' => $eventDto->amountKobo,
                    'credits_awarded' => $eventDto->creditsAwarded,
                    'status' => 'PENDING',
                    'metadata' => $eventDto->metadata,
                ]);
            }

            // Idempotency: Do not process twice
            if ($topupTx->isSuccess()) {
                Log::info("Credit top-up reference {$eventDto->providerReference} already processed.");

                return [
                    'status' => 'already_processed',
                    'credits' => $topupTx->credits_awarded,
                    'balance' => $wallet->balance,
                ];
            }

            $authoritativeCredits = $topupTx->credits_awarded > 0
                ? (int) $topupTx->credits_awarded
                : $eventDto->creditsAwarded;
            $authoritativeAmountKobo = $topupTx->amount_kobo > 0
                ? (int) $topupTx->amount_kobo
                : $eventDto->amountKobo;

            // Credit the Wallet
            $idempotencyKey = "topup_{$eventDto->gateway}_{$eventDto->providerReference}";
            $ledgerEntry = $this->ledgerService->creditWallet(
                wallet: $wallet,
                amount: $authoritativeCredits,
                reason: CreditTxReason::TOPUP,
                idempotencyKey: $idempotencyKey,
                reference: $eventDto->providerReference,
                description: "Credit top-up via {$eventDto->gateway} ({$authoritativeCredits} credits)",
                metadata: [
                    'gateway' => $eventDto->gateway,
                    'provider_ref' => $eventDto->providerReference,
                    'amount_kobo' => $authoritativeAmountKobo,
                ]
            );

            // Mark transaction as SUCCESS
            $topupTx->update([
                'status' => 'SUCCESS',
                'amount_kobo' => $authoritativeAmountKobo,
                'credits_awarded' => $authoritativeCredits,
                'metadata' => array_merge($topupTx->metadata ?? [], [
                    'ledger_uuid' => $ledgerEntry->uuid,
                    'processed_at' => now()->toIso8601String(),
                ]),
            ]);

            return [
                'status' => 'success',
                'credits_awarded' => $authoritativeCredits,
                'new_balance' => $wallet->fresh()->balance,
                'reference' => $eventDto->providerReference,
            ];
        });
    }

    /**
     * Synchronously verify a transaction using the provider API and credit the wallet.
     * Used when the organizer returns to the browser redirect URL.
     *
     * @return array<string, mixed>
     */
    public function verifyAndSettle(OrganizerCreditWallet $wallet, string $reference, ?string $gatewayName = null): array
    {
        /** @var CreditTopupTransaction|null $topupTx */
        $topupTx = CreditTopupTransaction::where('provider_ref', $reference)->first();

        // If already completed via webhook, return immediately
        if ($topupTx && $topupTx->isSuccess()) {
            return [
                'status' => 'success',
                'already_processed' => true,
                'credits_awarded' => $topupTx->credits_awarded,
                'balance' => $wallet->fresh()->balance,
                'reference' => $reference,
            ];
        }

        $resolvedGateway = $topupTx ? $topupTx->provider : ($gatewayName ?: $this->gatewayManager->getDefaultDriverName());
        $gateway = $this->gatewayManager->driver($resolvedGateway);

        $verification = $gateway->verifyTransaction($reference);

        if (! ($verification['is_successful'] ?? false)) {
            if ($topupTx) {
                $topupTx->update(['status' => 'FAILED']);
            }

            return [
                'status' => 'failed',
                'message' => $verification['message'] ?? 'Payment verification failed on gateway.',
                'reference' => $reference,
            ];
        }

        $creditsAwarded = ($topupTx && $topupTx->credits_awarded > 0)
            ? (int) $topupTx->credits_awarded
            : (int) ($verification['credits_awarded'] ?? 0);
        $amountKobo = ($topupTx && $topupTx->amount_kobo > 0)
            ? (int) $topupTx->amount_kobo
            : (int) ($verification['amount_kobo'] ?? 0);

        return DB::transaction(function () use ($wallet, $topupTx, $resolvedGateway, $reference, $creditsAwarded, $amountKobo, $verification) {
            $lockedWallet = OrganizerCreditWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if (! $topupTx) {
                $topupTx = CreditTopupTransaction::create([
                    'wallet_id' => $lockedWallet->id,
                    'provider' => $resolvedGateway,
                    'provider_ref' => $reference,
                    'amount_kobo' => $amountKobo,
                    'credits_awarded' => $creditsAwarded,
                    'status' => 'PENDING',
                    'metadata' => $verification['raw_data'] ?? [],
                ]);
            } else {
                $topupTx = CreditTopupTransaction::where('id', $topupTx->id)->lockForUpdate()->firstOrFail();
            }

            if ($topupTx->isSuccess()) {
                return [
                    'status' => 'success',
                    'already_processed' => true,
                    'credits_awarded' => $topupTx->credits_awarded,
                    'balance' => $lockedWallet->balance,
                    'reference' => $reference,
                ];
            }

            $idempotencyKey = "topup_{$resolvedGateway}_{$reference}";
            $ledgerEntry = $this->ledgerService->creditWallet(
                wallet: $lockedWallet,
                amount: $creditsAwarded,
                reason: CreditTxReason::TOPUP,
                idempotencyKey: $idempotencyKey,
                reference: $reference,
                description: "Credit top-up via {$resolvedGateway} ({$creditsAwarded} credits)",
                metadata: [
                    'gateway' => $resolvedGateway,
                    'provider_ref' => $reference,
                    'amount_kobo' => $amountKobo,
                    'verified_via' => 'direct_verification_api',
                ]
            );

            $topupTx->update([
                'status' => 'SUCCESS',
                'amount_kobo' => $amountKobo > 0 ? $amountKobo : $topupTx->amount_kobo,
                'credits_awarded' => $creditsAwarded > 0 ? $creditsAwarded : $topupTx->credits_awarded,
                'metadata' => array_merge($topupTx->metadata ?? [], [
                    'ledger_uuid' => $ledgerEntry->uuid,
                    'verified_at' => now()->toIso8601String(),
                ]),
            ]);

            return [
                'status' => 'success',
                'credits_awarded' => $creditsAwarded,
                'balance' => $lockedWallet->fresh()->balance,
                'reference' => $reference,
            ];
        });
    }

    /**
     * Backward-compatibility proxy for Paystack specific calls.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function processPaystackWebhook(array $payload, string $signature): array
    {
        return $this->processWebhook('paystack', $payload, $signature);
    }
}
