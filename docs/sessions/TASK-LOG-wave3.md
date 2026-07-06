# Wave 3 Task Log

## Task 1: Migrations + models + `GoodsReceiptStatus` enum

- Files:
  - `apps/api/tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`
  - `apps/api/database/migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php`
  - `apps/api/app/Modules/Inventory/Domain/Enums/GoodsReceiptStatus.php`
  - `apps/api/app/Modules/Inventory/Domain/GoodsReceipt.php`
  - `apps/api/app/Modules/Inventory/Domain/GoodsReceiptLine.php`
- Red test:
  - `vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`
  - Output summary: 1 test, 3 assertions, 1 failure at `Schema::hasTable('goods_receipts')` because the ledger tables did not exist.
- Green test:
  - `vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`
  - Output summary: OK, 1 test, 70 assertions.
- Deviations:
  - The plan file referenced by `CODEX-TASK-wave3.md` is missing from the working tree but exists in git history at `241e681ac2ece0b4be6388bf6ae072453d48158c`; I read it with `git show` and followed the Wave 3 section from that commit.

## Task 2: GRN numbering via `document_sequences`

- Files:
  - `apps/api/tests/Unit/Document/GoodsReceiptNumberingTest.php`
  - `apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php`
- Red test:
  - `vendor/bin/phpunit tests/Unit/Document/GoodsReceiptNumberingTest.php`
  - Output summary: 4 tests, 2 assertions, 3 errors; `generateForKey()` was undefined.
- Green test:
  - `vendor/bin/phpunit tests/Unit/Document/GoodsReceiptNumberingTest.php`
  - Output summary: OK, 4 tests, 7 assertions.
  - `vendor/bin/phpunit tests/Unit/Document/DocumentNumberingServiceTest.php`
  - Output summary: OK, 9 tests, 17 assertions.
- Deviations:
  - None so far.

## Task 3: `GoodsReceiptService` writes header + lines; PO counters derived

- Files:
  - `apps/api/tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php`
  - `apps/api/app/Modules/Inventory/Application/DTOs/GoodsReceiptResult.php`
  - `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`
  - `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php`
  - `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/PurchaseOrderToGoodsReceiptConverter.php`
  - `apps/api/tests/Feature/Inventory/GoodsReceiptTest.php`
- Red test:
  - `vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php`
  - Output summary: 6 tests, 3 assertions, 5 errors; `GoodsReceiptResult` missing, service returned the old `Document`, and response `meta.goods_receipt` was absent.
- Green test:
  - `vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php tests/Feature/Inventory/GoodsReceiptTest.php tests/Feature/Inventory/PurchaseBonusGoodsReceiptTest.php tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php tests/Feature/Accounting/SupplierInvoiceGlTest.php`
  - Output summary: OK, 53 tests, 278 assertions.
- Deviations:
  - Minimal call-site fixes forced by `receiveGoods` / `receiveAll` returning `GoodsReceiptResult`: `PurchaseOrderController`, `PurchaseOrderToGoodsReceiptConverter`, and existing regression test call sites in `GoodsReceiptTest.php`.

## Task 4: DTOs + `typescript:transform`

- Files:
  - `apps/api/tests/Unit/Inventory/GoodsReceiptDataTest.php`
  - `apps/api/app/Modules/Inventory/Application/DTOs/GoodsReceiptData.php`
  - `apps/api/app/Modules/Inventory/Application/DTOs/GoodsReceiptLineData.php`
  - `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php`
- Red test:
  - `vendor/bin/phpunit tests/Unit/Inventory/GoodsReceiptDataTest.php`
  - Output summary: 2 tests, 0 assertions, 2 errors; `GoodsReceiptData` class was missing.
- Green test:
  - `vendor/bin/phpunit tests/Unit/Inventory/GoodsReceiptDataTest.php tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php`
  - Output summary: OK, 8 tests, 64 assertions.
  - `CACHE_STORE=array php artisan typescript:transform`
  - Output summary: transformed 380 PHP types to TypeScript; generated `GoodsReceiptData`, `GoodsReceiptLineData`, and `GoodsReceiptStatus`.
- Deviations:
  - None so far.

## Task 5: Idempotent per-tenant backfill command

- Files:
  - `apps/api/tests/Feature/Inventory/GoodsReceiptBackfillTest.php`
  - `apps/api/app/Console/Commands/BackfillGoodsReceiptsCommand.php`
