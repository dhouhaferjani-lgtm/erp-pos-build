# Adversarial Code Review — Variant-Aware Stock-Transfer Retrofit (G2)

**Branch:** `feat/variant-aware-transfers`
**Base commit:** `df30e653b`
**Reviewed:** 2026-06-09
**Reviewer:** Codex (claude-sonnet-4-6 / codex-companion, two-pass read + test-run evidence)

---

## Verdict: APPROVE-WITH-MINOR-EDITS

**Confidence: 88%**

The backend invariants are well-protected: WAC stays genuinely product-grain, the canonical lock order (advisory → stock_level → product) is preserved, the mixed-mode guard throws `InvalidArgumentException` (→ 422, not 500), and the migration is online-DDL safe. The one module-boundary call (`StockTransferLine::variant()` importing `Catalog\ProductVariant` directly into `Inventory\Domain`) is the same pattern already used for `product()` and `company()` relations on the same model and is acceptable at the read-side projection layer. Two P1-level gaps exist in the frontend batch picker and the `isset` null-passthrough, both of which can be patched before merge without touching the core service layer.

---

## Findings

### P1 — `isset()` silently drops an explicit `null` `variant_id`

**File:** `app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:121`

```php
// current — isset() is false when the key is present but value is null
$variantId = isset($line['variant_id']) ? (string) $line['variant_id'] : null;
```

The comment acknowledges that `isset()` is `false` for a null value, which means it behaves identically to the key being absent — but the comment frames this as intentional. The problem: a caller that explicitly sends `"variant_id": null` to force product-grain semantics on a non-variant product gets `null` correctly. However, a caller that sends `"variant_id": "not-a-uuid"` will pass Laravel's nullable UUID rule (because the field is `nullable`), reach this code as a non-null string, and be cast via `(string)`. That is correct. The real risk is the **reverse**: a non-null value of `false` or `0` from a malformed payload would pass the `nullable` rule (not a UUID, rule fires), but if a future API consumer sends an empty string the UUID validator catches it. The current logic is actually safe for well-typed JSON payloads.

**However**, for defense-in-depth, replace `isset` with `array_key_exists` plus an explicit null check so the intent is unambiguous and future JSON coercion surprises are avoided:

```php
$variantId = (array_key_exists('variant_id', $line) && $line['variant_id'] !== null)
    ? (string) $line['variant_id']
    : null;
```

**Severity rationale:** Low probability of real-world breakage (the UUID validation fires first), but the `isset` comment is misleading and the asymmetry between key-absent and value-null should be explicit rather than inherited.

---

### P1 — Frontend batch picker not variant-scoped (UI-only gap, not a data-integrity breach)

**Files:** `apps/web/src/features/batches/hooks/useBatches.ts`, `apps/web/src/features/batches/api/batches.ts`

The `useProductBatches(productId)` hook used in `CreateStockTransferPage` fetches batches at product grain — it does not accept or forward a `variant_id` parameter. For a batch-tracked, variant-bearing product the picker will show all batches across all variants (e.g. LOT-A for RED-L and LOT-B for BLU-M), and a user selecting the wrong batch will receive a 422 from `assertBatchCanIssue` only at submit time, not at selection time.

This is a **UI UX correctness gap**, not a backend data-integrity breach (the backend correctly rejects cross-variant batch allocation). But it creates a confusing error path.

**Fix:** Pass `variant_id` as a query parameter on the batches endpoint and filter `useBatches` query key and fetch by it. The backend `BatchController@batchStock` endpoint also needs a `variant_id` filter added — a one-line `when($request->input('variant_id'), ...)` addition.

---

### P2 — `down()` on PostgreSQL does not restore the FK before recreating the original unique

**File:** `database/migrations/tenant/2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php:54–67`

In `down()` the PG path drops both partial indexes with `DROP INDEX CONCURRENTLY`, drops the FK constraint, recreates the original `(transfer_id, product_id)` unique, then `dropColumn('variant_id')`. The `CREATE UNIQUE INDEX` (non-`CONCURRENTLY`) on line 57 runs without `withinTransaction=false` protection inside a non-transactional migration, which is fine. But the recreated unique index is created **without** `CONCURRENTLY`, meaning it takes a full table lock on rollback. For rollback paths in development this is acceptable; flag if the table is large in production.

**Fix:** Add `CONCURRENTLY` to the `down()` `CREATE UNIQUE INDEX` statement on the PG branch:

```sql
CREATE UNIQUE INDEX CONCURRENTLY stock_transfer_lines_transfer_product_unique
    ON stock_transfer_lines (transfer_id, product_id);
```

---

### NIT — `(string)` cast of `$line['product_id']` and `$line['variant_id']` inconsistency

**File:** `app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:122,124`

`product_id` is cast with `(string)` unconditionally; `variant_id` is guarded with `isset`. This is fine but slightly asymmetric — a future developer might expect both to follow the same pattern. Minor readability only.

---

### NIT — `StoreStockTransferRequest` does not cross-validate `variant_id` ↔ `product_id` per line

**File:** `app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php:46–55`

