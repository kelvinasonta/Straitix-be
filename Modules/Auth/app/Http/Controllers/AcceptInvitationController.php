<?php

namespace Modules\Auth\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\app\Enum\UserStatus;
use Modules\Auth\app\Exceptions\UserNotActiveException;
use Modules\Auth\app\Http\Requests\AcceptInvitationRequest;
use Modules\Auth\app\Http\Requests\AcceptInvitationTokenRequest;
use Modules\Auth\app\Models\User;
use Modules\Auth\app\Services\AcceptInvitationService;
use Modules\Auth\app\Transformers\UserResource;
use Modules\Organizer\app\Exceptions\PlanLimitExceededException;
use Modules\Organizer\app\Exceptions\UserAlreadyOrganizerMemberException;
use Modules\Organizer\app\Models\OrganizerMember;
use Modules\Organizer\app\Services\OrganizerInvitationService;
use Modules\Organizer\app\Transformers\OrganizerResource;

class AcceptInvitationController extends Controller
{
    public function __construct(
        private AcceptInvitationService $acceptInvitationService,
        private OrganizerInvitationService $organizerInvitationService,
    ) {}

    public function verify(AcceptInvitationTokenRequest $request): JsonResponse
    {
        $token = $request->validated('token');
        $invitation = $this->acceptInvitationService->verify($token);
        $userExists = User::where('email', $invitation->email)->exists();

        return ApiResponse::success(
            'Invitation token is valid.',
            [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'organizer_name' => $invitation->organizer?->name,
                'user_exists' => $userExists,
                'expires_at' => $invitation->expires_at,
            ],
            200
        );
    }

    public function store(AcceptInvitationRequest $request): JsonResponse
    {
        $token = $request->validated('token');
        $invitation = $this->acceptInvitationService->verify($token);

        [$user, $authToken] = DB::transaction(function () use ($request, $invitation) {
            $user = User::where('email', $invitation->email)->first();

            if ($user && $user->isSuspended()) {
                throw new UserNotActiveException($user->status ?? UserStatus::SUSPENDED);
            }

            if ($user && $user->belongsToOrganization($invitation->organizer_id)) {
                throw new UserAlreadyOrganizerMemberException(
                    message: 'You already belong to another organization. A user can only belong to one organization at a time.',
                    status: 422,
                    errorCode: 'USER_ALREADY_IN_ORGANIZATION',
                );
            }

            if (! $user) {
                $user = User::create([
                    'first_name' => $request->validated('first_name'),
                    'last_name' => $request->validated('last_name'),
                    'email' => $invitation->email,
                    'password' => Hash::make($request->validated('password')),
                    'account_type' => 'organizer',
                    'email_verified_at' => now(),
                ]);
            } else {
                $updates = [];
                if (! $user->email_verified_at) {
                    $updates['email_verified_at'] = now();
                }
                if ($user->account_type !== 'organizer') {
                    $updates['account_type'] = 'organizer';
                }
                if (! empty($updates)) {
                    $user->update($updates);
                }
            }

            // Verify organizer hasn't exceeded plan staff capacity
            $organizer = $invitation->organizer;
            $isAlreadyMember = OrganizerMember::where('organizer_id', $organizer->id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $isAlreadyMember) {
                $currentStaffCount = OrganizerMember::where('organizer_id', $organizer->id)
                    ->where('user_id', '!=', $organizer->user_id)
                    ->count();
                $maxStaff = $organizer->maxAllowedStaffMembers();

                if ($currentStaffCount >= $maxStaff) {
                    $tierLabel = $organizer->effectivePlanTier()->label();
                    throw new PlanLimitExceededException(
                        "Your {$tierLabel} plan allows up to {$maxStaff} staff member(s). Upgrade your subscription plan to add more team members."
                    );
                }
            }

            OrganizerMember::updateOrCreate(
                [
                    'organizer_id' => $invitation->organizer_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $invitation->role,
                    'permissions' => $invitation->permissions,
                ]
            );

            $invitation->update(['accepted_at' => now()]);

            $this->organizerInvitationService->clearInvitationsCache($invitation->organizer);

            $token = $user->createToken('invitation_accepted')->plainTextToken;

            return [$user, $token];
        });

        $organizer = $invitation->organizer->fresh(['members']);

        return ApiResponse::success(
            'Invitation accepted successfully.',
            [
                'user' => new UserResource($user),
                'organizer' => new OrganizerResource($organizer, true, $user),
                'token' => $authToken,
            ],
            200
        );
    }
}
