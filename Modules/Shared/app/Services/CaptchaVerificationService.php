<?php

declare(strict_types=1);

namespace Modules\Shared\app\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Shared\app\Contracts\AppSettingsServiceInterface;

class CaptchaVerificationService
{
    public function __construct(
        protected AppSettingsServiceInterface $settingsService
    ) {}

    /**
     * Get the currently active captcha provider ('none' | 'recaptcha' | 'turnstile').
     */
    public function getActiveProvider(): string
    {
        $provider = (string) $this->settingsService->get('auth_captcha_provider', 'none');

        return in_array($provider, ['recaptcha', 'turnstile'], true) ? $provider : 'none';
    }

    /**
     * Check if a captcha provider is active.
     */
    public function isEnabled(): bool
    {
        return $this->getActiveProvider() !== 'none';
    }

    /**
     * Validate the captcha token against the configured provider.
     *
     * @throws ValidationException
     */
    public function verify(?string $token, ?string $clientIp = null): bool
    {
        $provider = $this->getActiveProvider();

        if ($provider === 'none') {
            return true;
        }

        if (empty($token)) {
            $providerName = $provider === 'recaptcha' ? 'Google reCAPTCHA' : 'Cloudflare Turnstile';
            throw ValidationException::withMessages([
                'captcha_token' => ["{$providerName} verification challenge token is required."],
            ]);
        }

        if ($provider === 'recaptcha') {
            return $this->verifyRecaptcha($token, $clientIp);
        }

        if ($provider === 'turnstile') {
            return $this->verifyTurnstile($token, $clientIp);
        }

        return true;
    }

    /**
     * Verify Google reCAPTCHA v2 / v3 token.
     *
     * @throws ValidationException
     */
    protected function verifyRecaptcha(string $token, ?string $clientIp = null): bool
    {
        $secret = $this->settingsService->get('recaptcha_secret_key') ?: config('services.recaptcha.secret');

        if (empty($secret)) {
            Log::warning('Google reCAPTCHA is active but secret key is not configured.');

            return true;
        }

        try {
            $response = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $clientIp,
            ]));

            if ($response->successful()) {
                $body = $response->json();
                if (! empty($body['success'])) {
                    return true;
                }
            }

            Log::warning('Google reCAPTCHA verification failed.', [
                'response' => $response->json(),
                'ip' => $clientIp,
            ]);
        } catch (\Throwable $e) {
            Log::error('Google reCAPTCHA verification exception: '.$e->getMessage());
        }

        throw ValidationException::withMessages([
            'captcha_token' => ['Google reCAPTCHA verification failed. Please try again.'],
        ]);
    }

    /**
     * Verify Cloudflare Turnstile token.
     *
     * @throws ValidationException
     */
    protected function verifyTurnstile(string $token, ?string $clientIp = null): bool
    {
        $secret = $this->settingsService->get('cloudflare_turnstile_secret_key') ?: config('services.turnstile.secret');

        if (empty($secret)) {
            Log::warning('Cloudflare Turnstile is active but secret key is not configured.');

            return true;
        }

        try {
            $response = Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $clientIp,
            ]));

            if ($response->successful()) {
                $body = $response->json();
                if (! empty($body['success'])) {
                    return true;
                }
            }

            Log::warning('Cloudflare Turnstile verification failed.', [
                'response' => $response->json(),
                'ip' => $clientIp,
            ]);
        } catch (\Throwable $e) {
            Log::error('Cloudflare Turnstile verification exception: '.$e->getMessage());
        }

        throw ValidationException::withMessages([
            'captcha_token' => ['Cloudflare Turnstile verification failed. Please try again.'],
        ]);
    }
}

