<?php

namespace Modules\Organizer\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Auth\app\Models\User;
use Modules\Events\app\Models\Event;
use Modules\Organizer\app\Enum\OrganizerMemberRole;
use Modules\Organizer\app\Enum\OrganizerPermission;
use Modules\Organizer\app\Enum\OrganizerPlanTier;
use Modules\Organizer\app\Enum\OrganizerVerificationStatus;

class Organizer extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'user_id',
        'uuid',
        'name',
        'slug',
        'description',
        'logo',
        'cover_photo',
        'email',
        'phone',
        'website',
        'is_active',
        'suspended_at',
        'suspension_reason',
        'verification_status',
        'verified_at',
        'plan_tier',
        'plan_expires_at',
        'custom_fee_percentage',
        'custom_fee_fixed',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
            'verification_status' => OrganizerVerificationStatus::class,
            'verified_at' => 'datetime',
            'plan_expires_at' => 'datetime',
            'custom_fee_percentage' => 'float',
            'custom_fee_fixed' => 'float',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null || ! $this->is_active;
    }

    /**
     * Determine whether the organizer currently has an active paid plan.
     */
    public function hasActivePaidPlan(): bool
    {
        $rawTier = is_string($this->plan_tier) ? strtolower($this->plan_tier) : 'free';
        if ($rawTier === '' || $rawTier === OrganizerPlanTier::Free->value) {
            return false;
        }

        if ($this->plan_expires_at !== null && $this->plan_expires_at->isPast()) {
            return false;
        }

        return OrganizerPlanTier::tryFrom($rawTier) !== null;
    }

    /**
     * Return the effective active plan tier (falling back to Free if expired).
     */
    public function effectivePlanTier(): OrganizerPlanTier
    {
        if (! $this->hasActivePaidPlan()) {
            return OrganizerPlanTier::Free;
        }

        return OrganizerPlanTier::tryFrom(strtolower((string) $this->plan_tier)) ?? OrganizerPlanTier::Free;
    }

    /**
     * Resolve the effective platform commission rate for this organizer.
     * Priority: 1. Custom Admin Override -> 2. Active Paid Subscription Tier -> 3. Global Platform Settings.
     *
     * @return array{percentage: float, fixed: float, source: string}
     */
    public function resolveEffectiveFeeRate(float $globalPercentage = 0.0, float $globalFixed = 0.0): array
    {
        if ($this->custom_fee_percentage !== null || $this->custom_fee_fixed !== null) {
            return [
                'percentage' => $this->custom_fee_percentage !== null ? (float) $this->custom_fee_percentage : $globalPercentage,
                'fixed' => $this->custom_fee_fixed !== null ? (float) $this->custom_fee_fixed : $globalFixed,
                'source' => 'custom',
            ];
        }

        if ($this->hasActivePaidPlan()) {
            $slug = strtolower((string) $this->plan_tier);
            $dbPlan = SubscriptionPlan::where('slug', $slug)->first();
            if ($dbPlan) {
                return [
                    'percentage' => $dbPlan->fee_percentage !== null ? (float) $dbPlan->fee_percentage : $globalPercentage,
                    'fixed' => $dbPlan->fee_fixed !== null ? (float) $dbPlan->fee_fixed : $globalFixed,
                    'source' => 'plan:'.$dbPlan->slug,
                ];
            }

            $tier = $this->effectivePlanTier();
            if ($tier !== OrganizerPlanTier::Free) {
                return [
                    'percentage' => $tier->defaultFeePercentage() ?? $globalPercentage,
                    'fixed' => $tier->defaultFeeFixed() ?? $globalFixed,
                    'source' => 'plan:'.$tier->value,
                ];
            }
        }

        return [
            'percentage' => $globalPercentage,
            'fixed' => $globalFixed,
            'source' => 'global',
        ];
    }

    /**
     * Return the maximum allowed staff members based on the active subscription plan.
     */
    public function maxAllowedStaffMembers(): int
    {
        $tier = $this->effectivePlanTier();
        $dbPlan = SubscriptionPlan::where('slug', $tier->value)->first();

        return $dbPlan ? (int) $dbPlan->max_staff_members : $tier->maxStaffMembers();
    }

    protected static function booted(): void
    {
        static::creating(function ($organizer) {
            if (empty($organizer->uuid)) {
                $organizer->uuid = (string) Str::uuid();
            }
            if (empty($organizer->slug) && ! empty($organizer->name)) {
                $organizer->slug = Str::slug($organizer->name);
            }
        });
    }

    public function getBannerUrlAttribute(): ?string
    {
        return $this->cover_photo;
    }

    public function getCoverImageAttribute(): ?string
    {
        return $this->cover_photo;
    }

    public function getBioAttribute(): ?string
    {
        return $this->description;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizerMember::class, 'organizer_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(OrganizerVerification::class, 'organizer_id');
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(OrganizerVerification::class, 'organizer_id')->latestOfMany();
    }

    public function isVerified(): bool
    {
        return $this->verification_status === OrganizerVerificationStatus::VERIFIED;
    }

    public function bank(): HasOne
    {
        return $this->hasOne(OrganizerBankAccounts::class, 'organizer_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizerSubscription::class, 'organizer_id');
    }

    public function latestSubscription(): HasOne
    {
        return $this->hasOne(OrganizerSubscription::class, 'organizer_id')->latestOfMany();
    }

    /**
     * Resolve the member role of the given user in this organization.
     */
    public function getRoleForUser(?User $user): ?OrganizerMemberRole
    {
        if (! $user) {
            return null;
        }

        if ((int) $this->user_id === (int) $user->id) {
            return OrganizerMemberRole::OWNER;
        }

        if (isset($this->pivot->role)) {
            return $this->pivot->role instanceof OrganizerMemberRole
                ? $this->pivot->role
                : OrganizerMemberRole::tryFrom((string) $this->pivot->role);
        }

        if ($this->relationLoaded('members')) {
            /** @var OrganizerMember|null $member */
            $member = $this->members->firstWhere('user_id', $user->id);
            if ($member?->role) {
                return $member->role instanceof OrganizerMemberRole
                    ? $member->role
                    : OrganizerMemberRole::tryFrom((string) $member->role);
            }
        } else {
            /** @var OrganizerMember|null $member */
            $member = OrganizerMember::where('organizer_id', $this->id)
                ->where('user_id', $user->id)
                ->first();

            if ($member?->role) {
                return $member->role;
            }
        }

        if ($user->isAdministrator()) {
            return OrganizerMemberRole::OWNER;
        }

        return null;
    }

    /**
     * Get the granted permissions for the given user in this organization.
     *
     * @return array<int, string>
     */
    public function getPermissionsForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ((int) $this->user_id === (int) $user->id) {
            return OrganizerMemberRole::OWNER->permissions();
        }

        /** @var OrganizerMember|null $member */
        $member = null;
        if ($this->relationLoaded('members')) {
            $member = $this->members->firstWhere('user_id', $user->id);
        } else {
            $member = OrganizerMember::where('organizer_id', $this->id)
                ->where('user_id', $user->id)
                ->first();
        }

        if ($member) {
            if (is_array($member->permissions)) {
                return $member->permissions;
            }

            return $member->role?->permissions() ?? [];
        }

        if ($user->isAdministrator()) {
            return OrganizerMemberRole::OWNER->permissions();
        }

        return [];
    }

    /**
     * Determine whether the given user has the specified permission in this organization.
     */
    public function userHasPermission(?User $user, OrganizerPermission|string $permission): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdministrator()) {
            return true;
        }

        $permString = $permission instanceof OrganizerPermission ? $permission->value : $permission;
        $granted = $this->getPermissionsForUser($user);

        if (in_array('*', $granted, true)) {
            return true;
        }

        return in_array($permString, $granted, true);
    }

    /**
     * Determine whether the given user is the owner or a team member of this organization.
     */
    public function isMember(?User $user): bool
    {
        return $this->getRoleForUser($user) !== null;
    }
}
