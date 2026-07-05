# Codex second-layer review — api.inventory reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: d54151d0
Reviewer: codex

Verdict: REQUEST-CHANGES
Commit reviewed: 39718854

## Opus claim verification

Opus's positive claims hold for the six submitted callsites. The five validator fixes now use constructor-injected CompanyContext and ScopedExists: products is correctly tenant+company scoped, while locations is correctly company-scoped because the locations migration has company_id only and CompanyContextMiddleware validates user-company membership before setting context. BatchWriteOffService::calculateWriteOffAmount now takes tenantId + companyId and the only in-class caller passes the source batch's tenant_id + company_id.

Gates re-run:
- `vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php` — PASS, 24 tests / 71 assertions.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/BatchExpiry tests/Feature/Inventory/InventoryTenantIsolationTest.php` — PASS.
- `./vendor/bin/pint --test app/Modules/BatchExpiry tests/Feature/Inventory/InventoryTenantIsolationTest.php` — PASS.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — PASS, 1409 events / 299 callsites / 0 problems.

Test honesty also holds at the fix-file level: with only the 39718854 production files reverted and the six regression tests retained, all six dedicated tests fail. The first attempted full patch revert removed the new tests and therefore executed no tests; the app-only revert is the meaningful check.

POS surface check: `git diff --name-only dev..HEAD -- apps/web/src apps/web/e2e apps/web/public packages/shared` is empty. The broader apps/web diff contains tooling/config files only, not actual UI surfaces.

## Opus's 4 deferred findings — disposition

- Finding 1 (productBatchStock): CONFIRMED in-scope leak. `GET /api/v1/products/{productId}/batch-stock` calls `BatchController::productBatchStock`, which directly calls `BatchRepository::getByProduct($productId, activeOnly: true)`. That repository query is only `where('product_id', $productId)` plus active/recalled filters; it does not use CompanyContext, tenant_id, or company_id. The route does not pass through `findBatchOrFail`, and the route param is attacker-controlled. Earlier api.inventory remediation commits 1eada1cb, c521b051, and 44207410 did not touch BatchExpiry at all, so this was not previously covered. Because BatchExpiry was reassigned into api.inventory and this is a public BatchExpiry route, this is not deferrable.

- Finding 2 (posAvailableBatches route param): verified. The `productId` route param flows into `FEFOInventoryService::suggestBatchesForSale`, whose query filters `product_batches.product_id = ?` and `inventory_batch_stock.location_id = ?` without tenant/company predicates. The fixed `location_id` validator means a tenant-A user can only use a current-company location, and under normal data integrity a foreign product's batch stock will not exist at that location, so the leak is structurally blocked. This is still defense-in-depth debt on a route-param anchored read, but it is not the blocking leak here.

- Finding 3 (BatchRepository): found backdoor. Most public UUID surfaces using `findByUuid` are post-load company checked by BatchController::findBatchOrFail or BatchTraceabilityController; same-tenant cross-company access is blocked by company_id comparison, and cross-tenant company-context coercion is blocked by CompanyContextMiddleware. I found no queue/artisan/listener path that calls the repository methods directly; DailyExpiryCheck uses Batch directly. However, `getByProduct` is reachable from `productBatchStock` and bypasses the post-load guard entirely, so the repository is not fully upstream-protected.

- Finding 4 (test tautology): acceptable. The value pin is tautological on the current seed because productA has no non-zero cost, so both fixed and unfixed code return `0.00`. The structural SQL assertion does discriminate: on the app-only pre-fix revert it fails on SQL missing `"tenant_id"` and `"company_id"`. This should still be hardened by seeding productA with non-zero `cost_price` or WAC so the value assertion also fails on the old bare `Product::find`.

## New findings

1. MEDIUM — In-scope cross-tenant/cross-company batch read via productBatchStock.

`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:276` accepts a route-param productId and returns `BatchResource::collection($this->batchRepository->getByProduct(...))`. `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:33` scopes only by product_id. A tenant-A user can request tenant-B's productId and receive tenant-B product batch records. Fix by validating/scoping productId against current tenant+company and adding tenant_id + company_id predicates to the batch read, or by replacing this path with a current-company scoped repository method.

## Audit exhaustiveness

Reviewed Opus verdict end-to-end, full diffs for 39718854 and d54151d0, the BatchExpiry routes/controllers/repository/services/entities/migrations, prior inventory remediation commit file lists, and repository/service references across HTTP, jobs, listeners, and console paths. Confirmed product_batches carries tenant_id + company_id, locations carries only company_id, and inventory_batch_stock has tenant_id but no company_id.

## Confidence

High. The six submitted fixes are correct, but the first deferred finding is a live public BatchExpiry route in the reassigned api.inventory cluster and must be fixed before approval.