- Red test:
  - `vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptBackfillTest.php`
  - Output summary: 3 tests, 4 assertions, 3 errors; `procurement:backfill-goods-receipts` command did not exist.
- Green test:
  - `vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptBackfillTest.php`
  - Output summary: OK, 3 tests, 35 assertions.
- Deviations:
  - None so far.

## Task 6: Wave-3 exit regression sweep

- Files:
  - `apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php`
  - `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`
  - `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php`
  - `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php`
  - `apps/api/app/Modules/Inventory/Domain/GoodsReceiptLine.php`
  - `apps/api/tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`
- Static-analysis/format fixes:
  - `DocumentNumberingService::generateForKey()` retry path simplified to avoid PHPStan's always-true/unreachable-loop finding while preserving one retry on first unique-key race.
  - `GoodsReceiptService` hardcoded bcmath scales replaced with named quantity/cost/working-scale constants; no behavior change.
  - `StockReservationService` two reservation quantity `bcadd()` calls replaced literal scale `4` with a named quantity-scale constant; no behavior change.
  - Pint applied import/order and style-only formatting to `InventoryServiceProvider`, `GoodsReceiptLine`, `GoodsReceiptService`, and `GoodsReceiptLedgerSchemaTest`.
- Verification:
  - `vendor/bin/phpunit tests/Unit/Document/GoodsReceiptNumberingTest.php tests/Unit/Document/DocumentNumberingServiceTest.php tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php tests/Feature/Inventory/GoodsReceiptTest.php tests/Feature/Inventory/PurchaseBonusGoodsReceiptTest.php tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php tests/Feature/Accounting/SupplierInvoiceGlTest.php`
  - Output summary after static-analysis edits: OK, 66 tests, 302 assertions.
  - `vendor/bin/phpunit tests/Feature/Inventory/StockReservationDefaultBatchTest.php`
  - Output summary for the precision-only reservation edit: OK, 1 test, 5 assertions.
  - `vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php tests/Unit/Document/GoodsReceiptNumberingTest.php tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php tests/Unit/Inventory/GoodsReceiptDataTest.php tests/Feature/Inventory/GoodsReceiptBackfillTest.php tests/Feature/Inventory/GoodsReceiptTest.php tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php tests/Feature/Inventory/PurchaseBonusGoodsReceiptTest.php tests/Feature/Inventory/IngressPrecisionTest.php tests/Feature/Inventory/WacBcmathTest.php tests/Feature/Inventory/LandedCostBcmathTest.php tests/Feature/Accounting/SupplierInvoiceGlTest.php tests/Feature/Accounting/GrIrChartSeedTest.php tests/Feature/Procurement/SupplierInvoiceMatcherTest.php tests/Feature/Procurement/SupplierInvoiceApiTest.php tests/Feature/Procurement/PurchaseBonusQuantityEntryTest.php`
  - Output summary: OK, 137 tests, 827 assertions.
  - `vendor/bin/phpstan analyse app/Modules/Inventory app/Modules/Document --memory-limit=1G`
  - Output summary: blocked before analysis in this sandbox by `Failed to listen on "tcp://127.0.0.1:0": Operation not permitted (EPERM)` from PHPStan parallel workers.
  - `vendor/bin/phpstan analyse app/Modules/Inventory app/Modules/Document --memory-limit=1G --debug`
  - Output summary: OK, no errors. `--debug` was used to force single-process analysis because the sandbox disallows PHPStan's local TCP worker socket.
  - `vendor/bin/pint --test app/Console/Commands app/Modules/Inventory app/Modules/Document database/migrations/tenant tests/Feature/Inventory tests/Unit/Document tests/Unit/Inventory`
  - Output summary: `{"result":"pass"}`.
- Deviations:
  - Exact phpstan command could not run to completion under the sandbox because PHPStan parallel mode attempted to bind a local TCP socket and received EPERM. I reran the same paths with `--debug`, which disables parallel workers, and it passed with no errors.
  - `StockReservationService` and `InventoryServiceProvider` are outside the Wave-3 implementation file list, but the required `app/Modules/Inventory` phpstan/pint gates could not pass without a precision-only constant replacement and import-order formatting respectively. Both changes are behavior-neutral and were covered by the focused reservation test plus the full Wave-3 regression path list.

WAVE 3 COMPLETE

## FIX ROUND: W3-1..W3-11

