<?php

declare(strict_types=1);

namespace Modules\Shared\app\DTOs;

class AppSettingsDTO
{
    public function __construct(
        public readonly string $sitename,
        public readonly string $site_tagline,
        public readonly ?string $favicon = null,
        public readonly ?string $logo = null,
        public readonly ?string $logo_light = null,
        public readonly ?string $logo_dark = null,
        public readonly ?string $meta_title = null,
        public readonly ?string $meta_description = null,
        public readonly ?string $meta_keywords = null,
        public readonly ?string $og_image = null,
        public readonly ?string $twitter_handle = null,
        public readonly ?string $maintenance_message = null,
        public readonly ?string $support_email = null,
        public readonly ?string $timezone = 'UTC',
        public readonly ?string $currency_id = null,
        public readonly float $ticket_fee_percentage = 0.0,
        public readonly float $ticket_fee_fixed = 0.0,
        public readonly float $platform_fee_percentage = 0.0,
        public readonly float $platform_fee_fixed = 0.0,
        public readonly bool $platform_fee_passthrough = true, // true = charged to buyer, false = absorbed by organizer
        public readonly string $auth_captcha_provider = 'none', // 'none' | 'recaptcha' | 'turnstile'
        public readonly ?string $recaptcha_site_key = null,
        public readonly ?string $recaptcha_secret_key = null,
        public readonly ?string $cloudflare_turnstile_site_key = null,
        public readonly ?string $cloudflare_turnstile_secret_key = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $feePct = isset($data['ticket_fee_percentage'])
            ? (float) $data['ticket_fee_percentage']
            : (isset($data['platform_fee_percentage']) ? (float) $data['platform_fee_percentage'] : 0.0);

        $feeFixed = isset($data['ticket_fee_fixed'])
            ? (float) $data['ticket_fee_fixed']
            : (isset($data['platform_fee_fixed']) ? (float) $data['platform_fee_fixed'] : 0.0);

        $passthrough = isset($data['platform_fee_passthrough'])
            ? filter_var($data['platform_fee_passthrough'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $logo = ! empty($data['logo']) ? (string) $data['logo'] : null;

        return new self(
            sitename: isset($data['sitename']) ? trim((string) $data['sitename']) : (string) config('app.name', 'EventsOS'),
            site_tagline: isset($data['site_tagline']) ? trim((string) $data['site_tagline']) : '',
            favicon: ! empty($data['favicon']) ? (string) $data['favicon'] : null,
            logo: $logo,
            logo_light: ! empty($data['logo_light']) ? (string) $data['logo_light'] : $logo,
            logo_dark: ! empty($data['logo_dark']) ? (string) $data['logo_dark'] : $logo,
            meta_title: ! empty($data['meta_title']) ? (string) $data['meta_title'] : null,
            meta_description: ! empty($data['meta_description']) ? (string) $data['meta_description'] : null,
            meta_keywords: ! empty($data['meta_keywords']) ? (string) $data['meta_keywords'] : null,
            og_image: ! empty($data['og_image']) ? (string) $data['og_image'] : null,
            twitter_handle: ! empty($data['twitter_handle']) ? (string) $data['twitter_handle'] : null,
            maintenance_message: ! empty($data['maintenance_message']) ? (string) $data['maintenance_message'] : null,
            support_email: ! empty($data['support_email']) ? (string) $data['support_email'] : null,
            timezone: ! empty($data['timezone']) ? (string) $data['timezone'] : 'UTC',
            currency_id: ! empty($data['currency_id']) ? (string) $data['currency_id'] : null,
            ticket_fee_percentage: $feePct,
            ticket_fee_fixed: $feeFixed,
            platform_fee_percentage: $feePct,
            platform_fee_fixed: $feeFixed,
            platform_fee_passthrough: $passthrough,
            auth_captcha_provider: ! empty($data['auth_captcha_provider']) ? (string) $data['auth_captcha_provider'] : 'none',
            recaptcha_site_key: ! empty($data['recaptcha_site_key']) ? (string) $data['recaptcha_site_key'] : null,
            recaptcha_secret_key: ! empty($data['recaptcha_secret_key']) ? (string) $data['recaptcha_secret_key'] : null,
            cloudflare_turnstile_site_key: ! empty($data['cloudflare_turnstile_site_key']) ? (string) $data['cloudflare_turnstile_site_key'] : null,
            cloudflare_turnstile_secret_key: ! empty($data['cloudflare_turnstile_secret_key']) ? (string) $data['cloudflare_turnstile_secret_key'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sitename' => $this->sitename,
            'site_tagline' => $this->site_tagline,
            'favicon' => $this->favicon,
            'logo' => $this->logo,
            'logo_light' => $this->logo_light,
            'logo_dark' => $this->logo_dark,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'og_image' => $this->og_image,
            'twitter_handle' => $this->twitter_handle,
            'maintenance_message' => $this->maintenance_message,
            'support_email' => $this->support_email,
            'timezone' => $this->timezone,
            'currency_id' => $this->currency_id,
            'ticket_fee_percentage' => $this->ticket_fee_percentage,
            'ticket_fee_fixed' => $this->ticket_fee_fixed,
            'platform_fee_percentage' => $this->platform_fee_percentage,
            'platform_fee_fixed' => $this->platform_fee_fixed,
            'platform_fee_passthrough' => $this->platform_fee_passthrough,
            'auth_captcha_provider' => $this->auth_captcha_provider,
            'recaptcha_site_key' => $this->recaptcha_site_key,
            'recaptcha_secret_key' => $this->recaptcha_secret_key,
            'cloudflare_turnstile_site_key' => $this->cloudflare_turnstile_site_key,
            'cloudflare_turnstile_secret_key' => $this->cloudflare_turnstile_secret_key,
        ];
    }
}
