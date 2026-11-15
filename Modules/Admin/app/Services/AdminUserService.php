<?php

declare(strict_types=1);

namespace Modules\Admin\app\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Enum\UserStatus;
use Modules\Auth\app\Models\User;
use Modules\Organizer\app\Models\OrganizerMember;
use Modules\Tickets\app\Models\Ticket;

class AdminUserService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Paginated list of users with search and status filtering.
     *
     * @param  array{search?: string, status?: string, role?: string, per_page?: int}  $filters
     */
    public function getUsers(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        $query = User::query();

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('uuid', 'LIKE', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'suspended') {
                $query->whereNotNull('suspended_at');
            } elseif ($filters['status'] === 'active') {
                $query->whereNull('suspended_at')->where('status', UserStatus::ACTIVE);
            }
        }

        if (isset($filters['is_admin'])) {
            $query->where('is_admin', filter_var($filters['is_admin'], FILTER_VALIDATE_BOOLEAN));
        }

        // Category filter: 'attendee', 'organizer', 'admin'
        if (! empty($filters['category'])) {
            $cat = strtolower(trim((string) $filters['category']));
            if ($cat === 'admin') {
                $query->where('is_admin', true);
            } elseif ($cat === 'organizer') {
                $query->whereHas('organizers');
            } elseif ($cat === 'attendee') {
                $query->where('is_admin', false)
                    ->whereDoesntHave('organizers');
            }
        }

        return $query->with(['organizers:id,uuid,name,slug'])->withCount(['tickets'])->latest()->paginate($perPage);
    }

    /**
     * Get user profile details, memberships, tickets, and active tokens.
     *
     * @return array<string, mixed>
     */
    public function getUserDetails(string $userUuid): array
    {
        $user = User::where('uuid', $userUuid)->firstOrFail();

        $memberships = OrganizerMember::with(['organizer:id,uuid,name,slug,logo'])
            ->where('user_id', $user->id)
            ->get();

        $tickets = Ticket::with(['event:id,uuid,title,slug', 'ticketType:id,name,price'])
            ->where('user_id', $user->id)
            ->latest()
            ->take(10)
            ->get();

        $activeTokensCount = $user->tokens()->count();

        return [
            'user' => $user,
            'memberships' => $memberships,
            'recent_tickets' => $tickets,
            'active_sessions_count' => $activeTokensCount,
        ];
    }

    /**
     * Suspend a user account.
     */
    public function suspendUser(string $userUuid, User $adminUser, string $reason): User
    {
        $user = User::where('uuid', $userUuid)->firstOrFail();

        $user->update([
            'status' => UserStatus::SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);

        // Revoke tokens immediately upon suspension
        $user->tokens()->delete();

        $this->auditService->log(
            action: 'user.suspended',
            description: "Suspended user [{$user->name}] ({$user->email}) with reason: {$reason}",
            user: $adminUser,
            targetType: 'User',
            targetId: $user->uuid,
            reason: $reason,
        );

        return $user;
    }

    /**
     * Reactivate / unsuspend a user account.
     */
    public function unsuspendUser(string $userUuid, User $adminUser): User
    {
        $user = User::where('uuid', $userUuid)->firstOrFail();

        $user->update([
            'status' => UserStatus::ACTIVE,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        $this->auditService->log(
            action: 'user.unsuspended',
            description: "Reactivated suspended user [{$user->name}] ({$user->email})",
            user: $adminUser,
            targetType: 'User',
            targetId: $user->uuid,
        );

        return $user;
    }

    /**
     * Emergency revocation of all active sessions and access tokens.
     */
    public function revokeUserSessions(string $userUuid, User $adminUser): int
    {
        $user = User::where('uuid', $userUuid)->firstOrFail();
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        $this->auditService->log(
            action: 'user.sessions_revoked',
            description: "Force-revoked {$count} active access tokens for user [{$user->name}] ({$user->email})",
            user: $adminUser,
            targetType: 'User',
            targetId: $user->uuid,
        );

        return $count;
    }

    /**
     * Support action: Manual email verification override.
     */
    public function verifyUserEmail(string $userUuid, User $adminUser): User
    {
        $user = User::where('uuid', $userUuid)->firstOrFail();

        $user->update([
            'email_verified_at' => now(),
            'is_email_verified' => true,
        ]);

        $this->auditService->log(
            action: 'user.email_verified_manual',
            description: "Manually verified email address for user [{$user->name}] ({$user->email})",
            user: $adminUser,
            targetType: 'User',
            targetId: $user->uuid,
        );

        return $user;
    }
}
