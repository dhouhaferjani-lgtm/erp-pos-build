# Codex round-2 second-layer review — api.inventory reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: a30290ae
Reviewer: codex (round-2 second-layer review)

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 39718854

Commits reviewed (api.inventory reassignment cluster, cumulative):
- 39718854 — round-1 fix: ALL 6 reassigned callsites (api.unmapped.006-010 BatchExpiry FormRequest validators + api.unmapped.014 BatchWriteOffService scoping). All 6 callsites' inventory `fix_commit` pin = 39718854.
- a30290ae — round-2 IMPROVEMENTS: BatchRepository::getByProduct + BatchController::productBatchStock scoping (closes the live productBatchStock leak Codex flagged round-1 Finding 1) + .014 test seed hardening. Reviewed against the round-1 pin per workflow design — round-2 is a strict improvement and out-of-scope improvements (the productBatchStock surface wasn't an inventoried callsite).

The workflow's review-command commit-linkage parser reads the FIRST `Commit reviewed:` line. All 6 inventory-reassigned callsites pin to 39718854 so that hash leads.

## Round-1 finding closure (verified independently)
- Finding 1 (productBatchStock unscoped leak): CLOSED. `BatchRepository::getByProduct` now requires tenant + company + product and leads the query with `where('tenant_id', $tenantId)->where('company_id', $companyId)->where('product_id', $productId)`. `BatchController::productBatchStock` resolves both ids from constructor-injected `CompanyContext::requireCompany()` and passes them to the only `getByProduct` caller. Red check: temporarily restoring the old product-only repository/controller path made `test_product_batch_stock_does_not_leak_cross_tenant_batches` fail by returning `BATCH-LEAK-B` to tenant A.
- Finding 4 (test tautology hardening): CLOSED. `productA` is seeded with `cost_price = '10.00'`; same-tenant `.014` assertion pins `'10.00'` and cross-tenant pins `'0.00'`. Red check: simulating the old unscoped `Product::find($productId)` lookup, while keeping the hardened seed/test, failed at the cross-tenant assertion with actual `'10.00'`.
- Finding 2 (posAvailableBatches deferral): documented
- Finding 3 (BatchRepository deferral catalog): documented, with a minor edit applied to correct stale `markAsExpired` / repository `recall` callsite notes.

## New findings (round 2, second-layer)
None requiring changes.

## Audit exhaustiveness
Reviewed the full `a30290ae` diff, prior Codex round-1 verdict, `BatchController`, `BatchTraceabilityController`, `BatchRepository`, `BatchStockService`, `FEFOInventoryService`, `BatchWriteOffService`, `DailyExpiryCheck`, request validators, routes, and all `getByProduct` / BatchRepository callsites. Ran the requested hostile grep over `apps/api/app/Modules/BatchExpiry`; the remaining bare id/product/location matches are guarded by current-company route/entity checks, documented structural protection, scheduled global job semantics, or are unused repository helpers. Also verified no queue job, listener, or scheduled command calls the newly tightened `getByProduct`.

Gates:
- `vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php` — PASS, 25 tests / 77 assertions.
- `vendor/bin/phpunit tests/Feature/Inventory` — PASS, 111 tests / 309 assertions / 2 skipped.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/BatchExpiry tests/Feature/Inventory/InventoryTenantIsolationTest.php` — PASS.
- `./vendor/bin/pint --test app/Modules/BatchExpiry tests/Feature/Inventory/InventoryTenantIsolationTest.php` — PASS.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — PASS, 1437 events / 303 callsites / 0 problems.
- Post-restore focused fixed check for the two remediated tests — PASS, 2 tests / 11 assertions.

Note: `git stash push --message codex-round2-review-preimage` was attempted before red checks but the sandbox could not create `.git/index.lock`. I used targeted temporary patches instead and verified restoration with a clean tracked diff before writing this verdict.

## Confidence
High. The live leak is closed, the hardened `.014` value pin now fails on the old lookup behavior, the residual deferrals are documented accurately after the minor doc edit, and the BatchExpiry scan did not reveal another in-scope route-param anchored tenant/company leak.
