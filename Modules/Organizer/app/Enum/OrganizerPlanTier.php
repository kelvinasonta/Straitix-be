<?php

declare(strict_types=1);

namespace Modules\Organizer\app\Enum;

enum OrganizerPlanTier: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free Starter',
            self::Pro => 'Pro Growth',
            self::Enterprise => 'Enterprise',
        };
    }

    public function monthlyPriceNgn(): float
    {
        return match ($this) {
            self::Free => 0.0,
            self::Pro => 15000.0,
            self::Enterprise => 60000.0,
        };
    }

    public function monthlyCostCredits(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 1500,
            self::Enterprise => 6000,
        };
    }

    public function defaultFeePercentage(): ?float
    {
        return match ($this) {
            self::Free => null,
            self::Pro => 2.5,
            self::Enterprise => 1.5,
        };
    }

    public function defaultFeeFixed(): ?float
    {
        return match ($this) {
            self::Free => null,
            self::Pro => 50.0,
            self::Enterprise => 0.0,
        };
    }

    public function maxStaffMembers(): int
    {
        return match ($this) {
            self::Free => 3,
            self::Pro => 15,
            self::Enterprise => 999,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $tier): array => [
            'tier' => $tier->value,
            'name' => $tier->label(),
            'monthly_price_ngn' => $tier->monthlyPriceNgn(),
            'monthly_cost_credits' => $tier->monthlyCostCredits(),
            'fee_percentage' => $tier->defaultFeePercentage(),
            'fee_fixed' => $tier->defaultFeeFixed(),
            'max_staff_members' => $tier->maxStaffMembers(),
        ], self::cases());
    }
}
