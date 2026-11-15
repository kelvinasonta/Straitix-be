<?php

declare(strict_types=1);

namespace Modules\Organizer\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Auth\app\Models\User;

class OrganizerSubscription extends Model
{
    use HasFactory;

    protected $table = 'organizer_subscriptions';

    protected $fillable = [
        'uuid',
        'organizer_id',
        'subscription_plan_id',
        'created_by',
        'plan_slug',
        'billing_cycle',
        'payment_method',
        'amount_paid',
        'credits_paid',
        'currency',
        'status',
        'auto_renew',
        'reference',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'float',
            'credits_paid' => 'integer',
            'auto_renew' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $sub): void {
            if (empty($sub->uuid)) {
                $sub->uuid = (string) Str::uuid();
            }
            if (empty($sub->reference)) {
                $sub->reference = 'SUB-'.strtoupper(Str::random(12));
            }
        });
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class, 'organizer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        if (! in_array($this->status, ['active', 'cancelled'], true)) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }
}

