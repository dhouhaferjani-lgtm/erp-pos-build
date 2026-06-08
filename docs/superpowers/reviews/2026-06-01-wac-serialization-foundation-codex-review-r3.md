# WAC Serialization Foundation Review - Round 3

Date: 2026-06-01
Round: R3
Reviewer: Codex
Branch: feat/inventory-transfer
Commit reviewed: f3d9a04cbc2c81c6f73fc6c184138607a31a54e6 (includes fix commit ffe4ea9d735f2f3bbdc652d61cc15dd46ab325c3)

## Overall Verdict

REQUEST-CHANGES

## Confidence

High. I read the R2 review, the full fix commit, and the current implementations of `StockTransferService`, `WeightedAverageCostService`, `StockAdjustmentService`, `ProductCostLock`, the stock/transfer/product models, lock-coverage tests, and WAC call sites.

## Round-2 Findings

| Finding | Status | Evidence (file:line) |
|---|---|---|
| R2-1: Multi-product AB-BA deadlock in `complete()`/`cancel()` | RESOLVED | `complete()` collects all line product ids with `$productIds = $this->lineProductIds($transfer);` and calls `$this->costLock->acquire($transfer->tenant_id, $transfer->company_id, $productIds, ...)` before `completeLocked()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:184-187`. `completeLocked()` then performs the per-line `receive()` loop at `StockTransferService.php:199-213`. `cancel()` does the same for in-transit transfers with `$productIds = $this->lineProductIds($transfer);` and `$this->costLock->acquire(...)` before its per-line `receive()` loop at `StockTransferService.php:287-309`. `initiate()` has no `costLock->acquire` in its transaction body at `StockTransferService.php:96-158`; only `issue()` is called from `moveSourceToInTransit()` at `StockTransferService.php:387-394`, and `issue()` locks stock via `$this->lockStockLevel(...)` at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:134-135`. |
| R2-2: `recordCostAdjustment` lock ordering reordered to advisory -> stock_level rows -> product row | RESOLVED | `recordCostAdjustment()` enters `$this->costLock->acquire($tenantId, $companyId, [$product->id], ...)` at `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:558-559`. Inside that callback it locks stock-level rows with `StockLevel::query()...->lockForUpdate()->get()` at `WeightedAverageCostService.php:570-575`. It locks the product row later with `Product::query()...->lockForUpdate()->findOrFail($product->id)` at `WeightedAverageCostService.php:603-607`. |
| R2-3: Allocation residual reconciliation at persisted 4-dp scale | RESOLVED | `capitalizeTransferCost()` builds only cost-bearing allocations at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:455-473`, computes `$lastIndex = count($allocations) - 1` at `StockTransferService.php:476`, formats `$transferCost4dp = CurrencyScale::bcformat($transferCost, self::QTY_SCALE)` at `StockTransferService.php:485-486`, and assigns the last cost-bearing line with `bcsub($transferCost4dp, $runningSumOfOthers4dp, self::QTY_SCALE)` at `StockTransferService.php:494-496`. The same `$allocated4dp` is persisted with `$line->allocated_transfer_cost = $allocated4dp` at `StockTransferService.php:500-501` and passed to `recordCostAdjustment(additionalCost: (float) $allocated4dp, ...)` at `StockTransferService.php:503-518`. Single-line transfers are covered because index `0 === $lastIndex`, so the persisted value is `transferCost4dp`. If the last physical transfer line has a zero computed share, it is skipped at `StockTransferService.php:469-470`, so the residual lands on the last cost-bearing entry in `$allocations`. All-zero transfer quantities are rejected before lines are created by `if (bccomp($line->quantity, '0', self::QTY_SCALE) <= 0)` at `StockTransferService.php:141-144`; all-zero allocation weights fall back to equal-per-line allocation at `StockTransferService.php:463-467`. |
| R2-4: `moveSourceToInTransit` reads `cost_price` unlocked after avoiding product row lock before source `stock_level` | NOT-RESOLVED | The product row is no longer locked: `Product::query()->where(...)->findOrFail($line->product_id)` has no `lockForUpdate()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:358-361`. However, `cost_price` is still read before the source `stock_level` row lock: `$costAtSend = (string) ($product->cost_price ?? '0');` is at `StockTransferService.php:363-364`, while the source row is locked later with `StockLevel::query()...->lockForUpdate()->first()` at `StockTransferService.php:366-371`. This does not satisfy the R3 criterion that `cost_price` be read after the stock-level lock or from the stock-level itself. |

