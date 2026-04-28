<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Development/deployment feature flags. All flags default to false (off)
    | in production and must be explicitly enabled via environment variables.
    |
    | These are server-side deployment gates — distinct from the billing-tier
    | plan limits in app/Modules/Billing/Domain/PlanLimits.php.
    |
    */

    'documents' => [

        /**
         * Per-line designation override + additional description on documents.
         *
         * When enabled, each document line exposes:
         *  - a free-text designation override (shown in place of the product
         *    catalogue name on PDFs and e-invoicing payloads), and
         *  - a free-text notes field rendered as a sub-line on PDFs.
         *
         * Gate: FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE=true
         */
        'line_designation_override' => [
            'enabled' => env('FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE', false),
            'description' => 'Per-line designation override + additional description on documents.',
        ],

    ],

];
