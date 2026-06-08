# T2 Variants + WAC Foundation Merge Reconciliation - Codex Adversarial Review

Date: 2026-06-03

Verdict: REQUEST-CHANGES

Confidence: 86%

Merge reviewed: `68842add6` (`6cf16cc81` T2 product-variants + `15f4fe08b` dev WAC serialization foundation)

Canonical lock order preserved? YES for the reviewed merge-sensitive WAC/stock recompute seams: advisory -> stock_level FOR UPDATE -> product FOR UPDATE. `recordPurchase()` takes `ProductCostLock` with `[$product->id]`, locks the variant target row, locks all product/location sibling `stock_levels`, then locks the product row. The repeated target row lock is safe on PostgreSQL because row locks are re-entrant inside the same transaction. A concurrent lock-free `recordSale()` on a sibling can block the purchase on that sibling row, but does not form an AB-BA cycle because purchase has not yet locked the product row.

§6.7 variant scoping fully preserved? NO. The `recordPurchase()` merge path correctly keeps WAC product-grain while applying physical quantity and movement rows to the variant row, but variant scoping is still dropped on return/release paths below. WAC is not being promoted to variant-grain; the issue is that physical stock rows and V2 event payloads lose the variant dimension.

## Findings

1. `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:417` - P1 - `recordReturn()` is still product-level only and cannot return a variant line to the same variant row.

Evidence:

```php
public function recordReturn(
    Product $product,
    Location $location,
    float $quantity,
    float $originalCost,
    ?string $reference = null,
    ?string $referenceType = null,
    ?string $referenceId = null
): StockMovement {
```

The stock lookup at `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:429` filters only `product_id`, `location_id`, `tenant_id`, and `company_id`, with no `variant_id` predicate, and the movement created at `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:478` does not persist `variant_id`.

Impact: a refund/return for a variant-bearing product cannot address the variant row. It will update a product-level/null-variant row if one exists, or create one if none exists, violating the no-mixed-mode invariant and the T2 acceptance criterion that a credit/refund against a variant line returns stock to the same variant row. It also computes the WAC blend from a single physical row (`$stockLevel->quantity`) instead of the product-grain variant sibling sum used by `recordPurchase()`, so the merge leaves an asymmetric §6.7 cost path.

2. `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:509` - P1 - `releaseReservation()` has no `?string $variantId` parameter and always releases the product-level row.

Evidence:

```php
public function releaseReservation(
    string $productId,
    string $locationId,
    string $quantity,
    string $reference,
    ?string $expectedCompanyId = null,
): void {
```

The call at `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:517` invokes `lockStockLevel($productId, $locationId, ...)` without a variant id, which takes the `whereNull('variant_id')` branch at `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:707`. The V2 event then hard-codes `variantId: null` at `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:560`.

Impact: after `reserve(..., variantId: $variant->id)` correctly increments the variant row, there is no matching release API that can decrement that same variant row. On a variant-bearing product, release either throws because the null-variant row does not exist or touches/creates the wrong product-level state in legacy/mixed data. Downstream V2 consumers also receive a null variant on `ReservationReleasedV2`, breaking the dual-dispatch contract for variant-aware reservations.

## Confirmations

- `GoodsReceiptService::receiveGoods()` acquires all PO product advisory locks up front at `app/Modules/Inventory/Application/Services/GoodsReceiptService.php:78` before calling the per-line helper, and `processReceiptLines()` loads products unlocked at `app/Modules/Inventory/Application/Services/GoodsReceiptService.php:141` before passing `$line->variant_id` into `recordPurchase()` at `app/Modules/Inventory/Application/Services/GoodsReceiptService.php:170`.
- `recordPurchase()` uses a product-grain advisory key, never a variant key, at `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:103`.
- `recordPurchase()` denominator is product+location+tenant+company, not variant-sharded and not company-wide, at `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:156` through `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:161`.
- `issue()` remains seam-free and row-locks through `lockStockLevel()` at `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:173`, matching the architecture guard's pure-decrement contract.
- `transfer()` acquires one product advisory lock, then row-locks source and destination with the same `variantId` at `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:288`, `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:290`, and `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:325`. Quantity conservation holds: source subtracts at `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:307`, destination adds at `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:328`.

---

## Reconciliation author's response (2026-06-03)

Both P1 findings are **real product gaps** but **pre-existing T2 design state, not regressions introduced by this merge**, and out of scope for the reconciliation. Verified against the codebase:

**Finding 1 — `recordReturn()` product-level.** On the T2 tip being merged (`6cf16cc81`), `recordReturn()` already had **no** `?string $variantId` parameter — T2 variant-scoped `recordPurchase()` (goods-receipt path, Phases 1-2) but never the return path. This merge took dev's bcmath `recordReturn()` verbatim precisely because the T2 side added no variant logic there, so there was nothing to graft. Per `project_t2_variants_impl` memory, T2 IMPL **completed Phases 1-2 and STOPPED at an owner checkpoint before Phases 3-6**; return-flow variant scoping is later-phase T2 work. `recordReturn()` is called by `ReturnNoteService::confirm()` (dev's foundation code), which is likewise product-level on both sides — the variant-return gap exists identically pre- and post-merge. The merge does not make it worse, and adding it now is net-new feature work the reconciliation task explicitly defers to the next session.

**Finding 2 — `releaseReservation()` no `variantId`.** Same status: T2 tip (`6cf16cc81`) already defined `releaseReservation()` without a `variantId` param, with an explicit in-code comment acknowledging it locates the row by product+location. Additionally, **`releaseReservation()` has zero production callers** (`grep -rn 'releaseReservation(' app/` returns only the definition) — the variant-release gap is currently unreachable (YAGNI). The `reserve()`/`releaseReservation()` variant asymmetry is a known T2 Phase 3+ item.

**Disposition:** The reconciliation preserved (a) the foundation's canonical lock order + advisory seam and (b) **all** variant scoping that T2 actually had at Phases 1-2 — confirmed by every item in the "Confirmations" section above. The two P1s are **deferred to T2 Phase 3+ (return-flow + reservation-release variant scoping)** and tracked here for the owner. They are NOT implemented in this merge because doing so is feature work beyond the resolve/verify/commit/review scope, and the task instructs not to build variant features beyond the reconciliation.

**Verdict for the reconciliation specifically: APPROVE** — no new lock-order inversion, no deadlock, no §6.7 violation, no dropped Phase 1-2 variant scoping. The REQUEST-CHANGES findings are accepted as valid pre-existing gaps and deferred, not actioned in this commit.
