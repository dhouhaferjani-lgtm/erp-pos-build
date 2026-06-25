# Phase 0 Audit — Draft To Posted Lifecycle

HEAD: `5cf94a1f04731258704792b7aa07f714e9c718c3` on `fix/balance-ar-event-hardening`.

## Finding

**HIGH — non-POS AR/AP/advance/clearing entries are often created as Draft with no production posting path.**

Evidence:
- `GeneralLedgerService` creates invoice AR as Draft: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:75`.
- Credit-note AR Draft: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:150`.
- Customer payment clearing Draft: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:580`.
- Customer advance Draft: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:291`.
- Supplier invoice AP Draft: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:429`.
- Supplier payment clearing Draft: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:511`.
- Advance-to-AR clearing Draft: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:753`.
- POS account-charge AR Draft: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1322`.
- `GeneralLedgerService::postEntry()` exists and posts Draft entries: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1100`.
- Production `postEntry()` callers found are POS payment/tolerance paths: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:435`, `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:313`, and `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:406`.
- Non-POS workflows call draft-creating methods but do not post them, including `PaymentController`, `PaymentAllocationService`, `CloseInvoiceWithToleranceService`, `SalesOrderToInvoiceConverter`, and `TreasuryAccountChargeBridge`.
- The manual journal post route updates `status` directly instead of using `GeneralLedgerService::postEntry()`: `apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:154`.
- `PartnerBalanceService::getPartnerBalance()` filters `journal_entries.status = posted`: `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:46`.

## Acceptance Criteria

- Every AR/AP/advance/clearing entry created as Draft has a production posting path, either immediate post or a real accounting-cycle poster.
- Posting uses one consistent path that hashes, emits posting events, and refreshes affected partner balances after `Posted`.
- Partner-balance refresh is not called as if drafts affect balances; refresh occurs after posting or is deferred to the posting event.
- Tests cover draft exclusion, post transition, cached balance refresh after posting, and relevant fiscal projection flows.

## Test Plan

- Add targeted tests for account charge, customer deposit overflow, payment allocation, tolerance close, and prepayment application.
- Add regression coverage that manual journal posting uses the common posting service or otherwise emits the same event and balance refresh effects.
- Run targeted accounting/treasury tests; do not run the full PHPUnit suite.

