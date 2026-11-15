<?php

declare(strict_types=1);

namespace Modules\Shared\app\Enum;

enum SystemFeature: string
{
    // Authentication & Identity
    case AUTH_REGISTRATION = 'auth-registration';
    case AUTH_PASSWORD_RESET = 'auth-password-reset';
    case AUTH_SOCIAL_LOGIN = 'auth-social-login';
    case AUTH_TWO_FACTOR = 'auth-two-factor';
    case AUTH_SESSION_MANAGEMENT = 'auth-session-management';

    // Commerce & Checkout
    case GUEST_CHECKOUT = 'guest-checkout';
    case PAYOUTS_AUTOMATION = 'payouts-automation';
    case STRIPE_GATEWAY = 'stripe-gateway';
    case PAYSTACK_GATEWAY = 'paystack-gateway';
    case TICKET_TRANSFERS = 'ticket-transfers';

    // Compliance & Trust/Safety
    case STRICT_KYC = 'strict-kyc';
    case EVENT_AUTO_APPROVAL = 'event-auto-approval';
    case FRAUD_DETECTION_SHIELD = 'fraud-detection-shield';

    // Operations & Ecosystem
    case API_RATE_LIMITING = 'api-rate-limiting';
    case GATE_SCANNER_V2 = 'gate-scanner-v2';
    case SMS_NOTIFICATIONS = 'sms-notifications';
    case MAINTENANCE_MODE = 'platform-maintenance-mode';

    public function label(): string
    {
        return match ($this) {
            self::AUTH_REGISTRATION => 'User Registration',
            self::AUTH_PASSWORD_RESET => 'Password Reset',
            self::AUTH_SOCIAL_LOGIN => 'Social / OAuth Login',
            self::AUTH_TWO_FACTOR => 'Two-Factor Authentication (2FA)',
            self::AUTH_SESSION_MANAGEMENT => 'Session & Device Management',

            self::GUEST_CHECKOUT => 'Guest Checkout Mode',
            self::PAYOUTS_AUTOMATION => 'Automated Bank Settlements',
            self::STRIPE_GATEWAY => 'Stripe Payment Gateway',
            self::PAYSTACK_GATEWAY => 'Paystack Payment Gateway',
            self::TICKET_TRANSFERS => 'Peer-to-Peer Ticket Transfers',

            self::STRICT_KYC => 'Mandatory Organizer KYC Verification',
            self::EVENT_AUTO_APPROVAL => 'Instant Event Publishing (Auto-Approve)',
            self::FRAUD_DETECTION_SHIELD => 'Heuristic Fraud & Chargeback Shield',

            self::API_RATE_LIMITING => 'API Rate Limiting & Throttling',
            self::GATE_SCANNER_V2 => 'Turnstile Gate Scanner V2 (Offline Cache)',
            self::SMS_NOTIFICATIONS => 'SMS Notification Dispatcher (Termii/Twilio)',
            self::MAINTENANCE_MODE => 'Platform Maintenance Banner (Read-Only Mode)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AUTH_REGISTRATION => 'Allow public attendee and organizer sign-ups across consumer web.',
            self::AUTH_PASSWORD_RESET => 'Allow users to request password reset links and recover accounts.',
            self::AUTH_SOCIAL_LOGIN => 'Enable one-click OAuth login via Google and Apple credentials.',
            self::AUTH_TWO_FACTOR => 'Require or support authenticator app TOTP 2FA verification.',
            self::AUTH_SESSION_MANAGEMENT => 'Allow users to inspect active login sessions and terminate remote devices.',

            self::GUEST_CHECKOUT => 'Allow attendees to purchase tickets without creating an account upfront.',
            self::PAYOUTS_AUTOMATION => 'Automatically disburse approved bank withdrawals via banking APIs.',
            self::STRIPE_GATEWAY => 'Process international cards, Apple Pay, and foreign currency checkouts.',
            self::PAYSTACK_GATEWAY => 'Process local cards, direct bank transfers, USSD, and mobile money checkouts.',
            self::TICKET_TRANSFERS => 'Allow attendees to re-assign or gift confirmed ticket passes to other emails.',

            self::STRICT_KYC => 'Block ticket sales until the organizer corporate registration / CAC is verified.',
            self::EVENT_AUTO_APPROVAL => 'Publish events directly to the explore directory without admin moderation queue.',
            self::FRAUD_DETECTION_SHIELD => 'Score transactions for high velocity or mismatched card fingerprints.',

            self::API_RATE_LIMITING => 'Enforce strict 60 req/min throttles across public discovery and cart endpoints.',
            self::GATE_SCANNER_V2 => 'Enable IndexedDB client caching for high-speed offline QR validation at gates.',
            self::SMS_NOTIFICATIONS => 'Send automated ticket SMS receipts and urgent venue change alerts.',
            self::MAINTENANCE_MODE => 'Lock checkout and ticket sales while performing backend database migrations.',
        };
    }

    /**
     * Category grouping for UI display.
     */
    public function category(): string
    {
        return match ($this) {
            self::AUTH_REGISTRATION,
            self::AUTH_PASSWORD_RESET,
            self::AUTH_SOCIAL_LOGIN,
            self::AUTH_TWO_FACTOR,
            self::AUTH_SESSION_MANAGEMENT => 'auth',

            self::GUEST_CHECKOUT,
            self::PAYOUTS_AUTOMATION,
            self::STRIPE_GATEWAY,
            self::PAYSTACK_GATEWAY,
            self::TICKET_TRANSFERS => 'commerce',

            self::STRICT_KYC,
            self::EVENT_AUTO_APPROVAL,
            self::FRAUD_DETECTION_SHIELD => 'compliance',

            self::API_RATE_LIMITING,
            self::GATE_SCANNER_V2,
            self::SMS_NOTIFICATIONS,
            self::MAINTENANCE_MODE => 'system',
        };
    }

    /**
     * Default state if not explicitly configured in storage.
     */
    public function defaultActive(): bool
    {
        return match ($this) {
            self::AUTH_REGISTRATION,
            self::AUTH_PASSWORD_RESET,
            self::AUTH_SESSION_MANAGEMENT,
            self::GUEST_CHECKOUT,
            self::PAYSTACK_GATEWAY,
            self::STRIPE_GATEWAY,
            self::TICKET_TRANSFERS,
            self::API_RATE_LIMITING,
            self::GATE_SCANNER_V2,
            self::FRAUD_DETECTION_SHIELD => true,

            self::AUTH_SOCIAL_LOGIN,
            self::AUTH_TWO_FACTOR,
            self::PAYOUTS_AUTOMATION,
            self::STRICT_KYC,
            self::EVENT_AUTO_APPROVAL,
            self::SMS_NOTIFICATIONS,
            self::MAINTENANCE_MODE => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
