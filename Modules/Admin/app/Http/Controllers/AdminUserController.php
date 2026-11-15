<?php

declare(strict_types=1);

namespace Modules\Admin\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\app\Services\AdminUserService;

class AdminUserController extends Controller
{
    public function __construct(
        protected AdminUserService $userService
    ) {}

    /**
     * List all platform users with pagination and search.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'is_admin', 'category', 'per_page']);
        $users = $this->userService->getUsers($filters);

        return ApiResponse::success(
            'Users retrieved successfully.',
            $users
        );
    }

    /**
     * Retrieve user profile, tickets, and active session count.
     */
    public function show(string $userUuid): JsonResponse
    {
        $details = $this->userService->getUserDetails($userUuid);

        return ApiResponse::success(
            'User details retrieved successfully.',
            $details
        );
    }

    /**
     * Suspend a user account.
     */
    public function suspend(Request $request, string $userUuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $user = $this->userService->suspendUser(
            $userUuid,
            $request->user(),
            $validated['reason']
        );

        return ApiResponse::success(
            'User suspended successfully.',
            $user
        );
    }

    /**
     * Reactivate / unsuspend a user account.
     */
    public function unsuspend(Request $request, string $userUuid): JsonResponse
    {
        $user = $this->userService->unsuspendUser(
            $userUuid,
            $request->user()
        );

        return ApiResponse::success(
            'User reactivated successfully.',
            $user
        );
    }

    /**
     * Emergency revocation of all access tokens for a user.
     */
    public function revokeSessions(Request $request, string $userUuid): JsonResponse
    {
        $revokedCount = $this->userService->revokeUserSessions(
            $userUuid,
            $request->user()
        );

        return ApiResponse::success(
            "Successfully revoked {$revokedCount} active sessions.",
            ['revoked_count' => $revokedCount]
        );
    }

    /**
     * Customer support action: Manually verify email.
     */
    public function verifyEmail(Request $request, string $userUuid): JsonResponse
    {
        $user = $this->userService->verifyUserEmail(
            $userUuid,
            $request->user()
        );

        return ApiResponse::success(
            'User email verified successfully.',
            $user
        );
    }
}
