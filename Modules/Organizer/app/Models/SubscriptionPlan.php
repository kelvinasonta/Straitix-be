<?php

declare(strict_types=1);

namespace Modules\Organizer\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'uuid',
        'slug',
        'name',
        'description',
        'monthly_price_ngn',
        'annual_price_ngn',
        'monthly_cost_credits',
        'annual_cost_credits',
        'fee_percentage',
        'fee_fixed',
        'max_staff_members',
        'max_active_events',
        'features',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price_ngn' => 'float',
            'annual_price_ngn' => 'float',
            'monthly_cost_credits' => 'integer',
            'annual_cost_credits' => 'integer',
            'fee_percentage' => 'float',
            'fee_fixed' => 'float',
            'max_staff_members' => 'integer',
            'max_active_events' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $plan): void {
            if (empty($plan->uuid)) {
                $plan->uuid = (string) Str::uuid();
            }
            if (empty($plan->slug) && ! empty($plan->name)) {
                $plan->slug = Str::slug($plan->name);
            }
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizerSubscription::class, 'subscription_plan_id');
    }
}

