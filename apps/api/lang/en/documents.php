<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Document Validation Messages
    |--------------------------------------------------------------------------
    |
    | Server-side messages emitted by document FormRequest validators.
    | Frontend i18n still owns user-facing copy; these are the wire-format
    | messages returned in 422 responses for cases where the rule has no
    | natural client-side mirror (e.g. tolerance-margin computed server-side).
    |
    */

    'discount' => [
        'below_tolerance' => 'Discount must exceed the tolerance margin (:margin). For smaller residuals, use payment-tolerance write-off at settlement.',
    ],
];
