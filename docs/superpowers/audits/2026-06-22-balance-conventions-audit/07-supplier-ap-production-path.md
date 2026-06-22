# Phase 0 Audit — Supplier AP Production Path

HEAD: `5cf94a1f04731258704792b7aa07f714e9c718c3` on `fix/balance-ar-event-hardening`.

## Finding

**HIGH — supplier invoice/payment AP posting has no normal production caller.**

Evidence:
- `GeneralLedgerService::createSupplierInvoiceJournalEntry()` is defined at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:402`.
- `GeneralLedgerService::createSupplierPaymentJournalEntry()` is defined at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:487`.
- These methods create partner-tagged `SupplierPayable` lines: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:465` and `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:520`.
- Direct callers found are tests only: `apps/api/tests/Feature/Accounting/GLIntegrationTest.php:322` and `apps/api/tests/Feature/Accounting/GLIntegrationTest.php:367`.
- Purchase-order routes expose create/confirm/receive but no AP post route: `apps/api/app/Modules/Document/Presentation/routes.php:210`.
- `PurchaseOrderService` confirms and dispatches `PurchaseOrderConfirmed`: `apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:79` and `apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:109`.
- No accounting listener for `PurchaseOrderConfirmed` is registered in `EventServiceProvider`: `apps/api/app/Providers/EventServiceProvider.php:51`.
- `payable_balance` is calculated generically from posted `SupplierPayable` lines: `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:320`.

## Acceptance Criteria

- A production supplier invoice action creates posted, partner-tagged `SupplierPayable` GL lines.
- A production supplier payment action creates posted, partner-tagged payable-clearing lines.
- `partners.payable_balance` refreshes after those postings.
- Source document/payment links to the journal entry.
- Duplicate posting is idempotent.

## Test Plan

- Add feature tests through production APIs/services for supplier invoice posting and supplier payment posting.
- Assert posted journal entries, AP debit/credit lines with `partner_id`, refreshed `payable_balance`, source linkage, and duplicate-call idempotency.

