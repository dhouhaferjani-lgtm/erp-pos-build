# H-3.2 Supplier Invoice Posting — Codex Adversarial Review

Date: 2026-06-22  
Scope reviewed: `PurchaseOrderConfirmedListener`, `EventServiceProvider` wiring, `GeneralLedgerService::createSupplierInvoiceJournalEntry()`, and `PurchaseOrderServiceTest` coverage.  
Review mode: refute the patch against AP recognition timing, idempotency, partner subledger correctness, and production preconditions.

## Verdict

PASS.

## Findings

### No BLOCKER/HIGH findings

The patch wires the production purchase-order confirmation event to AP recognition without inventing a new document type. That matches current local behavior: purchase orders are the AP-facing documents used by aged payables and supplier payments.

The supplier payable line is partner-tagged, the entry is posted via the shared post-and-refresh helper, and the test asserts `partners.payable_balance` refreshes from the posted AP line.

### LOW — Chart of accounts is now a hard confirmation precondition

Status: ACCEPTED.

`PurchaseOrderConfirmedListener` uses `Account::findByPurposeOrFail()` for PurchaseExpenses and the GL service uses the same fail-loud pattern for SupplierPayable/VatDeductible. This means purchase-order confirmation can fail when a company lacks an initialized chart of accounts. That is consistent with the existing accounting helpers, and the unit fixture now seeds the chart so tests represent the production precondition.

### LOW — VAT path is covered by GL helper, not by the production confirmation unit test

Status: ACCEPTED.

The production confirmation test targets the no-VAT path because tax recoverability/company setup is outside this AP wiring slice. `GLIntegrationTest::test_supplier_invoice_creates_correct_journal_entry()` continues to cover the VAT-deductible line behavior in the GL helper.

## Verification Reviewed

- `php artisan test tests/Unit/Document/PurchaseOrderServiceTest.php tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Treasury/PaymentTest.php`
- `php artisan test --filter PartnerBalanceServiceTest`
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Accounting/Listeners/PurchaseOrderConfirmedListener.php app/Providers/EventServiceProvider.php`
- `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Accounting/Listeners/PurchaseOrderConfirmedListener.php app/Providers/EventServiceProvider.php tests/Unit/Document/PurchaseOrderServiceTest.php`

## Residual Risk

The listener's idempotency check skips any existing `supplier_invoice` source entry, regardless of status. That prevents duplicate source entries, but legacy pre-existing draft entries for the same source would not be auto-posted by this listener. No such production writer existed before this slice, so this is documented as a legacy-data remediation concern rather than a blocker.
