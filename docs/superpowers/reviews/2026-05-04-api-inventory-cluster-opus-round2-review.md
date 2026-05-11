# api.inventory cluster — Opus round-2 review

Verdict: APPROVE
Commit reviewed: c521b051

Reviewer: Claude (Opus 4.7, 1M context) — adversarial first-layer
Branch: feat/tenant-isolation-sweep-execution
Cluster owner: Codex
Round-1 verdicts: Opus APPROVE (5 non-blocking findings); Codex REQUEST-CHANGES (2 new findings — HIGH read leak + MEDIUM regression-coverage gap)

---

## Scope of round-2 commit

`git diff --name-only c521b051^..c521b051` returns exactly:

- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockLevelController.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php`
- `apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php`

Confirmed no Document / Service / Accounting / Voucher / POS files touched. `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` is empty.

## Round-1 finding closure status

### Codex round-1 HIGH Finding 1 — same-tenant cross-company stock-level/movement read leak — CLOSED

`StockLevelController::index/show` and `StockMovementController::index` now scope by both `tenant_id` AND `company_id`:

- `StockLevelController.php:30-31` — `->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)`
- `StockLevelController.php:64-65` — same shape on `show`
- `StockMovementController.php:34-35` — same shape on `index`

Behavioural pin tests `test_stock_levels_index_excludes_same_tenant_cross_company_rows` and `test_stock_movements_index_excludes_same_tenant_cross_company_rows` create a foreign company under the same tenant and verify its rows do not appear in the API response. Structural pin `test_stock_levels_index_query_includes_tenant_and_company_predicates` asserts both substrings appear in the SQL. Both predicates are required.

### Codex round-1 MEDIUM Finding 2 — WAC service-tier scope not pinned by regression tests — CLOSED

New test `test_wac_record_purchase_locks_product_with_tenant_and_company_predicates` enables `DB::enableQueryLog()`, calls `WeightedAverageCostService::recordPurchase`, and asserts the products lock query contains both `tenant_id` AND `company_id` AND `"id" =` substrings.

**Test honesty independently verified twice:**

1. Reverted `WeightedAverageCostService::recordPurchase` lines 83-87 to bare `Product::lockForUpdate()->findOrFail($product->id)`. Test failed: `Failed asserting that null is not null` (the products query in the captured log is `select * from "products" where "products"."id" = ? and "products"."deleted_at" is null limit 1` — neither predicate present).
2. Partial reversion (kept `tenant_id`, removed `company_id`). Test still failed: query is `select * from "products" where "tenant_id" = ? and "products"."id" = ? ...` — `company_id` substring missing.

Restored the full scope; full 17/17 suite green again. The structural pin is honest about both individual predicates, not just their union.

### Opus Finding 1 (CountingItemController bare exists) — UNCHANGED

Round-1 commit message marks this as deferred to manual stub follow-up. Acceptable; structurally protected by `counting_id` filter at the service layer.

### Opus Finding 2 (FraudTriggeredCountingService User::first()) — UNCHANGED

System command path; deferred to manual stub. Acceptable.

### Opus Finding 3 (createDraft tenant_id not set) — UNCHANGED

Hygiene; reads remain company-coherent. Deferred to manual stub. Acceptable.

### Opus Findings 4-5 — INFORMATIONAL — UNCHANGED

Both noted as informational; no action required.

## New round-2 findings

### Finding 1 — INFORMATIONAL (defense-in-depth) — StockLevel locks in WAC service still scoped by tenant_id only (not company_id)

`WeightedAverageCostService.php` lines 62-66, 211-215, 309-313 lock stock_levels with the tuple `(product_id, location_id, tenant_id)` only — no `company_id` predicate. Same shape that motivated Codex round-1 Finding 1 against the controllers.

Mitigation in place that prevents this from being exploitable today:
- Migration `2025_11_30_110000_create_inventory_tables.php` line 27 declares `unique(['tenant_id', 'product_id', 'location_id'])`. The (product_id, location_id, tenant_id) tuple matches at most one row, so even if a foreign-company productId were forged, no second row would be picked up; the row that is found has its own `company_id` already pinned by FK.
- All callers of `recordPurchase` / `recordSale` / `recordReturn` first re-scope `Product::query()` by both `tenant_id` and `company_id` (lines 83-87, 219-223, 329-333) before any side effects, and `getOrCreateStockLevel` in `StockAdjustmentService` (lines 472-475) verifies `Product.company_id == Location.company_id`.

So this is a stylistic / defense-in-depth gap, not an exploitable leak, and aligns with the round-1 committed deferrals. Recording for the manual stub backlog.

### Finding 2 — INFORMATIONAL — InventoryCountingService::getStockLevelsForScope uses `forCompany($companyId)` only (no tenant_id)

`InventoryCountingService.php:114-115` uses `StockLevel::query()->forCompany($companyId)`. The `scopeForCompany` (`StockLevel.php:168-171`) filters by `company_id` only. Since each `company_id` belongs to exactly one tenant by FK, this is functionally equivalent to a (tenant_id, company_id) filter, and not exploitable. Same defense-in-depth shape; record for manual stub backlog.

### Finding 3 — INFORMATIONAL — StockReservationController scopes by company_id only (acceptable)

Verified `stock_reservations` table has no `tenant_id` column (migration `2025_12_24_133728_create_stock_reservations_table.php`; no later migration adds tenant_id). All four StockReservation queries scope by `company_id` only, which is canonical for this table. NOT a finding — recorded for completeness so a future reviewer doesn't re-flag it.

### Finding 4 — INFORMATIONAL — Unused legacy `scopeForTenant` on StockLevel + StockMovement domain models

`StockLevel::scopeForTenant` and `StockMovement::scopeForTenant` exist but `grep -rn "forTenant("` in the Inventory module returns no callers. Not a leak vector. Could be deleted in a future hygiene pass.

## Audit exhaustiveness

Hostile-grep matrix:

| Probe | Result |
|---|---|
| `->where('tenant_id'` only (no adjacent `->where('company_id'`) in Inventory non-test code | All instances accounted for: WAC product re-locks have both predicates on adjacent lines; WAC stock_level locks intentionally tuple-scoped (Finding 1); domain model legacy scopes (Finding 4); InventoryCountingController:549-550 has both; StockLevelController + StockMovementController both have both predicates after this commit. |
| `(StockLevel|StockMovement|StockReservation|InventoryCounting|InventoryCountingItem)::query()` outside tests | 10 callsites probed; all either properly scoped (StockLevel controllers post-fix, StockReservation by company_id only — table has no tenant_id, StockAdjustmentService::lockStockLevel by (product_id, location_id) which is unique-keyed) or covered by Finding 1/2 INFORMATIONAL deferrals. |
| `(StockLevel|StockMovement|StockReservation|InventoryCounting)::(find|findOrFail|first|firstOrFail)(` outside tests | No matches. |
| Validators with `'uuid'` and no ScopedExists in Inventory controllers | StockMovementController receive/issue/transfer/adjust accept bare `uuid` for product_id/location_id. Already documented in commit message as INFORMATIONAL with defense-in-depth via `LocationContext::validateLocationAccess` + `StockAdjustmentService::getOrCreateStockLevel` Product-by-Location.company_id rescope. Acceptable. |

POS surface diff `dev..HEAD` empty. Voucher surface diff empty.

## Test honesty results

| Test | Honesty check |
|---|---|
| `test_stock_levels_index_excludes_same_tenant_cross_company_rows` | Behavioural — would fail if controller reverted to `tenant_id` only. |
| `test_stock_movements_index_excludes_same_tenant_cross_company_rows` | Behavioural — would fail if controller reverted to `tenant_id` only. |
| `test_stock_levels_index_query_includes_tenant_and_company_predicates` | Structural — asserts both substrings in the SQL. Honest. |
| `test_wac_record_purchase_locks_product_with_tenant_and_company_predicates` | Structural — independently verified twice: (a) full revert to bare `Product::lockForUpdate()` fails the assertion; (b) partial revert (only `company_id` removed) also fails because the assertion requires both substrings. Honest. |

`vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php` — 17/17 OK (49 assertions).

## Required gates

| Gate | Result |
|---|---|
| `vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php` | OK (17 tests, 49 assertions) |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Inventory tests/Feature/Inventory/InventoryTenantIsolationTest.php` | `[OK] No errors` |
| `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` | `verified 1093 event(s) across 268 callsite(s); 0 problem(s).` |
| `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` | empty |

## Confidence

HIGH. The two Codex round-1 findings are fully closed with honest, surgical fixes:

- HIGH read leak — closed at the controller layer with both behavioural and structural test pins.
- MEDIUM coverage gap — closed with a query-log structural pin that is honest about both individual predicates (verified by full and partial reversions).

The 4 new findings I recorded are all INFORMATIONAL and align with the deferral pattern already stated in the commit message. None blocks. No exploitable leak remains in the round-2 surfaces.

Verdict: **APPROVE**.