## New Findings

1. Severity: REQUEST-CHANGES

   Description: `GoodsReceiptService::receiveGoods()` still creates a product-row-before-stock-level path into WAC. It locks the `Product` row with `->lockForUpdate()` at `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:88-92`, then calls `$this->wacService->recordPurchase(...)` at `GoodsReceiptService.php:105-113`. `recordPurchase()` acquires the advisory lock at `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:99-100`, then locks the stock-level row at `WeightedAverageCostService.php:105-110`, and only then locks the product row at `WeightedAverageCostService.php:127-131`. Because the outer goods-receipt transaction already holds the product row before `recordPurchase()` tries to acquire advisory and stock-level locks, this path violates the claimed global order `advisory -> stock_level -> product` and can deadlock with a concurrent sale that locks stock-level then product at `WeightedAverageCostService.php:269-285`.

   File:line: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:88-92`

   Concrete fix: remove `->lockForUpdate()` from the product lookup in `GoodsReceiptService::receiveGoods()`. Use an unlocked scoped product load for validation, then let `WeightedAverageCostService::recordPurchase()` acquire advisory, stock-level, and product locks in the canonical order. If the physical/batch validation must use a locked product snapshot, move that validation inside a WAC-side callback after the advisory and stock-level locks, immediately before the product row lock/write.

## Global Lock-Order Analysis

For the listed methods themselves, the canonical order is mostly consistent. `recordPurchase()` acquires `$this->costLock->acquire(...)` at `WeightedAverageCostService.php:99-100`, locks stock-level at `WeightedAverageCostService.php:105-110`, then product at `WeightedAverageCostService.php:127-131`; `recordReturn()` follows the same pattern at `WeightedAverageCostService.php:376-404`; `recordCostAdjustment()` follows advisory, stock-level rows, then product at `WeightedAverageCostService.php:558-607`. `recordSale()` intentionally has no advisory lock and locks stock-level then product at `WeightedAverageCostService.php:269-285`.

In `StockAdjustmentService`, `receive()` acquires a single-product advisory lock and then calls `lockStockLevel()` at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:56-57`; `adjust()` does the same at `StockAdjustmentService.php:448-449`; `transfer()` acquires the per-product advisory lock before source and destination stock locks at `StockAdjustmentService.php:225-263`. Opposite-direction transfers of the same product are serialized by that per-product advisory call before either location row is locked, so the source-then-destination row order cannot AB-BA for A->B versus B->A on the same product. `issue()` remains lock-free by advisory design and locks only the stock row through `lockStockLevel()` at `StockAdjustmentService.php:134-135`.

The new up-front `complete()` and `cancel()` advisory acquire interacts correctly with nested single-product acquires: `ProductCostLock::acquire()` sorts every supplied list before issuing `pg_advisory_xact_lock` at `apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php:42-48`, while the nested calls are single-product `receive()` calls at `StockAdjustmentService.php:56-57` and single-product `recordCostAdjustment()` calls at `WeightedAverageCostService.php:558-559`. I found no caller holding a product row before `recordCostAdjustment()`; the application caller loads the product without `lockForUpdate()` at `StockTransferService.php:458-461`, then calls `recordCostAdjustment()` at `StockTransferService.php:503-518`.

## Summary

The R2 fixes resolved the multi-product advisory ordering in `complete()`/`cancel()`, the `recordCostAdjustment()` row-lock inversion, and the 4-dp transfer-cost allocation residual. The remaining R2-4 criterion is not fully met because `moveSourceToInTransit()` still snapshots `product.cost_price` before the source `stock_level` lock, although it no longer locks the product row. I also found a new caller-side lock-order inversion in `GoodsReceiptService::receiveGoods()`: it locks a product row before entering `recordPurchase()`, bypassing the canonical order that `recordPurchase()` itself now follows. The branch should not be approved until those two ordering issues are corrected or explicitly documented as accepted exceptions.
