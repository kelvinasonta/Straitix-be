<?php

declare(strict_types=1);

namespace Modules\Admin\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\app\Models\User;
use Modules\Events\app\Enum\EventStatus;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Enum\OrganizerVerificationStatus;
use Modules\Organizer\app\Models\Organizer;
use Modules\Organizer\app\Models\OrganizerVerification;
use Modules\Payments\app\Enums\OrderStatus;
use Modules\Payments\app\Models\Order;
use Modules\Payments\app\Models\Payout;
use Modules\Tickets\app\Models\Ticket;

class AdminDashboardService
{
    /**
     * Compute comprehensive high-level platform KPIs.
     *
     * @param  array{period?: string, start_date?: string, end_date?: string}  $filters
     * @return array<string, mixed>
     */
    public function getPlatformStats(array $filters = []): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($filters);

        $ordersQuery = Order::query()->where('status', OrderStatus::Paid);
        if ($startDate) {
            $ordersQuery->where('paid_at', '>=', $startDate);
        }
        if ($endDate) {
            $ordersQuery->where('paid_at', '<=', $endDate);
        }

        $grossMerchandiseValue = (float) (clone $ordersQuery)->sum('total');
        $netPlatformRevenue = (float) (clone $ordersQuery)->sum('service_fee');
        $totalPaidOrders = (int) (clone $ordersQuery)->count();

        // Tickets breakdown
        $ticketsQuery = Ticket::query();
        if ($startDate) {
            $ticketsQuery->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $ticketsQuery->where('created_at', '<=', $endDate);
        }

        $totalTicketsSold = (int) (clone $ticketsQuery)->count();
        $paidTickets = (int) (clone $ticketsQuery)->where('price_paid', '>', 0)->count();
        $freeTickets = (int) (clone $ticketsQuery)->where(function ($q) {
            $q->whereNull('price_paid')->orWhere('price_paid', '<=', 0);
        })->count();

        // Events overview
        $eventsQuery = Event::query();
        if ($startDate) {
            $eventsQuery->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $eventsQuery->where('created_at', '<=', $endDate);
        }

        $totalEvents = (int) (clone $eventsQuery)->count();
        $publishedEvents = (int) (clone $eventsQuery)->where('status', EventStatus::PUBLISHED->value)->count();
        $draftEvents = (int) (clone $eventsQuery)->where('status', EventStatus::DRAFT->value)->count();
        $cancelledEvents = (int) (clone $eventsQuery)->where('status', EventStatus::CANCELLED->value)->count();

        // Users & Organizers
        $totalUsers = (int) User::query()->count();
        $activeUsers = (int) User::query()->where('status', 'active')->whereNull('suspended_at')->count();
        $totalOrganizers = (int) Organizer::query()->count();
        $verifiedOrganizers = (int) Organizer::query()
            ->where('verification_status', OrganizerVerificationStatus::VERIFIED->value)
            ->count();
        $pendingVerifications = (int) OrganizerVerification::query()
            ->where('status', 'pending')
            ->count();

        // Payouts & Escrow Overview
        $payoutsQuery = Payout::query();
        $totalPaidOut = (float) (clone $payoutsQuery)->where('status', 'processed')->sum('amount');
        $pendingPayouts = (float) (clone $payoutsQuery)->where('status', 'pending')->sum('amount');
        $pendingPayoutCount = (int) (clone $payoutsQuery)->where('status', 'pending')->count();

        // Platform Escrow: All-time net revenue held for organizers minus what has been paid out
        $allTimePaidOrders = Order::query()->where('status', OrderStatus::Paid);
        $allTimeSubtotal = (float) (clone $allTimePaidOrders)->sum('subtotal');
        $allTimeDiscount = (float) (clone $allTimePaidOrders)->sum('discount');
        $allTimeOrganizerEarnings = max(0.0, round($allTimeSubtotal - $allTimeDiscount, 2));
        $activeEscrowBalance = max(0.0, round($allTimeOrganizerEarnings - $totalPaidOut, 2));

