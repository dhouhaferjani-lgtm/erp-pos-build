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
    'guided_delivery' => [
        'fefo_allocation_failed' => 'Automatic FEFO allocation failed. Confirm the delivery manually and choose the batch explicitly.',
    ],

    /*
     | Campaign N-2. The refusal an operator meets on day one, when a product has
     | no stock_levels row at all: the reservation lane (sales-order confirm) and
     | the WAC sale lane (delivery-note confirm, the two invoice convenience
     | endpoints) both answer with this, code INSUFFICIENT_STOCK. Quantities are
     | pre-rendered at the product unit's decimal_places by the exception.
     */
    'stock' => [
        'insufficient' => "Not enough stock for ':product' at ':location'. Available: :available, requested: :requested. Receive or transfer the goods first, then confirm.",
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

    /*
     | M5-terminal r2, tenancy `F-R2-2`. The to-bill queue's location refusals are
     | OPERATOR-facing: ToBillPage renders query failures through
     | <QueryError error={query.error} …> (:511-512), so a location-restricted user in a
     | company with no active location reads this message verbatim in a French or Arabic
     | UI. The refusal has no client-side mirror — the scope is resolved server-side from
     | the user's location entitlements — so it belongs here rather than in frontend i18n.
     */
    'to_bill_queue' => [
        'no_active_location_in_scope' => 'No active location is available within your allowed scope; select a location explicitly.',
    ],
];
