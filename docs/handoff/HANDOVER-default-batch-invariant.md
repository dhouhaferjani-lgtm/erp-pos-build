# HANDOVER — Default-batch invariant (remaining seams + follow-ups)

> Status as of 2026-06-27. Branch `fix/parapharmacy-batch-seeding` (worktree `../erp.batch-seed`).
> The invariant: **a product with `requires_batch_tracking = true` must never hold stock that
> isn't inside a lot** (`product_batches` + `inventory_batch_stock`). Otherwise PO goods-receipt,
> stock transfers, and POS lot selection silently block (no lot to pick).

## DONE in this branch

- **`BatchStockService::ensureDefaultBatch()`** — shared "default behavior": mints/reuses one
  `DEFAULT` lot per product (+ variant), expiry = `asOfDate + default_shelf_life_days`
  (fallback `BatchStockService::DEFAULT_SHELF_LIFE_DAYS = 365`), reconciles the location's
  `BatchStock` up to a target. Idempotent, never reduces, skips non-positive targets.
- **`OpeningBalancePostingService::post()`** — for batch-tracked lines, backs the opened quantity
  with a default lot. Covers inline product-create-with-opening, `postOpening`, and the bulk
  `InventoryOpeningService` import (all route through this service).
- **Seeders** — `ParapharmacySeeder` + `DemoPharmacySeeder` (the Tunisia multi-branch keeper) now
  reconcile default lots for all batch-tracked stock at every location.
- **Deleted** `ParapharmacyMultiBranchSeeder` (France duplicate of the Tunisia demo) + its test;
  removed the FR-multi-branch case from `DemoSeedersTaxTest`.

## STILL LEAKY — production seams to fix (deferred; owner scoped this pass to opening + seeders)

Each writes/changes aggregate `StockLevel` for a batch-tracked product **without** guaranteeing a
lot. Recommended fix: call `BatchStockService::ensureDefaultBatch()` (or enforce a batch id) at each.

1. **`requires_batch_tracking` toggled false→true on product update**
   `ProductController::update()` (~:689) / product update service. When a product that already has
   `StockLevel` rows is switched to batch-tracked, backfill a default lot per location reconciling
   existing stock (`ensureDefaultBatch` with target = current StockLevel). Today: nothing happens →
   existing stock has no lot.
2. **`StockAdjustmentService::receive()`** (`Inventory/Domain/Services/StockAdjustmentService.php`
   ~:65) — `batchId` is optional; an inbound adjustment for a batch-tracked product can create stock
   with no lot. Either require a batch id or fall back to `ensureDefaultBatch`. (`issue()`/`adjust()`
   counting paths ~:181/:598 also unguarded.)
3. **`StockReservationService::reserve()`** (~:62) — optional `batchId`, no batch-tracked guard.
4. **`InventoryCountingService`** reconciliation (~:84) — adjusts stock via `StockAdjustmentService`
   with no batch-level handling.

## DTO GAP

- **`default_shelf_life_days` is not exposed in `ProductData`** (`Product/Application/DTOs/ProductData.php`).
  The "default expiry period" field exists on the model + FormRequests (`Create/UpdateProductRequest`)
  but does not round-trip through the DTO/typed API. Add it so the product editor can read/write the
  default expiry period that drives `ensureDefaultBatch` expiries.

## COVERAGE LOST WITH THE DELETION (decide whether to port)

- `ParapharmacyMultiBranchSeeder::seedVariantProducts()` was the **only** seeder creating
  variant/sized-goods demo products (orthopedic shoe, compression stocking — one SKU per EU size)
  and its variant-stock + per-size-SKU test coverage. `DemoPharmacySeeder` has no variant products.
  If sized goods are wanted in the demo, port `seedVariantProducts` into `DemoPharmacySeeder`
  (and add a variant batch-reconciliation assertion — `ensureDefaultBatch` already handles `variant_id`).

## KNOWN EDGE / NON-GOAL

- **Opening after a prior goods receipt**: `ensureDefaultBatch` from the opening path uses the opened
  line quantity as the default-lot target, so it composes correctly with GR lots in the normal order
  (opening is enter-once). The default lot is NOT a WAC/cost-bearing receipt; it records *which lot*
  holds opened stock, mirroring how seeded `StockLevel` rows are created movement-free.
- The Tunisia demo's `seedTunisiaPurchaseOrders` / `seedTunisiaTransfers` deliberately operate on
  `requires_batch_tracking = false` products only. With default lots now present, they *could* be
  extended to exercise batch-tracked PO receipt + lot-allocated transfers in the demo.
