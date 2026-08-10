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
        'amount_exceeds_line_gross' => 'Discount amount cannot exceed the line gross amount (:gross).',
    ],
    'bonus_quantity' => [
        'sub_row' => 'including bonus: +:quantity free unit',
        'line_total' => 'Line total: :quantity expected delivered units',
    ],
    'pre_delivery_invoicing' => [
        /*
         | Wave 3 T25b/T25d. GUIDED-REQUIRE: the refusal must name where the
         | operator goes next, or it gets worked around by back-dating.
         */
        'refused' => 'This invoice contains physical goods that have not been delivered. Under this country\'s accounting rules a definitive goods invoice cannot be issued before delivery, so it cannot be posted yet.',
        'alternative_delivery_note' => 'Create and confirm a delivery note for the goods now, then post the invoice.',
        'alternative_advance_payment' => 'If the customer is paying up front, record a quote or sales order and register the payment as a customer advance. Invoice once the goods are delivered.',
        'legacy_bucket_label' => 'Invoiced before delivery (legacy / pre-policy)',
        'legacy_bucket_help' => 'Invoices posted before goods were delivered. New invoices can no longer be posted this way; this list is an exception register for documents that predate the policy.',
        'policy_in_force' => 'Policy in force: :policy (from :source)',
    ],
];
