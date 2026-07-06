# Wave 3 (Goods-Receipt Ledger Foundation) — Fix-Round CLOSURE Verification

**Date:** 2026-07-04
**Scope:** Uncommitted working tree in `apps/erp.procurement-v2`, verifying the Codex fix round against findings W3-1..W3-11 of `docs/superpowers/reviews/2026-07-04-procurement-wave3-codereview.md`. Fix log: `docs/sessions/TASK-LOG-wave3.md` §FIX ROUND.
**Reviewer:** inventory-costing-reviewer (closure pass; every claim re-read in code, PHPStan + the two highest-risk suites re-run).

## Verdict: **CLOSED**

All eleven required/recommended findings (W3-1..W3-11) are genuinely fixed in code with real proof tests. Independently re-verified: PHPStan level 8 clean on `BackfillGoodsReceiptsCommand` + `GoodsReceiptService` + `GoodsReceiptResult` ([OK] no errors); `GoodsReceiptBackfillTest` + `GoodsReceiptLedgerWriteTest` = **16/16 green, 106 assertions**. The two highest-risk costing invariants (Σ receipt lines == PO counters; free-first/paid-last with correct movement_id capture) survived intact. No out-of-scope production edits, no weakened assertions, no silently deleted tests. One test was converted from an implicit SQLite error to an explicit skip — legitimate, not a mask (see Observations).

---

## Per-finding verification

### W3-1 — CRITICAL — CLOSED (fix real; proof test exercises the real failure mode)
Eligibility filter rewritten: `BackfillGoodsReceiptsCommand.php:105-118` now selects `movement_type = Receipt` + `reference_type = 'Document'` + `reference_id ∈ (Documents WHERE type = purchase_order)`; the impossible `reason = goods_receipt` predicate is gone. This matches the REAL writer: `GoodsReceiptService.php:235,274` calls `recordPurchase(... referenceType: 'Document', referenceId: $purchaseOrder->id ...)`, and `recordPurchase` never sets `reason` (stays NULL).
Proof test `backfill_selects_real_purchase_receipt_movements_with_null_reason_and_ignores_non_po_receipts` (`GoodsReceiptBackfillTest.php:117-159`) creates the movement through the **real** `WeightedAverageCostService::recordPurchase()` path, asserts `$movement->fresh()?->reason` is NULL (`:154`), then asserts `Eligible movements: 1` / `Created receipts: 1` and `line->movement_id === $movement->id`. The fabricated `createMovement` helper was corrected to `reason => null` (`:386`) — the value was NOT merely renamed. A non-PO receipt movement (a `CreditNote`-referencing Receipt movement) is proven NOT swept.
Minor note (not a reopen): the exclusion fixture uses a `CreditNote` document rather than an actual `recordReturn` movement; functionally it exercises the same gate (a `Receipt`-type movement whose reference resolves to a non-purchase_order document is excluded), which is exactly the discriminator the spec §2.3.2 relies on.

### W3-2 — MAJOR — CLOSED
`planLinesForGroup()` runs BEFORE any header/GRN draw; an empty plan returns `[0, 0, 1]` at `BackfillGoodsReceiptsCommand.php:159-164`, so `GoodsReceipt::create` + `generateForKey` (`:166-175`) are never reached for unmappable groups. Test `unmappable_groups_create_no_header_burn_no_grn_and_report_stably_on_rerun` asserts 0 headers, 0 lines, `Skipped unmappable groups: 1` stable across two runs, and `document_sequences` count 0 (no GRN number burned).

### W3-3 — MAJOR — CLOSED (independently re-run)
`vendor/bin/phpstan analyse app/Console/Commands/BackfillGoodsReceiptsCommand.php app/Modules/Inventory/Application/Services/GoodsReceiptService.php app/Modules/Inventory/Application/DTOs/GoodsReceiptResult.php` → **[OK] No errors**. Decimal reads are narrowed to `numeric-string` via `decimalString()` (`:486-493`, backed by `CurrencyScale::bcformatStrict`); `shiftMatchingFreeMovement` is typed `array<int, StockMovement>` with `array_values` re-index (`:355-370`).

### W3-4 — MAJOR — CLOSED
Migration adds partial unique indexes `goods_receipt_lines_movement_id_unique … WHERE movement_id IS NOT NULL` and `…_free_movement_id_unique … WHERE free_movement_id IS NOT NULL` (`2026_07_04_100000_create_goods_receipts_tables.php:56-64`); `down()` drops both. The command catches the unique violation as an already-claimed group and skips cleanly (`isMovementClaimUniqueViolation :449-464`, caught `:191-198`). Schema test asserts both indexes exist and are unique (`GoodsReceiptLedgerSchemaTest.php:93-97,116-117`).

