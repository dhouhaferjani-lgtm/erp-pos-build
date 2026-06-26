# R-1 Codex Adversarial Review

## Verdict

APPROVE. The implementation follows the corrected R-1 scope after Opus pre-review: it removes the PO-confirm supplier-invoice production wiring and the PaymentController supplier-payment branch, while preserving the pre-existing direct supplier GL helpers and tests.

## Checks Performed

- Verified `PurchaseOrderConfirmed` is still dispatched by `PurchaseOrderService`; only `PurchaseOrderConfirmedListener` and its provider mapping were removed.
- Verified `PaymentController::store()` no longer branches on `PaymentType::SupplierPayment`, no longer decrements repositories for purchase-order allocations, and always uses the customer payment GL writer for allocated store payments.
- Verified direct `createSupplierInvoiceJournalEntry()` and `createSupplierPaymentJournalEntry()` helpers were not removed and now have regression assertions for `Posted` status plus non-null `fiscal_hash`.
- Verified no remaining `PurchaseOrderConfirmedListener` hits under `apps/api/app` or `apps/api/tests`, and no `PaymentType::SupplierPayment` hit in `PaymentController`.

## Findings

No BLOCKER/HIGH findings.

## Residual Risk

Purchase-order payments now intentionally retain the pre-Codex customer-payment-shaped behavior. That is the owner-directed rollback state for R-1, not a designed supplier AP model. A proper supplier AP flow still needs the later GR/IR and supplier-invoice design described in the worklist.
