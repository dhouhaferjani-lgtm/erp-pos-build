<?php

declare(strict_types=1);

return [

    /*
    |----------------------------------------------------------------------
    | N-6 — printout marker for a confirmed (not yet posted) fiscal document
    |----------------------------------------------------------------------
    | Dotted keys on purpose. The documents blade tree calls `__()` with
    | ENGLISH NATURAL keys everywhere else (`__('Tax ID')`, `__('Qty')`), and
    | no `lang/*.json` file exists, so every one of those renders as literal
    | English regardless of locale. Only dotted keys reach these PHP arrays
    | and actually translate — and this line has to translate.
    */
    /*
    |----------------------------------------------------------------------
    | C-F0 / SPEC §2.4 (F-13, F-64, F-95) — the PROFORMA rendering
    |----------------------------------------------------------------------
    | Replaces the N-6 not-yet-posted marker on an UNSEALED fiscal document
    | (`posting_marker.title` / `.detail` are gone with it). N-6 put a warning
    | next to the VAT; F-95 removes the VAT. Under Code TVA Art. 18 the VAT
    | mentioned on an issued invoice is owed by the act of issuing it, so a
    | numbered VAT-bearing paper the ledger has never seen is a liability the
    | tenant never chose — and one the recipient can deduct from.
    |
    | The wording below deliberately contains none of the tokens F-95 forbids on
    | such a page (`VAT`, `TVA`, `tax`, `TTC`, `HT`, `posted`, `comptabilisée`,
    | seal/hash/chain/QR wording); `ProformaOutputTest` scans the rendered HTML
    | and the extracted PDF text for every one of them, in en, fr and ar. A
    | translator who reintroduces one fails that test, which is the point.
    |
    | Dotted keys, for the reason `posting_marker` gives below: only dotted keys
    | reach these PHP arrays and actually translate.
    */
    'proforma' => [
        'title' => 'Proforma — non-fiscal document',
        'detail' => 'This is an estimate issued before the sale has been entered in the accounts. It is not a definitive fiscal document, it carries no seal, and it confers no right of deduction. A definitive document will be issued once the sale is entered.',
        'estimated_total' => 'Estimated total',
    ],

    'posting_marker' => [
        'cancelled_title' => 'Cancelled — this document has been voided',
        'cancelled_detail' => 'This document was posted and sealed, and has since been cancelled. Its fiscal seal remains in the hash chain; the document itself is void and must not be used as a claim.',
        'cancelled_unsealed_detail' => 'This document has been cancelled and must not be used as a claim. It was never posted to the accounts and carries no fiscal seal.',
        'historical_title' => 'Opening balance — carried over from a previous system',
        'historical_detail' => 'This document records a balance that was already standing when the accounts were opened here. It was posted in the previous system and carries no fiscal seal in this one.',
    ],

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

    /*
     | Campaign W2-6 (gate r2 C2). A purchase line the operator never priced is
     | persisted as 0.000 by the draft autosave, and past that boundary nothing can
     | tell it from a deliberate zero. Confirm refuses it, code PO_LINE_UNPRICED.
     | An explicit free-of-charge bonus line (`is_bonus_line`) is exempt.
     */
    'purchase_order' => [
        'line_unpriced' => 'Line :line (":description") has no unit price. Enter the supplier\'s price on every line before confirming this purchase order — confirming at 0.000 would receive the goods into stock at no value and distort your stock valuation.',
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