- W3-1: Backfill eligibility now matches real historical purchase movements by `movement_type = receipt`, `reference_type = Document`, and `reference_id` pointing to a purchase-order document; the impossible `reason = goods_receipt` predicate was removed. Backfill fixtures now use `reason = null`, and one regression creates a movement through `WeightedAverageCostService::recordPurchase()` to prove the real writer shape is selected while non-PO receipt movements are ignored.
- W3-2: Backfill now resolves line plans before creating the header or drawing a GRN. Zero-mappable groups create no header/lines, burn no `document_sequences` row, and are reported as `Skipped unmappable groups`.
- W3-3: `BackfillGoodsReceiptsCommand` now normalizes decimal model values to `numeric-string` at the boundary and uses typed plan shapes. Required PHPStan scope is clean.
- W3-4: Migration now adds partial unique indexes on `goods_receipt_lines.movement_id` and `goods_receipt_lines.free_movement_id`, and backfill treats those unique violations as already-claimed movement groups.
- W3-5: Removed `GoodsReceiptResult::__get()` / `__call()` shims. Updated remaining Wave-3 test callers to use `$result->purchaseOrder` / `$result->receipt` explicitly.
- W3-6: `goods_receipt_lines.goods_receipt_id` now uses `restrictOnDelete()`.
- W3-7: Backfill grouping now clusters movements per PO when each movement is within 5 seconds of the previous movement; one-minute-apart receipts remain separate.
- W3-8: Backfill logs and prints a warning when synthesized received/free totals diverge from PO-line counters.
- W3-9: `PurchaseOrderToGoodsReceiptConverter` accepts `actor_user_id`, passes it to `receiveGoods`/`receiveAll`, and uses it on `DocumentConverted`; `DocumentConversionController` now passes the authenticated user id.
- W3-10: `GoodsReceiptService` passes the same half-up rounded landed cost to `effectiveUnitCost()` that it stores on the line.
- W3-11: Duplicate-product fallback is capacity-aware across duplicate PO lines; overflow movements map to the next line instead of always the first.

Red/proof tests added or expanded:
- `tests/Feature/Inventory/GoodsReceiptBackfillTest.php`: real `reason = NULL` purchase movement selection, non-PO receipt exclusion, unmappable group no-header/no-GRN rerun stability, 5-second grouping, one-minute split, reconciliation warning, capacity-aware duplicate-product fallback.
- `tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php`: paid-only rounded effective cost and converter actor stamping.
- `tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`: restrictive FK and partial movement-claim indexes.
- `tests/Feature/Inventory/GoodsReceiptTest.php` and `tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php`: explicit `GoodsReceiptResult` access.
- `tests/Feature/Inventory/BatchChainE2ETest.php`: PostgreSQL-only guard for the existing POS FEFO `FOR UPDATE SKIP LOCKED` path; under SQLite this file reports one intentional skip.

