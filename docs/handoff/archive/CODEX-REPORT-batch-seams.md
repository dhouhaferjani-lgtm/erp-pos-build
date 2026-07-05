# CODEX Report — Default-Batch Invariant Seams

Date: 2026-07-02
Branch: `feat/batch-invariant-seams`

## Behavior Decisions

1. `ProductController::update()`
   - When `requires_batch_tracking` changes from `false` to `true`, every positive `StockLevel` row for the product is reconciled into the product's `DEFAULT` lot at that row's location.
   - Existing zero/non-positive stock rows do not mint lots.
   - Variant-scoped stock rows pass `variant_id` through to `BatchStockService::ensureDefaultBatch()`.

2. `StockAdjustmentService::receive()` / upward `adjust()`
   - For batch-tracked products with no explicit `batchId`, implicit positive stock is reconciled through `BatchStockService::ensureDefaultBatch()`.
   - If only lot-less aggregate stock already exists, an implicit receipt tops the `DEFAULT` lot up to the new aggregate stock level rather than only the received delta.
   - Explicit `batchId` receipts keep the existing batch-movement path.
   - Downward adjustments are not reduced at batch level here because `ensureDefaultBatch()` is intentionally no-reduce/idempotent.

3. `StockReservationService::reserve()`
   - For batch-tracked products without an explicit `batchId`, reservation first reconciles the aggregate on-hand quantity into the `DEFAULT` lot, then reserves against that batch.
   - The reservation row stores the resolved `batch_id`.
   - Batch reservations update `inventory_batch_stock.reserved_quantity`; aggregate `stock_levels.reserved` is left unchanged for this batch-specific path.

4. `InventoryCountingService` positive reconciliation deltas
   - The counting completion listener already applies deltas through `StockAdjustmentService::adjust()`.
   - Positive counting deltas for batch-tracked products now reconcile into the `DEFAULT` lot through the new upward-adjust behavior.

## Tests Added

- `tests/Feature/Product/UpdateProductTest.php`
  - `test_toggling_batch_tracking_on_backfills_default_lot_for_existing_stocked_locations`
- `tests/Feature/Inventory/StockAdjustmentDefaultBatchTest.php`
  - `test_receive_without_explicit_batch_routes_batch_tracked_stock_into_default_lot`
  - `test_receive_without_explicit_batch_tops_up_default_lot_when_only_lotless_stock_already_exists`
  - `test_adjust_upward_without_explicit_batch_routes_positive_delta_into_default_lot`
- `tests/Feature/Inventory/StockReservationDefaultBatchTest.php`
  - `test_reserve_without_explicit_batch_on_batch_tracked_product_uses_default_lot`
- `tests/Feature/Inventory/InventoryCountingDefaultBatchTest.php`
  - `test_counting_positive_delta_for_batch_tracked_product_reconciles_default_lot`

## Verification

RED runs failed before production changes with missing `DEFAULT` lots on all four seam paths.

GREEN runs:

- `./vendor/bin/phpunit tests/Feature/Product/UpdateProductTest.php`
- `./vendor/bin/phpunit tests/Feature/Inventory/StockAdjustmentDefaultBatchTest.php tests/Feature/Inventory/InventoryCountingDefaultBatchTest.php`
- `./vendor/bin/phpunit tests/Feature/Inventory/StockReservationDefaultBatchTest.php tests/Feature/Marketplace/StockReservationTest.php`
- `./vendor/bin/phpunit tests/Feature/Inventory/StockAdjustmentEventsTest.php tests/Feature/Inventory/StockAdjustmentServiceVariantTest.php`
- `./vendor/bin/pint app/Modules/Product/Presentation/Controllers/ProductController.php app/Modules/Inventory/Domain/Services/StockAdjustmentService.php app/Modules/Inventory/Application/Services/StockReservationService.php tests/Feature/Product/UpdateProductTest.php tests/Feature/Inventory/StockAdjustmentDefaultBatchTest.php tests/Feature/Inventory/StockReservationDefaultBatchTest.php tests/Feature/Inventory/InventoryCountingDefaultBatchTest.php`

## Run Blockers

None. Targeted tests reached the database and passed. Full suite was intentionally not run.
