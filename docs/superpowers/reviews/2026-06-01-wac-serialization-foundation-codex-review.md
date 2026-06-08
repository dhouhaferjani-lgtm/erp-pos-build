# WAC Serialization Foundation Review

Date: 2026-06-01
Branch: feat/inventory-transfer
Diff range: dbd193a4c..1425be555
Reviewer: Codex adversarial review

VERDICT: REQUEST-CHANGES

CONFIDENCE: 87% - I read the full changed PHP classes and widened the search to backend stock/WAC writers; the remaining uncertainty is around operational reachability of older import/maintenance paths.

## FINDINGS

1. Severity: P1
   File: /Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:176
   Description: `complete()` locks the `products` row before the per-product advisory lock is acquired by `StockAdjustmentService::receive()` at line 182. The WAC recompute paths do the opposite: `WeightedAverageCostService::recordPurchase()` acquires `ProductCostLock` at `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:100`, then locks the product at line 127; `recordCostAdjustment()` does the same at lines 559 and 561. This creates a confirmed lock-order inversion: transfer completion can hold the product row and wait for `ProductCostLock`, while a purchase/cost adjustment holds `ProductCostLock` and waits for the product row. The same pattern exists during transfer initiation: `moveSourceToInTransit()` locks the product at `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:320` before calling the stock issue path at line 350, which then takes stock-level row locks in the opposite order used by purchase (`stock_level` before product).
   Concrete fix: make transfer flows follow the same global order as WAC recomputes. Either remove the unnecessary product `lockForUpdate()` in transfer orchestration and let the called service own locking, or acquire the product advisory lock before any product row lock. For example, inject `ProductCostLock`, collect line product IDs, and wrap the completion body with `costLock->acquire($transfer->tenant_id, $transfer->company_id, $productIds, ...)` before any `Product::lockForUpdate()` call; then avoid reacquiring product locks ahead of stock-level locks.

2. Severity: P1
   File: /Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:518
   Description: `getOrCreateStockLevel()` uses `StockLevel::firstOrCreate()` and returns the model without `FOR UPDATE`. `receive()` reads `quantity_before` from that unlocked model at line 60 and writes `quantity_after` at line 63; `adjust()` does the same at lines 451-455; `transfer()` increments the destination from the unlocked model at lines 262-266. Pure decrements intentionally do not take `ProductCostLock`, so they must serialize via stock-level row locks. With the current code, an increment can read quantity 10 without a row lock, a concurrent issue can lock and update the row to 5, and the increment can later save its precomputed 15, losing the issue.
   Concrete fix: after creating or finding the stock level, always re-read it with `lockForUpdate()` before returning it. Do not rely on the advisory lock because pure decrements intentionally bypass it.

   ```php
   StockLevel::firstOrCreate([...], [...]);

   return StockLevel::query()
       ->where('product_id', $productId)
       ->where('location_id', $locationId)
       ->where('company_id', $expectedCompanyId)
       ->lockForUpdate()
       ->firstOrFail();
   ```

3. Severity: P2
   File: /Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:167
   Description: `complete()` opens the outer transaction without `attempts: 3`, but its row-creating and WAC-mutating work happens inside that transaction through `StockAdjustmentService::receive()` at line 182 and `WeightedAverageCostService::recordCostAdjustment()` at line 431. Laravel does not safely retry a nested deadlock inside an already-open transaction; the inner transaction throws outward and the outer transaction has only one attempt. This leaves the transfer completion path outside the deadlock-retry guarantee requested for WAC/stock mutators. `initiate()` and `cancel()` have the same outer `DB::transaction(...)` omission at lines 94 and 247 while calling stock mutators inside.
   Concrete fix: add `attempts: 3` to the outer stock-transfer lifecycle transactions and keep the closure retry-safe by generating only rollback-safe DB state inside it. Example:

   ```php
   return DB::transaction(function () use ($transferId, $userId): StockTransfer {
       // existing complete body
   }, attempts: 3);
   ```

4. Severity: P2
   File: /Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:418
   Description: transfer-cost allocation divides each line weight by total weight at `ALLOCATION_SCALE` and multiplies each rounded ratio independently. The line allocations are not reconciled back to `transferCost`, so residual cost is lost or gained. For example, an equal three-line transfer cost of `100.000000` allocates `33.333300` per line at scale 6, capitalizing only `99.999900`. This violates the invariant that the sum of capitalized WAC adjustments should equal the transfer cost.
   Concrete fix: allocate all but the final eligible line normally, track `allocatedSoFar`, and assign the final line `bcsub($transferCost, $allocatedSoFar, $working)`. Persist the same reconciled amount used for `recordCostAdjustment()`.

5. Severity: P2
   File: /Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/apps/api/app/Modules/Inventory/Application/Services/InventoryService.php:29
   Description: `InventoryService::upsertStockLevel()` still creates or overwrites `stock_levels` rows directly through `StockLevel::updateOrCreate()` with no `ProductCostLock`, no row lock, and no transaction. It is reachable from stock-level imports at `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer/apps/api/app/Modules/Import/Services/ImportService.php:389`. Even though the HTTP import controller blocks `ImportType::StockLevels`, this application service remains a backend row-creating writer that bypasses the serialization foundation and can recreate the phantom-row race if invoked by queue/import code or another module through `InventoryServiceInterface`.
   Concrete fix: either delete/disable this stock-level upsert path in favor of `InventoryOpeningService`/`StockAdjustmentService`, or inject `ProductCostLock` and wrap the upsert in `DB::transaction(..., attempts: 3)` using the exact `(tenant_id, company_id, product_id)` key before locking/upserting the row.

## SUMMARY

The main WAC service changes are directionally correct: the recompute methods acquire a transaction-scoped advisory lock inside `DB::transaction(..., attempts: 3)`, `recordCostAdjustment()` scopes its denominator by tenant/company/product and includes in-transit quantity, and the transfer-cost regression assertion expecting `5.500000` is arithmetically correct because `60 / 120 = 0.5` and `5.0 + 0.5 = 5.5`. The implementation is not ready to approve because serialization still depends on stock-level row locks that `StockAdjustmentService::getOrCreateStockLevel()` does not take, transfer orchestration introduces lock-order inversions and lacks outer retry attempts, allocation can drop residual transfer cost, and an older stock-level upsert service bypasses the new lock seam entirely.
