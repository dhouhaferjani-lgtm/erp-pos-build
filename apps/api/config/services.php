<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google reCAPTCHA v3 — used by the public Scheduling storefront endpoints
    | (apps/api/app/Modules/Scheduling). The secret key is server-side only.
    | RECAPTCHA_MIN_SCORE is the threshold below which verify() returns false;
    | default 0.5 balances friction vs bot traffic per Google's guidance.
    |--------------------------------------------------------------------------
    */

    'recaptcha' => [
        'secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
        'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
        'hostname' => env('RECAPTCHA_HOSTNAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Providers
    |--------------------------------------------------------------------------
    */

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'sandbox' => env('PAYPAL_SANDBOX', true),
    ],

    'klarna' => [
        'username' => env('KLARNA_USERNAME'),
        'password' => env('KLARNA_PASSWORD'),
        'sandbox' => env('KLARNA_SANDBOX', true),
    ],

    'sepa' => [
        'creditor_id' => env('SEPA_CREDITOR_ID'),
    ],

    'flouci' => [
        'app_token' => env('FLOUCI_APP_TOKEN'),
        'app_secret' => env('FLOUCI_APP_SECRET'),
        'sandbox' => env('FLOUCI_SANDBOX', true),
    ],

    'clicktopay' => [
        'merchant_id' => env('CLICKTOPAY_MERCHANT_ID'),
        'secret' => env('CLICKTOPAY_SECRET'),
        'sandbox' => env('CLICKTOPAY_SANDBOX', true),
    ],

    'konnect' => [
        'api_key' => env('KONNECT_API_KEY'),
        'sandbox' => env('KONNECT_SANDBOX', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Syneriva Platform Integration
    |--------------------------------------------------------------------------
    */

    'platform' => [
        'url' => env('SYNERIVA_PLATFORM_URL', 'http://localhost:8080'),
        'api_key' => env('SYNERIVA_PLATFORM_API_KEY'),
        'webhook_secret' => env('SYNERIVA_WEBHOOK_SECRET'),
        'push_enabled' => env('SYNERIVA_PLATFORM_PUSH_ENABLED', true),
        'dev_lookup_stub_enabled' => env('SYNERIVA_PLATFORM_DEV_LOOKUP_STUB', false),
    ],

    'vin_decoder' => [
        'default_provider' => env('VIN_DECODER_PROVIDER', 'vindecoder_eu'),
        'api_key' => env('VIN_DECODER_API_KEY'),
        'base_url' => env('VIN_DECODER_URL', 'https://api.vindecoder.eu/3.2'),
        'cache_ttl' => (int) env('VIN_DECODER_CACHE_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Growth Advisor Service
    |--------------------------------------------------------------------------
    */

    'growth_advisor' => [
        'url' => env('GROWTH_ADVISOR_URL', 'http://localhost:8004'),
        'timeout' => 30,
        'connect_timeout' => 10,
        'retry_times' => 2,
        'retry_delay' => 200,
        'circuit_breaker_threshold' => 3,
        'circuit_breaker_cooldown' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommendation Engine Service (erp-ml)
    |--------------------------------------------------------------------------
    */

    'recommendation_engine' => [
        'url' => env('RECOMMENDATION_ENGINE_URL', 'http://localhost:8002'),
        'timeout' => 5,
        'connect_timeout' => 3,
        'retry_times' => 2,
        'retry_delay' => 200,
        'circuit_breaker_threshold' => 3,
        'circuit_breaker_cooldown' => 30,
    ],

    'erp_ml' => [
        'url' => env('ERP_ML_URL', 'http://127.0.0.1:8002'),
        'service_token' => env('ERP_ML_SERVICE_TOKEN'),
    ],

];