The comment correctly explains that cross-line ownership validation is deferred to `assertVariantValidForProduct`. This is architecturally sound (the per-line product context isn't available in a flat validation rule array). Documenting this decision in the request class is good practice — the current comment does so. No change needed; flagging as NIT for completeness.

---

## Invariant Checklist

| # | Invariant | Result | Evidence |
|---|-----------|--------|----------|
| 1 | **WAC stays product-grain** | **PASS** | `WeightedAverageCostService::companyOwnedQuantity` (line 95) queries `StockLevel` by `product_id` only — no `variant_id` filter. The `StockTransferLine` in-transit sum (line 118) also joins by `product_id` only. `recordCostAdjustment` call at `StockTransferService:565` passes `$product` (not a variant). `capitalizeTransferCost` (line 500) is unchanged from base. WAC denominator test at `StockTransferVariantTest::test_complete_increments_destination_variant_and_capitalizes_product_wac` seeds 10+10=20 units (two variants), transfers 4 of variant A with freight 40, asserts `cost_price = '7.000000'` (= 5 + 40/20). A variant-sharded denominator of 10 would give 9. Test passes and directly falsifies the shard hypothesis. |
| 2 | **Canonical lock order preserved** | **PASS** | `moveSourceToInTransit` (line ~405) adds variant filter inside existing `->lockForUpdate()->first()` without reordering the advisory→stock_level→product sequence. `lineProductIds` (line 809) deduplicates by `product_id` only — variant IDs never enter the lock key set. `costLock->acquire` sorted call at line 205 is unchanged. |
| 3 | **No mixed-mode hole** | **PASS** | `assertVariantValidForProduct` (line 859) throws `InvalidArgumentException` for both missing-variant-on-variant-product and foreign-variant. Controller catches `InvalidArgumentException` at line 154 and returns 422 with `INVALID_TRANSFER` code. `test_store_endpoint_rejects_missing_variant_on_variant_product_with_422` confirms the 422 path. `test_initiate_with_foreign_variant_throws_invalid_argument` confirms foreign-variant rejection. No uncaught `DomainException` path reaches a 500. |
| 4 | **Batch FEFO scoped to variant** | **PASS** | `assertAllocationsFollowFefo` (diff line ~672) adds `->when($line->variant_id !== null, fn($q)=>$q->where('variant_id', $line->variant_id), fn($q)=>$q->whereNull('variant_id'))` before ordering by expiry. `assertBatchCanIssue` (diff line ~746) receives `?string $variantId` and applies the same pattern. `test_batch_tracked_variant_transfer_uses_variant_scoped_batches` seeds variant A with a 9-month batch and variant B with a 2-month batch, allocates variant A's later batch — this passes only if FEFO is variant-scoped. |
| 5 | **Migration online-DDL safe** | **PASS with NOTE** | `$withinTransaction = false` at line 22. PG path uses `CREATE UNIQUE INDEX CONCURRENTLY` for both partial indexes (lines 45, 47) and `NOT VALID` for the FK (line 42). `down()` PG path drops with `CONCURRENTLY`. **Note:** `down()` recreates the original `(transfer_id, product_id)` unique without `CONCURRENTLY` (line 57) — takes a table lock on rollback. Acceptable for rollback paths but worth fixing (see P2). |
| 6 | **collectProductIds dedupes by (product, variant)** | **PASS** | `collectProductIds` (diff) now keys by `$line->productId.'|'.($line->variantId ?? '')`, throwing on duplicate `(product, variant)` pairs while allowing the same product under different variants. `lineProductIds` (line 809) still returns product-grain IDs for the lock sorted-acquire. `test_same_product_two_variants_on_one_transfer_is_allowed` verifies two-variant same-product line is accepted; a duplicate call would need an explicit duplicate-pair test (absent, but the invariant is structurally correct). |
| 7 | **StockTransferLine::variant() module boundary** | **ACCEPTABLE** | `StockTransferLine` already imports `App\Modules\Product\Domain\Product` for `product()` and `App\Modules\Company\Domain\Company` for `company()` — both are direct Eloquent model references across module boundaries. The `variant()` relation follows the exact same presentation-layer-read-side pattern. The method docblock explicitly flags it as "presentation eager-load only — no domain logic crosses the module boundary." Rule 6 says cross-module communication should use `Shared/Contracts/` or Events for domain logic; read-side Eloquent eager-loading on a Domain model is a pre-existing and accepted pattern here. **Flag as a NIT** to track for future `Shared\Contracts\ProductVariantReadModel` extraction if module coupling becomes stricter. |
| 8 | **Controller variant_id parsing and request validation** | **PASS with P1 note** | `StoreStockTransferRequest` applies `nullable / string / uuid / Rule::exists(...active)` at line 48–55. The controller correctly casts to string when non-null. The `isset` vs `array_key_exists` gap is flagged as P1 above but does not cause a data-integrity breach in practice due to upstream UUID validation. |

---

## Summary

The variant-aware transfer retrofit is structurally sound. The most critical invariants — WAC product-grain, canonical lock order, and mixed-mode rejection — are all correctly implemented and covered by direct correctness tests. The migration is online-DDL safe for the write path. The two P1 findings (frontend batch picker variant-scoping gap, and `isset` vs `array_key_exists` asymmetry) are isolated and patchable without touching the core service. The P2 finding (missing `CONCURRENTLY` in `down()`) is a rollback-path concern only. No BLOCKERs found.
