# Gate 2 Verdict — Wave 2 inventory visibility

Reviewer: inventory-costing-reviewer (adversarial, code-grounded)
Branch: feat/multi-location @ 4c29e831b
Scope: `git diff origin/dev...HEAD -- apps/api/app/Modules/Inventory apps/api/database/migrations/tenant apps/web/src/features/{inventory,stock-transfers,purchases} apps/web/src/components/organisms/ProductLocationMatrix`

## Summary of verification (what actually holds in code)

- **WAC engine untouched.** No new WAC arithmetic, no float rebase, no scale downgrade in the multiply/divide chain. Goods-receipt increments still route through `WeightedAverageCostService::recordPurchase()` (`GoodsReceiptService.php:531,578`).
- **No float on money/quantity anywhere in the diff.** Backend uses `bcadd`/`bcsub`/`bccomp` + `QuantityScale::round` throughout (`StockMatrixQueryService.php:141-150,315,379`, `StockRebalanceQueryService.php:106,113,121`, `StockThresholdService.php:27-28`). FE uses `bccomp`/`bcsub` from `@/lib/decimal` and `formatQuantity`; grep of added FE lines for `parseFloat|Number(|(float)|toFixed` returns nothing; `parseFloat` is gone from `ProductMovementsTab.tsx`.
- **Rollup is correct-by-construction, no double-count.** Parent cell = `bcadd` over ALL stock rows for the product×location once (`StockMatrixQueryService.php:136-151`); `is_variant_parent` iff ≥1 non-null variant row (:131-133); variant leaves + optional "(base)" leaf emitted (:194-221); base leaf only when nonzero (:207-211). On-hand/available invariant `sum(children)===parent` holds.
- **Receipt-destination reconciliation semantics (the owed A-then-B test) hold in the implementation:** movements post to `$location` and are created per-receipt (immutable history) via `recordPurchase(location: $location…)` (`GoodsReceiptService.php:533,578-587`); the PO line follows the LATEST destination `$line->location_id = $location->id` (:635); the unreceived remainder projects from `document_lines.location_id` with `quantity > COALESCE(quantity_received,0)` (`StockMatrixQueryService.php:416-419`). So a later B receipt re-homes only the remainder, leaving receipt-1's A movement intact.
- **`post()` signature reorder is safe.** `post(receipt, actorId, ?destinationLocationId, failClosedGrir=false)` inserts a new 3rd param, but no caller ever passed `failClosedGrir` positionally (all call sites use 2 args; internal `receiveGoods` passes destination) — verified by grep. No behavioral break.
- **Movement/threshold filter is fail-closed, always-resolve-always-apply** (`StockMovementController.php:57-70`, `StockLevelController.php:23-57`, `StockMatrixController.php:23-38`): empty request → resolver's full allowed set, still `whereIn`'d. Restricted no-param test proves it (`StockMovementLocationFilterTest.php`).
- **Threshold write path** keeps variant grain (`whereNull('variant_id')` vs `where`, `StockThresholdService.php:25`), FLOOR-rounds to scale 4, creates a zero-qty row when none exists, authorizes `location_id` via the §1 `ValidLocationAccess` rule + `ScopedExists` (`UpdateStockThresholdsRequest.php:34`). Regex ceiling `/^\d+(\.\d{1,4})?$/` and min≤max FLOOR-compare present.
- **Migration** is idempotent + reversible with FK `nullOnDelete` (`2026_07_16_120000_add_location_id_to_goods_receipts.php`).
- Required tests `StockRebalanceEndpointTest` and `StockMovementLocationFilterTest` exist and assert real behavior (real models, `RefreshDatabase`, `RolesAndPermissionsSeeder`, real HTTP endpoints, string quantities) — no `assertTrue(true)`, no mocking of the unit under test.

## Findings (by severity)

### Critical
None.

### Important
- **[Important] apps/api/phpunit.xml:41-42 (+ StockRebalanceEndpointTest.php:27-28, StockMovementLocationFilterTest.php, StockMatrixEndpointTest.php, StockThresholdTest.php)** — The gate brief (line 14) and plan (constraint 9, I8) REQUIRE the aggregate/pivot tests to run on PostgreSQL, precisely because SQLite masks PG `SUM`/aggregate/`ilike` divergence. As committed, `DB_CONNECTION=sqlite` / `:memory:` is the only test connection; these classes use plain `RefreshDatabase` with NO pgsql override, there is no `.env.testing`, and no `apps/api/.github` workflow forces pgsql. They therefore exercise the incoming `SUM(...)`/`COALESCE` aggregates (`StockMatrixQueryService.php:401,419`) and rebalance classification under SQLite — the exact I8 hole the plan forbids. The tests pass, but not against the engine the contract pins. Why it matters: a PG-only aggregate/precision regression (e.g. `SUM` on numeric strings) ships green. Fix: pin these classes to pgsql (a `DatabaseTestCase` with `protected $connection='pgsql'`, or a CI lane running them with `DB_CONNECTION=pgsql`) and demonstrate they pass there.

### Minor
- **[Minor] apps/api/.../GoodsReceiptDestinationTest.php (absent) — KNOWN OWED TEST (tracked; plan lines 485-487 unchecked).** Receiving-destination coverage currently lives in GoodsReceiptTest; the dedicated A-then-B repeated-partial-receipt PG test does not exist. Verified the semantics it would pin DO hold in code (see summary: immutable per-receipt movement at A, `document_lines.location_id` follows latest destination at :635, remainder follows the line via :416-419) — so the behavior is correct but UNPINNED against regression. Fix: land the PG A-then-B test.
- **[Minor] StockRebalanceQueryService.php:88-89** — Severity sort uses `-available` of the worst cell rather than deficit depth (`min − available`); a below-min-but-positive product gets negative severity and sorts below out-of-stock. Display ordering only; classification is correct. Fix: sort by `min_quantity − available` magnitude.
- **[Minor] StockThresholdService.php:26-38** — Read-then-insert without lock/upsert; concurrent first-time writes to the same grain race and the second insert hits the partial unique index (`stock_levels_non_variant`/`_with_variant`) → 500, no corruption. Fix: `updateOrInsert` / `ON CONFLICT` or a row lock.
- **[Minor] StockMatrixQueryService.php:207-221,265-271** — The `incoming` column can break `sum(children)===parent` when the base row is zero (no "(base)" leaf) but PO/base incoming exists, or a variant has in-transit incoming with no on-hand row (rollup counts it, no child leaf). On-hand/available invariant intact; incoming is advisory and the edge is acknowledged in-code (:265-266).
- **[Minor] GoodsReceiptService.php:919-925 / StockThresholdService.php:29-35** — `resolveDestinationLocation` scopes `company_id`+`is_active` but not `tenant_id`; threshold upsert doesn't verify the product/variant belong to the company. Tenant-isolated under db-per-tenant, low impact; add defense-in-depth scoping.

## What to fix before merge
Pin the two named aggregate tests (and the matrix/threshold tests) to PostgreSQL so the I8/gate "pass on PostgreSQL" precondition is verifiably met; then land the owed A-then-B `GoodsReceiptDestinationTest`.

VERDICT: REJECT
