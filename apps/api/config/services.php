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

];
