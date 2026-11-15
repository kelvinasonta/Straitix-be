<?php

declare(strict_types=1);

namespace Modules\Admin\app\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Models\User;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Models\Payout;

class AdminFinanceService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Cross-tenant platform orders directory.
     *
     * @param  array{search?: string, status?: string, organizer_id?: string, per_page?: int}  $filters
     */
    public function getOrders(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        $query = Order::query()->with([
            'user:id,first_name,last_name,email',
            'organizer:id,uuid,name,slug',
            'event:id,uuid,title,slug',
            'payments',
        ]);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'LIKE', "%{$search}%")
                    ->orWhere('customer_email', 'LIKE', "%{$search}%")
                    ->orWhere('customer_name', 'LIKE', "%{$search}%")
                    ->orWhere('uuid', 'LIKE', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['organizer_id'])) {
            $query->where('organizer_id', $filters['organizer_id']);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Get order details by UUID.
     */
    public function getOrderDetails(string $orderUuid): Order
    {
        return Order::with([
            'user',
            'organizer.bank',
            'event',
            'items.ticketType',
            'payments',
            'tickets',
        ])->where('uuid', $orderUuid)->firstOrFail();
    }

    /**
     * Retrieve payout review queue.
     *
     * @param  array{status?: string, organizer_id?: string, per_page?: int}  $filters
     */
    public function getPayouts(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        $query = Payout::query()->with([
            'organizer:id,uuid,name,slug',
            'requester:id,first_name,last_name,email',
            'reviewer:id,first_name,last_name,email',
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['organizer_id'])) {
            $query->where('organizer_id', $filters['organizer_id']);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Approve a payout request.
     */
    public function approvePayout(string $payoutUuid, User $adminUser, ?string $notes = null): Payout
    {
        $payout = Payout::where('uuid', $payoutUuid)->firstOrFail();

        if ($payout->status !== 'pending') {
            throw new \DomainException("Payout cannot be approved from current status: {$payout->status}");
        }

        $payout->update([
            'status' => 'approved',
            'reviewed_by' => $adminUser->id,
            'reviewed_at' => now(),
            'admin_notes' => $notes,
        ]);

        $this->auditService->log(
            action: 'payout.approved',
            description: "Approved payout [{$payout->uuid}] of {$payout->currency} {$payout->amount} for organizer [{$payout->organizer_id}].",
            user: $adminUser,
            targetType: 'Payout',
            targetId: $payout->uuid,
        );

        return $payout;
    }

    /**
     * Reject a payout request.
     */
    public function rejectPayout(string $payoutUuid, User $adminUser, string $reason): Payout
    {
        $payout = Payout::where('uuid', $payoutUuid)->firstOrFail();

        if ($payout->status !== 'pending') {
            throw new \DomainException("Payout cannot be rejected from current status: {$payout->status}");
        }

        $payout->update([
            'status' => 'rejected',
            'reviewed_by' => $adminUser->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->auditService->log(
            action: 'payout.rejected',
            description: "Rejected payout [{$payout->uuid}] of {$payout->currency} {$payout->amount} for organizer [{$payout->organizer_id}]. Reason: {$reason}",
            user: $adminUser,
            targetType: 'Payout',
            targetId: $payout->uuid,
            reason: $reason,
        );

        return $payout;
    }
}
