# api.inventory cluster - Codex second-layer adversarial review

Verdict: REQUEST-CHANGES
Commit reviewed: 1eada1cb

Reviewer: Codex headless second-layer adversarial review
Branch: feat/tenant-isolation-sweep-execution
Date: 2026-05-05
Cluster: api.inventory

## Opus Claim Verification

1. Confirmed LOW: `CountingItemController::triggerThirdCount` still has a bare `exists:inventory_counting_items,id` validator at `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:240-242`. The mutation is structurally constrained by `InventoryCounting::forCompany($companyId)->findOrFail($countingId)` and `InventoryCountingItem::whereIn(...)->where('counting_id', $counting->id)->update(...)` in `InventoryCountingService.php:467-475`. This is an enumeration/validation blind spot, not a cross-tenant write path.

2. Confirmed issue, refuted reachability framing: `FraudTriggeredCountingService.php:56-57` uses `User::where('email', 'system@autoerp.local')->first() ?? User::first()`. Opus was right that this is not HTTP-user-controlled, but it is triggered by a multi-company scheduled command: `routes/console.php:21-25` schedules `fraud:detect`; `DetectFraudPatterns.php:51-53` defaults to `Company::all()`; `DetectFraudPatterns.php:96-99` calls `detectAndAct`; `AnomalyDetectionService.php:369-372` calls `createCountingFromAlert`. If the system user is missing, the fallback can set `created_by_user_id` and `InventoryCounting.tenant_id` from an arbitrary other tenant while using the alert's `company_id`.

3. Confirmed LOW: `InventoryCountingController::createDraft` persists `company_id` but not `tenant_id` at `InventoryCountingController.php:422-444`. Schema confirms `inventory_countings.tenant_id` was added nullable in `2026_03_04_100000_add_tenant_id_and_counting_number_to_inventory_countings.php:13-20`. Reads are still company-scoped, but tenant/audit integrity is inconsistent.

4. Confirmed INFORMATIONAL: `StockReservationService::reserveWithFEFO` has no production callers; `rg "reserveWithFEFO\\(" apps/api/app apps/api/tests` found only the definition at `StockReservationService.php:355`.

5. Confirmed INFORMATIONAL with caveat: `StockMovementController` receive/issue/transfer/adjust validators accept UUIDs only at `StockMovementController.php:59-64`, `98-103`, `152-157`, and `210-214`. Location access is checked through `LocationContext::validateLocationAccess`, and `StockAdjustmentService::getOrCreateStockLevel` scopes the product lookup by `Location.company_id` at `StockAdjustmentService.php:470-475`. For existing malformed stock levels, `lockStockLevel` still trusts `product_id` + `location_id` at `StockAdjustmentService.php:494-510`; I did not find a current HTTP path that creates such a mixed row after the reviewed fix.

## New Findings Opus Missed

1. HIGH: HTTP-reachable stock level and stock movement reads leak same-tenant cross-company inventory data.

`StockLevelController::index` and `show` scope only by `tenant_id` from the selected company, then return stock levels and eager-loaded product/location data:

- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockLevelController.php:22-37`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockLevelController.php:53-63`

`StockMovementController::index` has the same issue:

- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:25-47`

Both models have a `company_id` column and `scopeForCompany`: `StockLevel.php:20-21,168-170` and `StockMovement.php:22-23,186-188`. The global middleware only proves the user can access the selected `X-Company-Id` company (`CompanyContextMiddleware.php:66-77`); it does not authorize access to every company under the same tenant. A user with access to Company A can select Company A and still receive Company B stock levels/movements if Company B shares the tenant. Fix by scoping these read queries with `where('company_id', $company->id)` / `forCompany($company->id)` and adding same-tenant cross-company regression coverage.

2. MEDIUM: The WAC service-tier tenant-scope fixes are not pinned by regression tests.

I temporarily reverted `WeightedAverageCostService::recordPurchase` from the fixed scoped lookup back to `Product::lockForUpdate()->findOrFail($product->id)`. `InventoryTenantIsolationTest.php` still passed: 13 tests, 38 assertions. I then ran the WAC-specific and inventory event subset with the same revert:

`vendor/bin/phpunit tests/Unit/Inventory/WeightedAverageCostServiceTest.php tests/Feature/Inventory/InventoryEventsTest.php tests/Feature/Inventory/InventoryTenantIsolationTest.php`

Result: 35 tests, 73 assertions, all green. The code is currently fixed, but the service-tier callsites `.012-.014` can regress silently. Add direct cross-tenant/cross-company service tests or structural SQL assertions for `recordPurchase`, `recordSale`, and `recordReturn`.

## Annotated Callsite Verification

- `.009/.010`: Confirmed route shadowing. `routes.php:84-86` registers `GET /stock-reservations/{id}` before `routes.php:96-98` registers `GET /stock-reservations/breakdown`. `rg --fixed-strings '/stock-reservations/breakdown' apps/api ...` found no other route registration.
- `.018`: Confirmed `recordMovement` is private and called only from public stock adjustment methods after deriving `tenantId` from the upstream `StockLevel` (`StockAdjustmentService.php:40-58`, `115-145`, `202-245`, `414-422`). `getOrCreateStockLevel` loads `Location` and scopes `Product` by `Location.company_id` at `StockAdjustmentService.php:470-475`, so new stock levels are company-coherent.

## Schema Honesty

- `products`: `tenant_id` created in `2025_11_30_052910_create_products_table.php:16-19`; `company_id` added in `2025_11_30_130000_add_company_id_to_existing_tables.php:49-54` and made non-null in `2025_11_30_134000_make_company_id_required.php:29-32`.
- `locations`: `company_id` only, no `tenant_id`, in `2025_11_30_105000_create_locations_table.php:23-64`.
- `users`: `tenant_id` only, no `company_id`, in `2025_11_30_000003_create_users_table.php:16-43`.
- `inventory_countings`: `company_id` created in `2025_12_02_070000_create_inventory_countings_table.php:20-23`; nullable `tenant_id` added in `2026_03_04_100000_add_tenant_id_and_counting_number_to_inventory_countings.php:13-20`.
- `stock_reservations`: `company_id` only, no `tenant_id`, in `2025_12_24_133728_create_stock_reservations_table.php:14-56`.
- Related to the new finding: `stock_levels` and `stock_movements` both started tenant-scoped in `2025_11_30_110000_create_inventory_tables.php:16-48`; both later received nullable `company_id` in `2025_11_30_131000_add_company_id_to_stock_tables.php:31-44`.

## Audit Exhaustiveness

Commands and key outputs:

- `git show --patch --find-renames 1eada1cb` read end-to-end in chunks.
- `rg -c "fix_commit: 1eada1cb" docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` -> 28.
- `rg -c "regression_test: apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php" ...` -> 28.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` -> `verified 1089 event(s) across 268 callsite(s); 0 problem(s).`
- Remaining bare exists grep found only `CountingItemController.php:242`.
- Request-sourced `company_id` / `tenant_id` / `X-Company-Id` grep in Inventory -> no hits.
- Forbidden `app(CompanyContext` grep in Inventory -> no hits.
- Equivalent route-anchored `where(id|product_id|location_id|...)` search surfaced the stock level/movement read issues above plus already-protected reservation/counting paths.
- `vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php` -> OK, 13 tests, 38 assertions.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Inventory tests/Feature/Inventory/InventoryTenantIsolationTest.php` -> OK, no errors.
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` -> empty.

## Confidence

High confidence that commit `1eada1cb` closes the 28 submitted callsites and passes the required chain/test/static gates. The review should not be approved yet because the inventory module still has an HTTP-reachable same-tenant cross-company read leak in stock level and stock movement endpoints, and the WAC service-tier fixes are not regression-pinned.
