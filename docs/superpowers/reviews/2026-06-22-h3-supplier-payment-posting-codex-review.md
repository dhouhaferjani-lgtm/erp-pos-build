# H-3.1 Supplier Payment Posting — Codex Adversarial Review

Date: 2026-06-22  
Scope reviewed: `GeneralLedgerService::createSupplierPaymentJournalEntry()`, `PaymentController::store()`, and `PaymentTest` purchase-order payment coverage.  
Review mode: refute the patch against supplier-payment correctness, sign conventions, posted-entry semantics, balance refresh, and accounting completeness.

## Verdict

PASS after remediation.

## Findings

### HIGH — Supplier overpayment could create an unposted cash delta

Status: RESOLVED.

Initial patch allowed a purchase-order payment amount to exceed the adjusted purchase-order allocation. The repository balance was updated by the full payment amount, while supplier-payment GL was posted only for the capped allocation amount. Because this slice intentionally does not implement supplier advances, that would leave a cash movement without matching AP or advance GL.

Remediation:
- Added `totalAdjustedAllocated` tracking in `PaymentController::store()`.
- Added `SUPPLIER_PAYMENT_REQUIRES_FULL_ALLOCATION` rejection when purchase-order supplier payments would leave excess.
- Added `test_purchase_order_payment_rejects_unallocated_excess()`.

### LOW — Mixed AP/AR allocation guard should remain visible in future API work

Status: DOCUMENTED.

The single-payment API now rejects mixed purchase-order/customer document allocations. This is correct for the current data model because one `Payment` row has one direction/type and one repository delta sign. Future multi-document supplier payment work should preserve this invariant or split mixed directions into separate payments.

## Verification Reviewed

- `php artisan test --filter 'purchase_order_payment'`
- `php artisan test tests/Feature/Treasury/PaymentTest.php tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Treasury/TreasuryEventsTest.php`
- `php artisan test --filter PartnerBalanceServiceTest`
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php`
- `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php tests/Feature/Treasury/PaymentTest.php`

## Residual Risk

H-3 is not complete. This commit only wires single purchase-order payments through `PaymentController::store()`. Production supplier invoice/AP recognition, `PaymentController::storeMultiple()`, and `PaymentAllocationService` still need separate H-3 slices.
