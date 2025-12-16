<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    */
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),

    'invoice_footer' => env(
        'BILLING_INVOICE_FOOTER',
        'Thank you for your business. Payment is due within 14 days.'
    ),

    'default_tax_rate' => env('BILLING_DEFAULT_TAX_RATE', 20.0),

    'default_country' => env('BILLING_DEFAULT_COUNTRY', 'FR'),

    /*
    |--------------------------------------------------------------------------
    | Company Information (appears on invoices)
    |--------------------------------------------------------------------------
    */
    'company' => [
        'name' => env('BILLING_COMPANY_NAME', 'Mecanospex'),
        'address' => env('BILLING_COMPANY_ADDRESS', ''),
        'city' => env('BILLING_COMPANY_CITY', ''),
        'postal_code' => env('BILLING_COMPANY_POSTAL_CODE', ''),
        'country' => env('BILLING_COMPANY_COUNTRY', ''),
        'phone' => env('BILLING_COMPANY_PHONE', ''),
        'email' => env('BILLING_COMPANY_EMAIL', ''),
        'website' => env('BILLING_COMPANY_WEBSITE', ''),
        'vat_number' => env('BILLING_COMPANY_VAT', ''),
        'registration' => env('BILLING_COMPANY_REGISTRATION', ''),
        'logo_path' => env('BILLING_COMPANY_LOGO', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bank Transfer Settings (for manual payments)
    |--------------------------------------------------------------------------
    */
    'bank_transfer' => [
        'bank_name' => env('BILLING_BANK_NAME', ''),
        'iban' => env('BILLING_IBAN', ''),
        'bic' => env('BILLING_BIC', ''),
        'account_holder' => env('BILLING_ACCOUNT_HOLDER', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Provider Settings
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'stripe' => [
            'enabled' => env('STRIPE_KEY') && env('STRIPE_SECRET'),
        ],
        'paypal' => [
            'enabled' => env('PAYPAL_CLIENT_ID') && env('PAYPAL_SECRET'),
        ],
        'flouci' => [
            'enabled' => env('FLOUCI_APP_TOKEN') && env('FLOUCI_APP_SECRET'),
        ],
        'konnect' => [
            'enabled' => env('KONNECT_API_KEY'),
        ],
        'click_to_pay' => [
            'enabled' => env('CTP_TERMINAL_ID') && env('CTP_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trial Settings
    |--------------------------------------------------------------------------
    */
    'trial' => [
        'days' => env('BILLING_TRIAL_DAYS', 14),
        'require_card' => env('BILLING_TRIAL_REQUIRE_CARD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grace Period (days after payment due before access restriction)
    |--------------------------------------------------------------------------
    */
    'grace_period_days' => env('BILLING_GRACE_PERIOD_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Dunning Settings (payment retry schedule)
    |--------------------------------------------------------------------------
    */
    'dunning' => [
        'retry_days' => [3, 5, 7], // Days after due date to retry payment
        'max_retries' => 3,
    ],
];
