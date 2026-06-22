# Phase 0 Audit — AR/AP GL Wiring Production Path

HEAD: `5cf94a1f04731258704792b7aa07f714e9c718c3` on `fix/balance-ar-event-hardening`.

## Finding

**HIGH — production invoice and credit-note GL posting omits `partner_id` on the AR line.**

The canonical production path is `DocumentPostingService::post()` dispatching `InvoicePosted` after commit, then `InvoicePostedListener` calling `AccountingService::createInvoiceGLEntries()` or `AccountingService::createCreditNoteGLEntries()`.

Evidence:
- `DocumentPostingService` schedules the posted event after commit: `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:193`.
- `InvoicePosted` carries `partnerId`: `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:203`.
- `InvoicePosted` is wired to `InvoicePostedListener`: `apps/api/app/Providers/EventServiceProvider.php:57`.
- `InvoicePostedListener` calls `AccountingService` for invoices and credit notes: `apps/api/app/Modules/Accounting/Listeners/InvoicePostedListener.php:29`.
- Invoice AR line omits `partner_id`: `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:144`.
- Credit-note AR reversal line omits `partner_id`: `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:260`.
- `PartnerBalanceService::getPartnerBalance()` requires `journal_lines.partner_id`: `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:41`.

`GeneralLedgerService::createFromInvoice()` and `createFromCreditNote()` do tag AR lines, but current app callers do not make them the production posted-document writer. Treat any claim that those methods are canonical for posted documents as stale.

## AR Truth

`documents.balance_due` is the practical production AR read model today:
- `Document` comments describe `balance_due` as a cached trigger-maintained column over allocations: `apps/api/app/Modules/Document/Domain/Document.php:591`.
- The trigger computes `balance_due = total - payment_allocations - credit_note_allocations`: `apps/api/database/migrations/tenant/2026_01_08_214145_add_balance_due_cache_trigger.php:33`.
- Aged receivables reads posted invoices by `balance_due > 0`: `apps/api/app/Modules/Accounting/Application/Services/Reports/AgedReceivablesService.php:93`.
- Smart payment open-invoice selection uses `COALESCE(balance_due, total) > 0`: `apps/api/app/Modules/Treasury/Presentation/Controllers/SmartPaymentController.php:177`.

## Acceptance Criteria

- Posting an invoice through `DocumentPostingService::post()` creates a posted customer receivable `journal_lines` row with `partner_id = documents.partner_id`.
- Posting a credit note through the same path creates the AR reversal row with `partner_id = documents.partner_id`.
- `PartnerBalanceService::refreshPartnerBalance()` after invoice/credit-note posting reflects AR movement for that partner.
- `reconcileSubledger(...CustomerReceivable)` reports zero `entries_without_partner` for production invoice/credit-note AR rows.
- Tests protect the listener path, not only direct `GeneralLedgerService` calls.

## Test Plan

- Extend `InvoiceAndCreditNoteGLIntegrationTest` or equivalent production-flow feature tests.
- Assert invoice AR debit and credit-note AR credit lines have `partner_id`.
- Assert refreshed partner balance sees invoice and credit-note AR movement.
- Run targeted `php artisan test --filter InvoiceAndCreditNoteGLIntegrationTest`.

