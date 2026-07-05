# api.super-admin-context + web.super-admin-frontend (paired) — Codex round-2 re-review

Reviewed commit: 3c4f728e
Reviewer: codex
Date: 2026-05-08
Verdict: BLOCK

## Verdict rationale
Round 2 closes the specific round-1 deferrals-fixture blocker for Groups A-D, F, G, and I, and the architecture test plus new deferral-validation test behave correctly under baseline and mutation checks. Approval is still blocked because two newly attributed classifications are not structurally verified: PurchaseHub offers use a tenant-tagged `PlatformHttpClient` only on cache miss while caching under a global key, and POS voucher/receipt sync claims terminal-scoped SQL tenant binding that is not present on the GET pull paths. The requested POS scope diff is also non-empty.

## Round-1 BLOCKER closure
Group A is structurally verifiable: `Ingredient`, `KeyComponent`, `HealthClaim`, `Certification`, and `StampDutyRule` had zero `tenant_id`/`company_id` matches in the inspected model files, and the sampled catalog reasons cite global shared reference data. Group B is structurally verifiable: `PublicProductImageController` checks `$product->is_active_for_ecommerce` in both public methods and `Product` declares/casts the column. Group C is structurally verifiable: `AttachmentController::config` returns `AttachmentService` constants only, and `OpeningBalanceBatchController::types` maps `OpeningBatchType::cases()` only. Group D is structurally verifiable as explicit known gaps: `Document` and `Product` only expose local `scopeForTenant()` methods, while `ProductImage` and `User` showed no `booted()`/`addGlobalScope()` matches; the controller reasons now say `KNOWN TENANT-ISOLATION GAP`. Group E is only partially verified: the injection chain exists (`PurchaseHubService` and `BarcodeLookupService` inject `PlatformHttpClient`; `PlatformHttpClient` injects `CompanyContext` and stamps headers), but `PurchaseHubService::getOffers()` uses a global `purchase_hub:offers` cache key before making the tenant-tagged HTTP call, so `PurchaseHubOfferController::index` is not structurally tenant-bound on cache hits. Group F is structurally verifiable for the role/permission methods: `SetPermissionsTeam` is mounted on tenant route groups and `config/permission.php` has `teams => true`. Group G is structurally verifiable by route shape and controller purpose: marketplace listing routes are authenticated buyer discovery under `/api/v1/marketplace`, while seller management is under `/api/v1/admin/marketplace`. Group H is not structurally verified: `VoucherSyncController::requireTerminalId()` only returns the query string, `pullVouchers()` filters `Voucher` by `redeemable_at_terminal_id`, `pullLedger()` filters through vouchers with the same column, and `pullReceiptQrIndex()` filters receipts by `terminal_id`, but the GET paths do not validate that the supplied terminal belongs to the authenticated tenant/company. `Gate::authorize('pos.operate_terminal')` is permission-only and receives no terminal argument. Group I is structurally verifiable: `VinDecodeController` methods validate input and return hardcoded 200/501 placeholder responses with no DB or service calls.

## Round-2 mutation tests
Baseline `cd apps/api && vendor/bin/phpunit tests/Architecture` passed with `OK (10 tests, 46 assertions)`. Mutation a, removing `#[CrossTenantRoute]` from `App\Modules\Product\Presentation\Controllers\IngredientController::index`, failed red naming that method. Mutation b, removing the attribute from `App\Http\Controllers\Api\DocumentAdditionalCostController::index`, failed red naming that method. Mutation c, adding `App\NonExistent\Controller::*` to the deferrals fixture, failed red with `class does not exist`. Mutation d, adding `SuperAdminController::doesNotExist`, failed red with `method does not exist on class`. Mutation e, adding a wildcard deferral with a blank reason, failed red naming `App\Http\Controllers\Api\Admin\SuperAdminController::*` as blank. All temporary edits were restored, and the final architecture rerun passed again with `OK (10 tests, 46 assertions)`.

## Reason text quality
The sampled Group A reasons (`IngredientController::store`, `HealthClaimController::index`) are non-vacuous and accurately cite no tenant column/global shared catalog behavior. The sampled Group D reason (`DocumentAdditionalCostController::store`) is non-vacuous and accurately identifies a known route-model-binding gap on `Document`. The sampled Group E reason (`PurchaseHubOfferController::index`) is not accurate enough because it cites the `PlatformHttpClient` tenant-header path but omits that `getOffers()` can return a globally cached value without invoking the client. The sampled Group H reason (`VoucherSyncController::pullVouchers`) is over-claimed: it says tenant identity flows through the terminal's `tenant_id` column at the SQL layer, but the inspected query filters only `redeemable_at_terminal_id` and does not join or resolve `pos_terminals` against authenticated context.

## Inventory state
`cd apps/api && php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` returned `verified 1732 event(s) across 347 callsite(s); 0 problem(s).` The six requested rows are present at `status: under_review` with `fix_commit: e51d3951`: `api.super-admin-context.001`, `api.unmapped.020`, `api.unmapped.021`, `api.unmapped.022`, `api.unmapped.023`, and `web.super-admin-frontend.001`.

## Scope check
`git diff --stat fcb4c7ab..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher apps/web/src/features/pos` was not empty:

```text
 .../Modules/POS/Presentation/Controllers/VoucherSyncController.php   | 5 +++++
 1 file changed, 5 insertions(+)
```

The same non-empty diff is present for `fcb4c7ab..3c4f728e`; the round-2 commit adds `CrossTenantRoute` import/attributes to `apps/api/app/Modules/POS/Presentation/Controllers/VoucherSyncController.php`.

## BLOCKERs (if any)
BLOCKER: `App\Modules\PurchaseHub\Application\Services\PurchaseHubService::getOffers()` caches under the global key `purchase_hub:offers` before the tenant-tagged `PlatformHttpClient` call. `PurchaseHubOfferController::index` therefore can serve another tenant's previously cached offers without stamping `X-Tenant-Id` / `X-Company-Id`, contradicting the Group E service-trust reason.

BLOCKER: `App\Modules\POS\Presentation\Controllers\VoucherSyncController` GET pull methods are not structurally terminal/tenant scoped as claimed. `requireTerminalId()` does not validate UUID ownership, `Gate::authorize('pos.operate_terminal')` does not bind the supplied terminal, and the SQL inspected in `VoucherSyncService` filters by `redeemable_at_terminal_id` rather than resolving `pos_terminals.tenant_id/company_id` from authenticated context. The reason text overclaims SQL-layer tenant binding.

BLOCKER: The requested scope check is non-empty. Round 2 changes `apps/api/app/Modules/POS/Presentation/Controllers/VoucherSyncController.php`, despite the checklist expectation of an empty diff for POS/Voucher/POS-web paths.

## NICE-TO-HAVEs (if any)
None beyond the blockers above.

## Sign-off
Blocked until the PurchaseHub offer cache is tenant/company-scoped or the attribute reason is narrowed to a verified non-tenant-specific cache invariant, the POS voucher/receipt pull paths either validate terminal ownership or are reclassified with an accurate known-gap reason, and the POS scope-diff expectation is reconciled.