        return [
            'period' => $filters['period'] ?? 'all',
            'start_date' => $startDate?->toIso8601String(),
            'end_date' => $endDate?->toIso8601String(),
            'financials' => [
                'gmv' => round($grossMerchandiseValue, 2),
                'net_revenue' => round($netPlatformRevenue, 2),
                'paid_orders_count' => $totalPaidOrders,
                'active_escrow_balance' => $activeEscrowBalance,
                'total_paid_out' => round($totalPaidOut, 2),
                'pending_payouts_amount' => round($pendingPayouts, 2),
                'pending_payouts_count' => $pendingPayoutCount,
            ],
            'tickets' => [
                'total_sold' => $totalTicketsSold,
                'paid_count' => $paidTickets,
                'free_count' => $freeTickets,
            ],
            'events' => [
                'total' => $totalEvents,
                'published' => $publishedEvents,
                'draft' => $draftEvents,
                'cancelled' => $cancelledEvents,
            ],
            'community' => [
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'total_organizers' => $totalOrganizers,
                'verified_organizers' => $verifiedOrganizers,
                'pending_kyc_verifications' => $pendingVerifications,
            ],
        ];
    }

    /**
     * Get time-series charts data for revenue and ticket sales velocity.
     *
     * @param  array{period?: string, days?: int}  $filters
     * @return array<string, mixed>
     */
    public function getChartsData(array $filters = []): array
    {
        $days = (int) ($filters['days'] ?? 14);
        if ($days <= 0 || $days > 90) {
            $days = 14;
        }

        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();

        $dailySales = Order::query()
            ->selectRaw('DATE(paid_at) as date, SUM(total) as gmv, SUM(service_fee) as revenue, COUNT(id) as orders_count')
            ->where('status', OrderStatus::Paid)
            ->where('paid_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(paid_at)'))
            ->orderBy('date', 'ASC')
            ->get()
            ->keyBy('date');

        $dailyTickets = Ticket::query()
            ->selectRaw('DATE(created_at) as date, COUNT(id) as tickets_count')
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'ASC')
            ->get()
            ->keyBy('date');

        $timeline = [];
        $current = clone $startDate;
        $today = Carbon::now()->endOfDay();

        while ($current->lte($today)) {
            $dateKey = $current->toDateString();
            $sale = $dailySales->get($dateKey);
            $ticket = $dailyTickets->get($dateKey);

            $timeline[] = [
                'date' => $dateKey,
                'gmv' => (float) ($sale?->gmv ?? 0.0),
                'net_revenue' => (float) ($sale?->revenue ?? 0.0),
                'orders_count' => (int) ($sale?->orders_count ?? 0),
                'tickets_count' => (int) ($ticket?->tickets_count ?? 0),
            ];

            $current->addDay();
        }

        return [
            'days' => $days,
            'start_date' => $startDate->toDateString(),
            'end_date' => Carbon::now()->toDateString(),
            'timeline' => $timeline,
        ];
    }

    /**
     * Resolve start and end carbon instances from query filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveDateRange(array $filters): array
    {
        $period = $filters['period'] ?? null;

        if (! empty($filters['start_date'])) {
            $start = Carbon::parse((string) $filters['start_date'])->startOfDay();
            $end = ! empty($filters['end_date'])
                ? Carbon::parse((string) $filters['end_date'])->endOfDay()
                : Carbon::now()->endOfDay();

            return [$start, $end];
        }

        return match ($period) {
            'today' => [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()],
            '7d' => [Carbon::now()->subDays(7)->startOfDay(), Carbon::now()->endOfDay()],
            '30d' => [Carbon::now()->subDays(30)->startOfDay(), Carbon::now()->endOfDay()],
            '90d' => [Carbon::now()->subDays(90)->startOfDay(), Carbon::now()->endOfDay()],
            default => [null, null],
        };
    }
}
