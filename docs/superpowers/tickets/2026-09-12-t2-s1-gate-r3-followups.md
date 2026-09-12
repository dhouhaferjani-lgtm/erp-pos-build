# Ticket — T-2 S1 receipt spine, gate r3 follow-ups (2026-09-12)

Source registers: `docs/superpowers/reviews/2026-09-12-t2-s1-impl-gate-r3-{stock-gl,inventory}.md`. Not S1 blockers; owners named per row.

| Id | Where | What | Owner |
|---|---|---|---|
| I-17(r3) | `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php:136-138,149` (T-1 class, already on dev) | `DB::table('journal_entries')->count()` precondition is unscoped in a no-`RefreshDatabase` class; RED when run after any class that leaves a journal on the same PG database (reproduced as 7th class on `autoerp_test_t`). Scope the count to the test's tenant/company/source, or count only rows the test itself will create. Test-only; needs a PG run to prove. | T-1 owner / inventory seam |
| I-18(r3) | `apps/api/database/migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php:49-52` | `down()` deletes the `legacy_completion` receipts but leaves `quantity_received` counters `up()` wrote. Promotion checklist forbids rollback, so document `down()` as non-inverse or revert the counters. | T2/T3 lane, S2 |
| G-13(r3) | `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php:202-264` | "receipt line quantity must be positive" is enforced only by the FormRequest and a PG-only CHECK; add one `bccomp` guard in the service so an internal caller cannot write a movement-less receipt line on SQLite or 500 on PG. | T2/T3 lane, S2 |
| G-14(r3) | `apps/api/tests/Support/TruncatesRootTransactionDatabase.php:55-63` | Cleanup sets `session_replication_role='replica'` and nothing asserts the restore; add the assertion so a future adopter cannot leave triggers disabled. | test-support owner |
| G-15(r3) | `stock_movements`, `journal_entries` | No DB-level immutability trigger; append-only rests on the DPA ratchet + one test class. Informational; candidate for the accounting-boundary campaign. | architecture lane |
| I-16(r3) | spec rev 11 §5.4 | Closed by Amendment A-1 at the top of the spec (2026-09-12). | — |
| m3(fe r3) | `apps/web/src/locales/{en,fr}/stock-transfers.json:151` | No plural handling in the line-count disclosure; use `count` + `_one`/`_other`. | S4 |
| m4(fe r3) | `apps/web/src/features/stock-transfers/types/index.ts:4` | `StockTransferType` is a dead export duplicating generated `TransferType`; delete. | S4 |
| m5(fe r3) | `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38-39,98-119` | No `hasPermission` gating in the feature; view-only actors see Complete/Cancel and take a 403 (same class as the RBAC ticket `2026-09-12-rbac-w0a-unguarded-row-actions-menus-promotions-coupons.md`). Sibling precedent `StockAdjustmentDetailPage.tsx:80-81`. | S4 / RBAC 0b-15 |