Verification:
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptBackfillTest.php`
  - Output summary: OK, 8 tests, 66 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php`
  - Output summary: OK, 8 tests, 40 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`
  - Output summary: OK, 1 test, 79 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptTest.php`
  - Output summary: OK, 25 tests, 83 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php`
  - Output summary: OK, 4 tests, 23 assertions.
- Full recorded Wave-3 regression path list:
  - `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php tests/Unit/Document/GoodsReceiptNumberingTest.php tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php tests/Unit/Inventory/GoodsReceiptDataTest.php tests/Feature/Inventory/GoodsReceiptBackfillTest.php tests/Feature/Inventory/GoodsReceiptTest.php tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php tests/Feature/Inventory/PurchaseBonusGoodsReceiptTest.php tests/Feature/Inventory/IngressPrecisionTest.php tests/Feature/Inventory/WacBcmathTest.php tests/Feature/Inventory/LandedCostBcmathTest.php tests/Feature/Accounting/SupplierInvoiceGlTest.php tests/Feature/Accounting/GrIrChartSeedTest.php tests/Feature/Procurement/SupplierInvoiceMatcherTest.php tests/Feature/Procurement/SupplierInvoiceApiTest.php tests/Feature/Procurement/PurchaseBonusQuantityEntryTest.php`
  - Output summary: OK, 144 tests, 870 assertions.
- Additional shim-adjacent files:
  - `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Inventory/BatchChainE2ETest.php`
    - Output summary: OK with one intentional SQLite skip (`Requires PostgreSQL: POS FEFO sale uses FOR UPDATE SKIP LOCKED.`).
  - `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Accounting/GoodsReceiptGlTest.php`
    - Output summary: OK, 5 tests, 38 assertions.
  - `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Expense/LinkedCostExpenseTest.php`
    - Output summary: OK, 3 tests, 32 assertions.
- Required PHPStan:
  - `vendor/bin/phpstan analyse app/Modules/Inventory app/Modules/Document app/Console/Commands --memory-limit=1G --debug`
  - Output summary: OK, no errors.
- Pint:
  - `vendor/bin/pint app/Console/Commands app/Modules/Inventory app/Modules/Document database/migrations/tenant tests/Feature/Inventory tests/Unit/Document tests/Unit/Inventory`
  - Output summary: fixed formatting in `BackfillGoodsReceiptsCommand.php`.
  - `vendor/bin/pint --test app/Console/Commands app/Modules/Inventory app/Modules/Document database/migrations/tenant tests/Feature/Inventory tests/Unit/Document tests/Unit/Inventory`
  - Output summary: `{"result":"pass"}`.

FIX ROUND COMPLETE

## FIX ROUND 2 (2026-07-04): inventory-costing-reviewer findings A + B

- Finding A (Important) — resumed-backfill invoiced double-count. `createReceiptLine()` seeded `$invoicedRemaining[$poLineId]` from the PO line's FULL `quantity_invoiced`, so a later run backfilling new movements for an already-partially-backfilled po_line re-apportioned the full invoiced qty (Σ ledger `quantity_invoiced` > PO line `quantity_invoiced`).
  - Fix: seed is now `quantity_invoiced − Σ(existing goods_receipt_lines.quantity_invoiced WHERE po_line_id = …)`, floored at 0, computed from the DB inside the per-group transaction. Dropped the cross-group in-memory `$invoicedRemaining` carryover in `backfillCompany()` (reset to `[]` per group); this also removes the rollback-staleness nit (a rolled-back group leaves no committed rows, so the next group reseeds correctly). Within a group, multiple lines for the same po_line still share the in-memory decrement via the `array_key_exists` guard, so within-group behavior is preserved (and prior committed groups in the same run are picked up by the DB reseed, matching the existing multi-receipt-single-run expectations).
  - Red test: `resumed_backfill_does_not_double_count_invoiced_quantity_across_runs` — asserted `7.0000`, got `10.0000` (double-count) before fix; passes after.
  - Files: `app/Console/Commands/BackfillGoodsReceiptsCommand.php`.

- Finding B (Important) — multi-company GRN collision. `goods_receipts` had `unique(['tenant_id','receipt_number'])`, but GRN numbers come from `document_sequences` scoped per (company_id, type, year), so in a tenant with 2+ companies, company B's first `GRN-YYYY-0001` deterministically 500s on the unique (the numbering retry can't help — the collision is on `goods_receipts`, not the sequence table). Multi-company per tenant is a supported topology.
  - Fix: changed the unique to `unique(['company_id','receipt_number'])` and added `index('tenant_id')` to preserve tenant-scoped lookups. Deviates from spec §2.3's literal `(tenant_id, receipt_number)`; rationale = per-company sequence scoping makes tenant-level uniqueness structurally impossible without cross-company coordination.
  - Red test: `backfill_assigns_per_company_grn_numbers_without_cross_company_collision` — threw a `goods_receipts_tenant_id_receipt_number_unique` violation at `GoodsReceipt::create` before fix; both companies now mint `GRN-YYYY-0001` and persist. Schema test `GoodsReceiptLedgerSchemaTest` updated to assert `goods_receipts_company_id_receipt_number_unique` (unique) + `goods_receipts_tenant_id_index`.
  - Files: `database/migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php`, `tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`.

Verification:
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptBackfillTest.php tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php` → OK, 11 tests, 158 assertions.
- Full recorded Wave-3 regression path list (same list as FIX ROUND) → OK, 147 tests, 893 assertions.
- `vendor/bin/phpstan analyse app/Modules/Inventory app/Modules/Document app/Console/Commands --memory-limit=1G --debug` → OK, no errors.
- `vendor/bin/pint --test app/Console/Commands/BackfillGoodsReceiptsCommand.php database/migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php tests/Feature/Inventory/GoodsReceiptBackfillTest.php tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php` → `{"result":"pass"}`.

FIX ROUND 2 COMPLETE
