Verdict: REQUEST-CHANGES
Commit reviewed: 44207410

# api.inventory Codex round-3 adversarial review

## Opus round-3 claim verification

1. Commit scope: verified. `git show 44207410 --name-only` is exactly 5 files:
   - `apps/api/app/Modules/Inventory/Application/Contracts/InventoryReservationServiceInterface.php`
   - `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php`
   - `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php`
   - `apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php`
   - `apps/api/tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php`
   No Document, Service, Catalog, Accounting, POS, Voucher, or `apps/pos` files are in the commit.

2. Codex round-2 HIGH 1 closure: verified. The interface now requires `tenantId` and `companyId`; `StockReservationService::reserveForWorkOrder` scopes the `StockLevel` lookup by `tenant_id`, `company_id`, and `product_id`; the `Company` lookup is tenant-scoped; `InventoryReservationAdapter::reserveFor` passes `$wo->tenant_id` and `$wo->company_id`.

3. Test honesty: verified. I removed only the `tenant_id` and `company_id` predicates from the `StockLevel` lookup while leaving the new signature and caller-company lookup intact. The new test failed:
   `Failed asserting that exception message 'No query results for model [App\Modules\Inventory\Domain\StockLevel].' matches '/no StockLevel row found/'.`
   I restored the code and confirmed no remaining diff in the touched service file.

4. Caller reachability: verified. Grep found one production caller of `reserveForWorkOrder`: `InventoryReservationAdapter::reserveFor`. Other production hits are the interface, implementation, and enum comments. Test hits are the two workshop stubs and the new inventory regression test.

5. Opus narrower-exploit claim: refuted for the actual pre-fix code. `reserve()` does have a `StockLevel` `firstOrFail` at lines 101-105, but before commit 44207410 `reserveForWorkOrder` derived `Company` from the same unscoped `StockLevel` row (`Company::findOrFail($stockLevel->company_id)`) and passed that row's location into `reserve()`. With a foreign `StockLevel` for the forged product, the downstream `firstOrFail` would match product + location + foreign company and would not catch the exploit. The `firstOrFail` only catches the partial mutation where caller company is already enforced but the initial `StockLevel` guard is removed.

6. Opus gates claim: not verified. Chain verifier, PHPUnit, and POS/Voucher diff passed, but the exact PHPStan command from the prompt failed in this checkout with 59 errors in existing files outside commit 44207410 (`GoodsReceiptTest.php`, `InventoryEventsTest.php`, `StockManagementTest.php`). The touched-file PHPStan subset passes. This falsifies "gates all green" for the requested gate set and is the reason for `REQUEST-CHANGES`.

## New round-3 findings

1. MEDIUM - Required PHPStan gate is red in current checkout.
   Command:
   `cd apps/api && ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Inventory app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php tests/Feature/Inventory tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php`
   Result: 59 errors, all in pre-existing Inventory test files not touched by 44207410. This is not evidence that the round-3 fix is wrong, but it means the required verification gate is not green.

2. LOW - `releaseForWorkOrder` / `releaseBySource` still accept only raw `workOrderId` / source id and do not carry tenant/company scope. Current production reachability is via `InventoryReservationAdapter::releaseFor(WorkOrder $wo)`, and UUID collision risk is low, matching Opus's non-blocking observation. This should be tracked as a follow-up before cluster close or in the manual-stub population.

3. INFO - Additional workshop cross-module raw-FK patterns observed outside api.inventory: `BundleExpansionAdapter::pricingModeOf` / `bundleName` use raw `ServiceBundle::find($bundleId)`, and `DocumentGenerationAdapter` loads `$wo->quote_document_id` with raw `Document::find`. I did not classify these as api.inventory blockers for commit 44207410, but they are relevant to the broader workshop/module-boundary isolation sweep.

## Audit exhaustiveness

- Read `git show 44207410` end-to-end.
- Read the modified Inventory contract, StockReservationService, Workshop adapter, and new/updated tests.
- Ran the requested caller searches for `reserveForWorkOrder` in app and tests.
- Ran hostile greps for `->company_id`, Inventory contracts, unscoped `StockLevel::where('product_id'...)`, `StockReservation` id/source lookups, and `releaseBySource`.
- Checked actual parent pre-fix code with `git show 44207410^`.
- Checked manual-stub gap: `grep -c "cluster_id: api.inventory" docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml` returned `0`. Per orchestrator note, I treat this as a hard prerequisite for cluster close, not a blocker for the round-3 commit fix itself.

## Gate results

- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: passed, `verified 1093 event(s) across 268 callsite(s); 0 problem(s).`
- `vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php`: passed, `20 tests, 54 assertions`.
- Exact requested PHPStan gate: failed, 59 errors in existing Inventory test files outside commit 44207410.
- Touched-file PHPStan subset: passed, no errors.
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`: empty.

## Confidence

High confidence that Codex round-2 HIGH 1 is closed by commit 44207410 and that the new regression test honestly pins the intended guard. Medium-high confidence that no additional api.inventory production caller was missed. The review cannot approve Opus's "all gates green" claim because the exact PHPStan gate is red in this checkout.
