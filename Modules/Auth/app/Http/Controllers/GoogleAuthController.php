<?php

namespace Modules\Auth\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Modules\Auth\app\Enum\UserStatus;
use Modules\Auth\app\Exceptions\UserNotActiveException;
use Modules\Auth\app\Models\User;
use Modules\Auth\app\Transformers\UserResource;
use Modules\Organizer\app\Transformers\OrganizerResource;
use Modules\Shared\app\Services\CaptchaVerificationService;

class GoogleAuthController extends Controller
{
    public function __construct(
        protected CaptchaVerificationService $captchaService
    ) {}

    /**
     * Return the Google OAuth redirect URL.
     */
    public function redirect(Request $request): JsonResponse
    {
        $this->captchaService->verify($request->input('captcha_token'), $request->ip());

        $redirectUrl = Socialite::driver('google')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return ApiResponse::success('Google redirect URL generated.', [
            'url' => $redirectUrl,
        ]);
    }

    /**
     * Handle the OAuth callback or token exchange from Google.
     * Accepts either an authorization code or an id_token / access_token payload.
     */
    public function callback(Request $request): JsonResponse
    {
        try {
            $googleUser = null;

            if ($request->filled('code')) {
                // Standard authorization code grant flow
                $provider = Socialite::driver('google')->stateless();
                if ($request->filled('redirect_uri')) {
                    $provider->redirectUrl($request->input('redirect_uri'));
                }
                $googleUser = $provider->user();
            } elseif ($request->filled('access_token')) {
                // Access token flow
                $googleUser = Socialite::driver('google')->userFromToken($request->input('access_token'));
            } else {
                return ApiResponse::error('Missing Google authorization code or token.', 400);
            }

            if (! $googleUser || empty($googleUser->getEmail())) {
                return ApiResponse::error('Failed to retrieve Google user credentials.', 422);
            }

            $email = strtolower(trim($googleUser->getEmail()));
            $googleId = (string) $googleUser->getId();
            $avatar = $googleUser->getAvatar();

            // Find user by google_id or email
            $user = User::where('google_id', $googleId)
                ->orWhere('email', $email)
                ->first();

            $isNewUser = false;

            // Resolve valid browser timezone if provided via body or X-Timezone header
            $rawTz = $request->input('timezone') ?? $request->header('X-Timezone');
            $detectedTz = 'UTC';
            if (is_string($rawTz)) {
                $cleanedTz = trim((string) preg_replace('/\s*\([^)]*\).*$/', '', $rawTz));
                if ($cleanedTz !== '' && in_array($cleanedTz, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                    $detectedTz = $cleanedTz;
                }
            }

            if ($user) {
                if ($user->isSuspended()) {
                    throw new UserNotActiveException($user->status ?? UserStatus::SUSPENDED);
                }

                $updates = [];
                if (empty($user->google_id)) {
                    $updates['google_id'] = $googleId;
                }
                if (empty($user->email_verified_at)) {
                    $updates['email_verified_at'] = now();
                }
                if (empty($user->avatar) && $avatar) {
                    $updates['avatar'] = $avatar;
                }
                if (empty($user->timezone) && $detectedTz !== 'UTC') {
                    $updates['timezone'] = $detectedTz;
                }
                if (! empty($updates)) {
                    $user->update($updates);
                }
            } else {
                // Create new user via Google
                $rawName = $googleUser->getName() ?? '';
                $nameParts = explode(' ', trim($rawName), 2);
                $firstName = $nameParts[0] ?? 'User';
                $lastName = $nameParts[1] ?? '';

                $user = User::create([
                    'first_name' => $firstName ?: 'Google',
                    'last_name' => $lastName ?: 'User',
                    'email' => $email,
                    'google_id' => $googleId,
                    'avatar' => $avatar,
                    'timezone' => $detectedTz,
                    'email_verified_at' => now(),
                    'account_type' => 'customer',
                    'status' => UserStatus::ACTIVE,
                ]);

                $isNewUser = true;
            }

            $userAgent = $request->userAgent() ?: 'GoogleOAuth';
            $token = $user->createToken($userAgent, ['*'], now()->addDays(30))->plainTextToken;
            $organizer = $user->resolveActiveOrganizer();

            return ApiResponse::success(
                $isNewUser ? 'Account created and authenticated via Google.' : 'Authenticated via Google successfully.',
                [
                    'token' => $token,
                    'user' => new UserResource($user),
                    'organizer' => $organizer ? new OrganizerResource($organizer, true, $user) : null,
                    'is_new_user' => $isNewUser,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback failed: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return ApiResponse::error('Google authentication failed: '.$e->getMessage(), 500);
        }
    }
}
