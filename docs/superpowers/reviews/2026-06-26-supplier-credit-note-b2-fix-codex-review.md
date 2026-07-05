VERDICT: SHIP
Prior concurrency HIGH: CLOSED

## BLOCKER

None.

## HIGH

None.

## MEDIUM

**[MEDIUM] New concurrency test proves stale reread, not real two-transaction serialization** — apps/api/tests/Feature/Accounting/SupplierCreditNoteGlTest.php:1045 — impact: the service implementation appears to close the stale-bypass bug, but the test does not actually run two concurrent posts, block on `lockForUpdate()`, or assert the real second caller returns as a clean idempotent no-op after seeing Posted + JE; it force-updates the row to Posted without a JE and expects an inconsistent-state exception, while the duplicate-index test directly inserts a duplicate JE at lines 1112-1121. Recommended fix: add a PostgreSQL-backed concurrency/integration test with two independent DB connections where request A locks/posts the Draft credit note and request B starts from a stale Draft model, waits, then returns without creating a second JE or applying a second `quantity_invoiced` reversal.

## LOW

None.

## SUMMARY

Prior concurrency HIGH is CLOSED. In `SupplierCreditNotePostingService::post()`, the transaction now reloads the credit note by id with `lockForUpdate()` before loading lines or running type/status/reason guards (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:69-81). The idempotent Posted path then checks for the existing `supplier_credit_note` JE and returns before invoice locks, PO-line locks, GL writes, or quantity mutation (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:104-150). Downstream GL and quantity work uses the reassigned locked model (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:153-193).

Serialization correctness: CLOSED. The locked reload is inside the same transaction and precedes every relevant guard and mutation. A second concurrent post of the same credit note should block at the credit-note row, then reread Posted and take the JE-exists no-op path. I found no guard still reading the stale caller model after line 77.

Lock ordering and deadlock: no shipping issue found. Credit-note post locks credit note -> linked invoice -> PO lines (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:77-140). Supplier-invoice post locks PO lines and does not lock the invoice row before its idempotency return; it only saves the invoice at the end of initial posting (apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:54-188). INFERRED from architecture: an initial supplier-invoice post racing a credit-note post for that invoice should not deadlock because the credit note rejects a not-yet-Posted invoice before taking PO-line locks (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:218-257).

Partial index correctness: no shipping issue found. The partial unique index is scoped only to `supplier_invoice` and `supplier_credit_note` (apps/api/database/migrations/tenant/2026_06_26_120000_unique_journal_entries_source_procurement.php:44-48), with a reversible `DROP INDEX IF EXISTS` down migration (apps/api/database/migrations/tenant/2026_06_26_120000_unique_journal_entries_source_procurement.php:51-53). GL writers confirm why global uniqueness is unsafe: `prepayment_application` uses invoice id as source id (apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:846-855), and `pos_receipt` uses receipt id per payment entry (apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1901-1910). For included types, the procurement flow writes `supplier_invoice` through GR-IR clearing (apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1239-1248) and `supplier_credit_note` through the credit-note entry method (apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1431-1440). The legacy `createSupplierInvoiceJournalEntry()` also writes `supplier_invoice` (apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:463-493), but `rg` found no app call sites, only tests; no current false-positive production collision was directly observed.

Idempotent path: preserved. The Posted-with-JE short-circuit returns under the credit-note row lock (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:104-110), and the existing retry test still asserts one JE and one quantity reversal (apps/api/tests/Feature/Accounting/SupplierCreditNoteGlTest.php:902-922).

Previously closed guards: no regression found. The locked model is used for type and reason guards (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:83-99), source invoice presence/type/company (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:206-234), cross-supplier partner match (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:236-246), linked-invoice Posted status (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:248-257), and PO-line linkage/ownership (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:272-323).

Test execution: focused tests passed locally with `php artisan test tests/Feature/Accounting/SupplierCreditNoteGlTest.php --filter='stale_draft_model|db_unique_constraint'` from `apps/api`: 2 tests, 8 assertions.