### W3-5 — MAJOR — CLOSED (shims fully gone; no caller depends on forwarding)
`GoodsReceiptResult.php` is now the plain `final readonly` DTO `{Document $purchaseOrder, GoodsReceipt $receipt}` — `rg "__get|__call"` returns nothing. Every production caller uses `->purchaseOrder` (`PurchaseOrderController.php:686-695`, `PurchaseOrderToGoodsReceiptConverter.php:140,143`; seeder discards). Test call sites migrated: `GoodsReceiptTest.php` (7 sites → `$result->purchaseOrder->status/->payload/->lines`), `GoodsReceiptServiceVariantTest.php:246,311,379`. A repo-wide sweep for `$result->{status,lines,payload}` on a receipt result found only unrelated fiscal-parser hits — zero lingering forwarding.

### W3-6 — MINOR — CLOSED
`goods_receipt_lines.goods_receipt_id` is now `->restrictOnDelete()` (`migration:34`). Schema test asserts `restrictOnDelete()` in the migration source.

### W3-7 — MINOR — CLOSED
`clusterMovementsByReceiptBatch()` (`:212-243`) groups per PO while consecutive movements are within 5s (`diffInSeconds(...) > 5` starts a new group). Tests `batch_grouping_keeps_movements_within_five_seconds_together` and `..._one_minute_apart_separate` pin both directions.

### W3-8 — MINOR — CLOSED
`warnOnCounterMismatch()` (`:419-447`) cross-checks Σ `received_qty`/`free_qty` vs `quantity_received`/`free_quantity_received` per PO line and both `$this->warn(...)` + `Log::warning(...)`. Test `backfill_warns_when_synthesized_quantities_do_not_reconcile_to_po_counters` asserts the warning fires.

### W3-9 — MINOR — CLOSED
Converter accepts `actor_user_id`, threads it to `receiveGoods`/`receiveAll` AND `DocumentConverted.userId` (`PurchaseOrderToGoodsReceiptConverter.php:112-114,140,143,156`); `DocumentConversionController.php:345` passes the authenticated user id. Test `purchase_order_converter_stamps_the_actor_on_the_goods_receipt` asserts `received_by = user->id`.

### W3-10 — NIT — CLOSED
`GoodsReceiptService.php:333` now feeds `effectiveUnitCost()` the same `CurrencyScale::bcround($landedUnitCost, self::COST_SCALE)` value it stores (`:328-329`), replacing the truncating `bcformatStrict`. Test `paid_only_effective_unit_cost_uses_the_same_half_up_rounding_as_landed_cost` uses `landed_unit_cost = 1.1234567` and asserts both `landed` and `effective` = `1.123457` (half-up), proving the paid-only `effective = landed` invariant holds at the rounding boundary.

### W3-11 — NIT — CLOSED
`resolvePoLine()` is now capacity-aware across duplicate PO lines: a line is skipped when the movement quantity exceeds its remaining capacity and duplicates exist (`:319-339`), advancing to the next line; `consumeLineCapacity` tracks per-line assignment. Test `duplicate_product_po_lines_warn_and_fall_back_to_fifo_assignment` now sends two movements (qty 1, qty 2) and asserts they land on line 1 then line 2 respectively.

---

## Highest-risk invariant spot-checks (both survived)

1. **Σ receipt lines == PO counters (all shapes).** Live path PO-line counter writes unchanged (`GoodsReceiptService.php:263,309`); `assertLedgerCountersMatchPo` is exercised after single/partial/free-only receipts and passes in the re-run (`GoodsReceiptLedgerWriteTest` 8 tests green). Backfill adds a reconciliation warning for the synthesized side (W3-8).
2. **Free-first / paid-last with correct movement_id capture.** `movementId`/`freeMovementId` reset per line (`:207-208`); free `recordPurchase` block (`:228-264`, captures `free_movement_id` `:251`) precedes the paid block (`:266-310`, captures `movement_id` `:290`) so `last_purchase_cost` = the paid price; the `GoodsReceiptLine` write consumes both captured ids (`:335-336`). Untouched by the fix round except the W3-10 one-liner.

## Observations (no action required)

- **`BatchChainE2ETest.php:166-169` SQLite skip is legitimate, not a mask.** The POS FEFO sale in that chain runs `FEFOInventoryService.php:152-162` with an unconditional raw `FOR UPDATE OF ibs SKIP LOCKED`, which SQLite cannot parse — so the test was already erroring under the default SQLite suite before the fix round. The test does NOT consume the `GoodsReceiptResult` shim (line 188 discards the return), so the skip hides neither the shim removal nor any fix-round change (no FEFO/POS production code was touched). Net effect: that end-to-end chain is now PG-only in CI — a pre-existing coverage limitation made explicit.
- `CompanyContext::setCompanyId(...)` added in `GoodsReceiptBackfillTest::setUp` is required for the real `recordPurchase` fixture and is test-scoped.
- `warnOnCounterMismatch` uses a `selectRaw SUM` aggregate; under SQLite this is only a warning path (no money moves on it), so the "SQLite masks PG SUM" risk is immaterial here.

## What to fix before merge
Nothing blocking — all W3-1..W3-11 are genuinely closed; optionally note the BatchChain PG-only coverage in the command/test docblock.
