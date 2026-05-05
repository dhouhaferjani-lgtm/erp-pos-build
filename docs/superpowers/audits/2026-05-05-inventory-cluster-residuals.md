# api.inventory cluster residuals — round-2 follow-ups

Date: 2026-05-05
Branch: `feat/tenant-isolation-sweep-execution`
Trigger: Codex round-1 review of api.inventory reassigned cluster (commit
`39718854`). Verdict file:
`docs/superpowers/reviews/2026-05-04-api-inventory-reassigned-cluster-codex-review.md`.

Round-2 fix commit closes Finding 1 (productBatchStock leak) and hardens
Finding 4 (.014 value-pin tautology). This file tracks the residual
defense-in-depth items Codex flagged as out-of-scope but worth recording.

## Residuals

### LOW — `BatchController::posAvailableBatches` productId is unvalidated

Path: `GET /api/v1/pos/products/{productId}/batches`
File: `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php` (`posAvailableBatches` method)

The route-param `productId` flows into
`FEFOInventoryService::suggestBatchesForSale`, whose query filters
`product_batches.product_id = ?` and
`inventory_batch_stock.location_id = ?` without tenant/company
predicates. Codex round-1 verified the leak is structurally blocked:
the inline `location_id` validator is now `ScopedExists::company`, so a
tenant-A caller can only pass a current-company location. Under normal
data integrity a foreign tenant's batch stock will not exist at a
current-company location.

Status: **structurally protected via location_id validator**. Treat as
defense-in-depth debt — if a future migration reorders the join, or
inventory_batch_stock starts being shared across companies, this
becomes a live leak. Tracked here, not fixed in round-2 because the
exploit path requires a separate broken invariant.

### LOW — `BatchRepository` UUID and bulk surfaces

File: `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`

| Method | Public surface | Status |
|---|---|---|
| `findByUuid` | `BatchController::findBatchOrFail` (post-load `$batch->company_id` check) and `BatchTraceabilityController` (post-load company check) | Upstream-protected. Cross-tenant company-context coercion is blocked by `CompanyContextMiddleware`. |
| `findByBatchNumber` | `BatchController::store` (passes `$companyId` from `CompanyContext`) and the duplicate-check inside `store` | Caller-scoped. Method already takes `companyId` as first arg. |
| `getByCompany` | `BatchController::index` (passes `$companyId` from `CompanyContext`) | Caller-scoped. |
| `markAsExpired` | `DailyExpiryCheck` console command (iterates per-company already; callsite passes the right batch id) | Caller-scoped. Console command already filters batches by company_id before invoking. |
| `recall` | `BatchController::recall` (uses `findBatchOrFail` first) | Upstream-protected. |
| `delete` | `BatchController::destroy` (uses `findBatchOrFail` first) | Upstream-protected. |
| `update` | `BatchController::update` (uses `findBatchOrFail` first) | Upstream-protected. |

No queue job, listener, or service path was found that calls these
repository methods directly without first resolving a Batch through
`findBatchOrFail` or a `CompanyContext`-scoped method. The repository is
not API-symmetric (some methods take companyId, others don't) but the
asymmetry is currently safe given upstream guards.

Status: **upstream-protected**. Future refactor candidate: tighten the
interface so every repository method requires `$tenantId` + `$companyId`
(matching the round-2 fix to `getByProduct`), eliminating the post-load
guard pattern. Not blocking for the api.inventory cluster.

## What round-2 closed

- `BatchRepository::getByProduct(string $tenantId, string $companyId, string $productId, bool $activeOnly = true)`
  now leads with both predicates. Interface and the only caller
  (`BatchController::productBatchStock`) updated to pass them from
  `CompanyContext`.
- `tests/Feature/Inventory/InventoryTenantIsolationTest::test_product_batch_stock_does_not_leak_cross_tenant_batches`
  pins both the response shape (empty array, no foreign rows) and the
  SQL invariant (`tenant_id` AND `company_id` literals in the WHERE
  clause). Seeds a tenant-B batch tied to productB; the test fails on
  pre-fix code because the foreign batch leaks into tenant-A's
  response.
- `productA` seed in `setUp` now carries `cost_price = '10.00'` so the
  `.014` `BatchWriteOffService::calculateWriteOffAmount` reflection
  test discriminates: same-tenant returns `'10.00'`, cross-tenant
  returns `'0.00'`. Pre-fix code (unscoped `Product::find`) would
  return `'10.00'` for both, breaking the cross-tenant assertion.
