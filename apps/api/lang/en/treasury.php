<?php

declare(strict_types=1);

return [
    'deferred_method_not_supported_on_this_path' => 'Deferred payment methods are not supported on this payment path.',

    /*
     * Labels of the two repositories a freshly registered tenant is born with
     * (DPA lane H-3). Read by PaymentRepositorySeeder under the registering
     * company's locale — a tenant must never be handed English defaults it did
     * not ask for. The operator renames them freely afterwards; these are
     * creation-time labels, not a runtime lookup.
     */
    'default_repositories' => [
        'cash_register' => 'Main Cash Register',
        'safe' => 'Office Safe',
    ],
    /*
     * C-0a0 — why a document was refused a payment allocation
     * (SPEC-document-lifecycle-dimensions §2.1, `AllocationRefusalReason`).
     * Rendered as the `message` of a 422 `DOCUMENT_NOT_ALLOCATABLE`; the enum
     * value travels alongside it in `details.reason`.
     */
    'allocation_refused' => [
        'document_not_live' => 'This document cannot receive a payment in its current status. Only confirmed or posted documents can be paid.',
        'historical_opening_provenance' => 'This is a supplier opening balance, or an opening balance whose side could not be determined. Supplier opening balances cannot be settled yet — support for paying them is coming in a later release.',
        'pos_derived_provenance' => 'This invoice comes from a point-of-sale account charge. It is settled through the customer account, not from this screen.',
        'status_not_allocatable_for_type' => 'This document cannot receive a payment in its current status. Post it first, then record the payment.',
        'outward_document_type' => 'Credit notes are money owed to the other party: apply them to another document or refund them, rather than receiving a payment against them.',
        'purchase_order_wrong_direction' => 'Purchase orders cannot receive a payment. Record the payment against the supplier invoice once it is posted.',
        'type_never_allocatable' => 'This document type never carries a balance a payment could settle.',
        'payable_not_settleable_here' => 'This is a supplier invoice. Record the payment through the supplier payment flow, which pays the supplier and clears the payable.',
        'partner_role_mismatch' => "This document's type does not match the partner's role, so the payment direction cannot be determined. A customer invoice must belong to a customer and a supplier invoice to a supplier. If this partner is both, set its type to Both; otherwise correct the document.",
    ],
];
