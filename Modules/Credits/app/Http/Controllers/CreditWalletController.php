<?php

namespace Modules\Credits\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Credits\app\Services\CreditLedgerService;
use Modules\Credits\app\Transformers\CreditLedgerResource;
use Modules\Credits\app\Transformers\CreditWalletResource;
use Modules\Organizer\app\Models\Organizer;

class CreditWalletController extends Controller
{
    public function __construct(
        protected CreditLedgerService $creditLedgerService
    ) {}

    /**
     * Resolve the active organizer from route parameters or request attributes.
     */
    protected function resolveOrganizer(Request $request, string $organizerUuid): Organizer
    {
        /** @var Organizer|null $organizer */
        $organizer = $request->attributes->get('active_organizer');

        if ($organizer instanceof Organizer) {
            return $organizer;
        }

        return Organizer::where('uuid', $organizerUuid)
            ->orWhere('id', $organizerUuid)
            ->firstOrFail();
    }

    /**
     * Get wallet details for the organizer.
     * GET /api/v1/organizers/{organizerUuid}/credits/wallet
     */
    public function show(Request $request, string $organizerUuid): JsonResponse
    {
        $organizer = $this->resolveOrganizer($request, $organizerUuid);
        $wallet = $this->creditLedgerService->getOrCreateWallet($organizer);

        return ApiResponse::success(
            'Credit wallet retrieved successfully.',
            new CreditWalletResource($wallet)
        );
    }

    /**
     * Update low-balance threshold and alert configuration for the organizer's wallet.
     * PUT /api/v1/organizers/{organizerUuid}/credits/wallet/settings
     */
    public function updateSettings(Request $request, string $organizerUuid): JsonResponse
    {
        $validated = $request->validate([
            'low_balance_threshold' => ['required', 'integer', 'min:0', 'max:1000000'],
            'low_balance_alert_enabled' => ['required', 'boolean'],
        ]);

        $organizer = $this->resolveOrganizer($request, $organizerUuid);
        $wallet = $this->creditLedgerService->getOrCreateWallet($organizer);

        $updated = $this->creditLedgerService->updateWalletSettings(
            wallet: $wallet,
            lowBalanceThreshold: (int) $validated['low_balance_threshold'],
            lowBalanceAlertEnabled: (bool) $validated['low_balance_alert_enabled']
        );

        return ApiResponse::success(
            'Credit wallet alert settings updated successfully.',
            new CreditWalletResource($updated)
        );
    }

    /**
     * Get paginated ledger history for the organizer's wallet with filtering.
     * GET /api/v1/organizers/{organizerUuid}/credits/ledger
     */
    public function ledger(Request $request, string $organizerUuid): JsonResponse
    {
        $organizer = $this->resolveOrganizer($request, $organizerUuid);
        $wallet = $this->creditLedgerService->getOrCreateWallet($organizer);

        $query = $wallet->ledgerEntries()->orderBy('created_at', 'desc');

        if ($request->filled('reason')) {
            $query->where('reason', $request->query('reason'));
        }

        if ($request->filled('type')) {
            $query->where('type', strtoupper((string) $request->query('type')));
        }

        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->query('search')).'%';
            $query->where(function ($q) use ($search): void {
                $q->where('reference', 'like', $search)
                    ->orWhere('description', 'like', $search);
            });
        }

        $perPage = min(max((int) ($request->query('per_page') ?? $request->query('limit') ?? 15), 1), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::success(
            'Credit ledger retrieved successfully.',
            CreditLedgerResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    /**
     * Trigger wallet reconciliation check.
     * GET /api/v1/organizers/{organizerUuid}/credits/reconcile
     */
    public function reconcile(Request $request, string $organizerUuid): JsonResponse
    {
        $organizer = $this->resolveOrganizer($request, $organizerUuid);
        $wallet = $this->creditLedgerService->getOrCreateWallet($organizer);

        $reconciliation = $this->creditLedgerService->reconcileWallet($wallet);

        if (! $reconciliation['valid']) {
            Log::channel('daily')->critical(
                sprintf(
                    'Credit mismatch detected on Wallet #%d (Organizer #%d): Stored balance = %d, Computed balance = %d (Difference: %d)',
                    $wallet->id,
                    $wallet->organizer_id,
                    $reconciliation['stored_balance'],
                    $reconciliation['computed_balance'],
                    $reconciliation['difference']
                ),
                [
                    'wallet_id' => $wallet->id,
                    'organizer_id' => $wallet->organizer_id,
                    'reconciliation' => $reconciliation,
                ]
            );

            return ApiResponse::error(
                'Credit wallet reconciliation mismatch detected.',
                409,
                'CREDIT_RECONCILIATION_MISMATCH',
                [],
                $reconciliation
            );
        }

        return ApiResponse::success(
            'Credit wallet is balanced and reconciled.',
            $reconciliation
        );
    }
}
