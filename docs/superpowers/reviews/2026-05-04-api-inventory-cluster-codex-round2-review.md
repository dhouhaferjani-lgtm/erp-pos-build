# api.inventory cluster - Codex round-2 adversarial review

Verdict: REQUEST-CHANGES
Commit reviewed: c521b051

Reviewer: Codex headless second-layer adversarial reviewer
Branch: feat/tenant-isolation-sweep-execution
Cluster: api.inventory

## Scope Verification

`git show --stat --name-only --oneline c521b051` shows exactly three files:

- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockLevelController.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php`
- `apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php`

No Document, Service, Catalog, Accounting, POS, Voucher, or other module files were touched by the round-2 commit.

## Codex Round-1 Closure Verification

### HIGH 1 - StockLevel/StockMovement same-tenant cross-company read leak

Status: CLOSED for the three reported controller reads.

Evidence:

- `StockLevelController::index` now has both `->where('tenant_id', $company->tenant_id)` and `->where('company_id', $company->id)` at `StockLevelController.php:29-31`.
- `StockLevelController::show` has the same paired predicates at `StockLevelController.php:63-65`.
- `StockMovementController::index` has the same paired predicates at `StockMovementController.php:33-35`.
- `test_stock_levels_index_excludes_same_tenant_cross_company_rows` creates a second company under the same tenant, creates its stock level, and asserts that row is absent from `/api/v1/stock-levels`.
- `test_stock_movements_index_excludes_same_tenant_cross_company_rows` creates a second company under the same tenant, creates its stock movement, and asserts that row is absent from `/api/v1/stock-movements`.
- `test_stock_levels_index_query_includes_tenant_and_company_predicates` captures the stock-level query and asserts both `"tenant_id"` and `"company_id"` substrings.

Test honesty: temporarily removing the `company_id` predicate from `StockMovementController::index` made `test_stock_movements_index_excludes_same_tenant_cross_company_rows` fail with the foreign movement ID present in the response. The predicate was restored and `git diff` for that file returned empty.

### MEDIUM 2 - WAC service-tier scope not pinned by tests

Status: CLOSED for `recordPurchase`.

Evidence:

- `test_wac_record_purchase_locks_product_with_tenant_and_company_predicates` exists.
- The test resolves `WeightedAverageCostService`, enables `DB::enableQueryLog()`, runs a real `recordPurchase(...)`, inspects `DB::getQueryLog()`, and requires the products lock query to include `tenant_id`, `company_id`, and `"id" =`.

Test honesty: temporarily replacing `WeightedAverageCostService.php:83-87` with `Product::lockForUpdate()->findOrFail($product->id)` made the WAC test fail with `Failed asserting that null is not null`; the captured product query was only `where "products"."id" = ?`. The scoped query was restored and `git diff` for the file returned empty.

## Opus Round-2 Claim Verification

1. WAC `StockLevel` locks tuple-scoped by `(product_id, location_id, tenant_id)` only: CONFIRMED. `WeightedAverageCostService.php:62-65`, `211-214`, and `309-312` do not include `company_id`. I agree this is informational for the current WAC call path because the product is re-locked by tenant and company and `stock_levels` has a unique `(tenant_id, product_id, location_id)` key.

2. `InventoryCountingService::getStockLevelsForScope` uses `forCompany($companyId)` only: CONFIRMED. `InventoryCountingService.php:114-116` scopes stock levels by company through `StockLevel::scopeForCompany`; since `company_id` implies tenant through the company FK, I do not treat this as exploitable.

3. `StockReservationController` scopes by `company_id` only: CONFIRMED and acceptable for that table. `StockReservationController.php:49-50`, `126-128`, `225-227`, and `264-267` scope by `company_id`; migration `2025_12_24_133728_create_stock_reservations_table.php:14-56` defines `stock_reservations` with `company_id` and no `tenant_id`.

4. Unused legacy `scopeForTenant` on `StockLevel` and `StockMovement`: CONFIRMED. `StockLevel.php:123-126` and `StockMovement.php:142-145` define the scopes; `rg "forTenant\\(" apps/api/app/Modules/Inventory --glob '*.php'` returned no callers. Hygiene only.

## New Round-2 Findings

1. HIGH - `StockReservationService::reserveForWorkOrder()` can reserve stock for a foreign company through the Workshop approval path.

   `StockReservationService::reserveForWorkOrder()` accepts only `productId`, then selects the first stock level by raw product ID:

   - `StockReservationService.php:445-447`: `StockLevel::where('product_id', $productId)->orderByRaw(...)->first()`
   - `StockReservationService.php:455-460`: derives `$company` from that stock level and calls `reserve(company: $company, productId: $productId, ...)`

   The cross-module caller is reachable:

   - `AddLineRequest.php:25` validates `product_id` as only `nullable|uuid`.
   - `WorkOrderLineController.php:43-48` passes that raw product ID into `AddLineCommand`.
   - `WorkOrderLineService.php:55-62` persists it on the current work order line without a scoped product re-read.
   - `WorkOrderTransitionService.php:94-97` calls the inventory reservation adapter when a work order moves to `Approved`.
   - `InventoryReservationAdapter.php:42-59` sends the line's raw `product_id` to `InventoryReservationServiceInterface::reserveForWorkOrder`.
   - `InventoryServiceProvider.php:20-22` binds that interface to `StockReservationService`.

   Impact: a user who can update and approve a work order in company A can attach a known product UUID from company B; on approval, Inventory derives company B from the stock level and creates/decrements a reservation against company B stock. The fix should make `reserveForWorkOrder` take caller scope, preferably the work order's tenant/company, and scope the product/stock-level lookup by that company instead of deriving company from an unscoped stock row. Add a regression test for a same-tenant and/or cross-tenant foreign product ID on work-order approval.

2. LOW - api.inventory manual-stub follow-ups are not tracked.

   `grep -c "cluster_id: api.inventory" docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml` returned `0`, while both round-1 reviews and the round-2 commit message defer inventory follow-ups such as `CountingItemController::triggerThirdCount`, `FraudTriggeredCountingService::User::first()`, and `InventoryCountingController::createDraft` tenant hygiene. The manual stub file documents that these rows are the durable source for non-mechanical gaps. This does not create a runtime leak by itself, but it means deferred api.inventory findings can silently disappear from the generated sweep inventory.

## Audit Exhaustiveness

Commands and key outputs:

- `git show --stat --name-only --oneline c521b051` -> exactly the three expected files.
- `grep -rn --include='*.php' -- "->where('tenant_id'" apps/api/app/Modules/Inventory | grep -v /tests/` -> all tenant-only or paired tenant/company occurrences accounted for; fixed controllers include both predicates; WAC stock-level tuple locks remain informational.
- `grep -rnE "(StockLevel|StockMovement|StockReservation|InventoryCounting|InventoryCountingItem)::query\\(\\)" apps/api/app/Modules/Inventory --include='*.php' | grep -v /tests/` -> controller reads and service reads reviewed.
- `grep -rnE "(StockLevel|StockMovement|StockReservation|InventoryCounting)::(find|findOrFail|first|firstOrFail)\\(" apps/api/app/Modules/Inventory --include='*.php' | grep -v /tests/` -> no matches.
- `grep -rnE "\\$request->(input|header|query)\\(.{0,20}(company_id|tenant_id|X-Company-Id)" apps/api/app/Modules/Inventory --include='*.php' | grep -v /tests/` -> no matches.
- `grep -rn "app(CompanyContext" apps/api/app/Modules/Inventory` -> no matches.
- `grep -rnE -- "->validated\\(\\)|\\$validated\\[" apps/api/app/Modules/Inventory/Presentation/Controllers/ apps/api/app/Modules/Inventory/Presentation/Requests/ | grep -v /tests/` -> only `InventoryCountingController.php:216` in the specified inventory paths; request classes use scoped product/location/user validators except the known category hygiene.
- `grep -rnE "findOrFail|::find\\(|::first\\(|::firstOrFail\\(" apps/api/app/Modules/Inventory/Presentation/Controllers/ | grep -v /tests/` -> controller direct reads reviewed; company-scoped or parent-scoped, except known `CountingItemController::triggerThirdCount` validation-only blind spot.
- `grep -c "cluster_id: api.inventory" docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml` -> `0`.
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` -> empty.

## Gates

- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` -> `verified 1093 event(s) across 268 callsite(s); 0 problem(s).`
- `vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php` -> `OK (17 tests, 49 assertions)`.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Inventory tests/Feature/Inventory/InventoryTenantIsolationTest.php` -> `[OK] No errors`.
- POS/Voucher diff gate -> empty.

## Confidence

High confidence that commit `c521b051` closes Codex round-1 HIGH 1 and MEDIUM 2 exactly as claimed, and that Opus's four informational round-2 notes are accurately characterized. I am requesting changes because the hostile service-tier pass found a separate reachable cross-company stock reservation path that Opus did not report.
