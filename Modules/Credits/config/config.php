<?php

declare(strict_types=1);

return [
    'name' => 'Credits',

    /*
    |--------------------------------------------------------------------------
    | Default Credit Payment Gateway
    |--------------------------------------------------------------------------
    | Supported: 'paystack', 'flutterwave'
    */
    'default_gateway' => env('CREDITS_DEFAULT_GATEWAY', 'paystack'),

    /*
    |--------------------------------------------------------------------------
    | Credit Pricing & Conversion Rates
    |--------------------------------------------------------------------------
    | 1 Credit = 1000 Kobo = 10 NGN
    */
    'rate_per_credit_kobo' => (int) env('CREDITS_RATE_PER_CREDIT_KOBO', 1000),

    /*
    |--------------------------------------------------------------------------
    | Credit Top-Up Packages
    |--------------------------------------------------------------------------
    */
    'packages' => [
        [
            'id' => 'starter',
            'name' => 'Starter Pack',
            'credits' => 100,
            'price_kobo' => 100000,
            'currency' => 'NGN',
            'discount_label' => null,
            'is_popular' => false,
            'is_active' => true,
        ],
        [
            'id' => 'growth',
            'name' => 'Growth Pack',
            'credits' => 500,
            'price_kobo' => 450000,
            'currency' => 'NGN',
            'discount_label' => 'Save 10%',
            'is_popular' => true,
            'is_active' => true,
        ],
        [
            'id' => 'power',
            'name' => 'Pro Accelerator',
            'credits' => 1500,
            'price_kobo' => 1200000,
            'currency' => 'NGN',
            'discount_label' => 'Save 20%',
            'is_popular' => false,
            'is_active' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway Specific Configurations
    |--------------------------------------------------------------------------
    */
    'gateways' => [
        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'base_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
        ],
        'flutterwave' => [
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY') ?: env('FLW_SECRET_KEY'),
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY') ?: env('FLW_PUBLIC_KEY'),
            'webhook_secret' => env('FLUTTERWAVE_SECRET_HASH') ?: env('FLW_SECRET_HASH'),
            'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
        ],
    ],
];
