# Codex second-layer review — api.cart cluster (round 1)

Review date: 2026-05-04
Branch tip reviewed: 65f291a2
Reviewer: codex (round-1 second-layer review post-Opus APPROVE-WITH-MINOR-EDITS-APPLIED)

Verdict: APPROVE
Commit reviewed: 65f291a2

## Opus claim verification
- Read Opus verdict end-to-end. Its substantive claims hold.
- `git show 65f291a2` was read end-to-end. The commit scopes both inventoried Partner reads with `tenant_id` and `company_id`: `CartConversionService::convertToPurchaseOrder()` uses scoped `Partner::where(...)->where(...)->find($partnerId)`, and `convertToSalesOrder()` uses scoped `findOrFail($customerId)`.
- `CatalogCartController` has 9 `CatalogCart::query()` chains total. The 8 route-param anchored lookups (`show`, `update`, `destroy`, `addItem`, `updateItem`, `removeItem`, `convert`, `marketplaceCheckout`) now lead with `where('tenant_id', $company->tenant_id)->where('company_id', $company->id)`. The remaining chain is `index()`, which is not route-param anchored and remains company-only as Opus flagged.
- `convert()` hardens `customer_id` with `ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id)`.
- Opus's Phase-2 test-honesty claim is reproduced. After temporarily checking out the pre-fix `744e8155` versions of `CartConversionService.php` and `CatalogCartController.php`, the Cart suite fails 4/7 exactly on the claimed paths: cross-tenant customer accepted as 201, cross-tenant supplier linked into the PO, missing `tenant_id` in the show SQL, and no partners exists-validator query. Restored HEAD afterward and the suite returned green.
- Cross-module caller trace found only `CatalogCartController` invoking `CartConversionService`; no queue jobs, scheduled tasks, listeners, or other service callers were found under `apps/api/app`.
- Inventory state at workflow child `5baa4d56`: `api.cart.001` and `api.cart.002` are `under_review`, both pin `fix_commit: 65f291a2`, and both pin the Cart tenant-isolation regression tests. The api.cart cluster itself remains `in_progress`, consistent with callsites awaiting review completion.

## New findings (round 1, second-layer)
- No new request-change findings.
- Opus nice-to-have #1 remains accurately classified: `CatalogCartController::index()` is a list query anchored by `CompanyContext`, not a route-param read. Adding `tenant_id` would improve consistency, but the cluster invariant under review is satisfied for route-param anchored reads.
- Opus nice-to-have #2 remains accurately classified: `addItem()` still accepts `preferred_supplier_partner_id` without `ScopedExists`. This permits storing a foreign tenant UUID on a cart item, but the reviewed service-layer conversion read is now scoped and does not leak or link the foreign Partner. It is a defense-in-depth follow-up, not a blocker for api.cart.001.

## Audit exhaustiveness
- Hostile-grep result count: 6. All are clean `cart_id = $cart->id` reads after an upstream tenant+company scoped cart load, or service reads constrained to an already scoped cart instance.
- Tests: `vendor/bin/phpunit tests/Feature/Cart/CartTenantIsolationTest.php` OK (7 tests, 22 assertions) before and after the pre-fix honesty check restoration.
- PHPStan: `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Cart tests/Feature/Cart/CartTenantIsolationTest.php` OK, no errors.
- Pint: `./vendor/bin/pint --test app/Modules/Cart tests/Feature/Cart/CartTenantIsolationTest.php` pass.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` verified 581 events across 262 callsites; 0 problem(s).
- POS surface diff: empty for `65f291a2^..65f291a2` and for the workflow child range `65f291a2..5baa4d56` under `apps/web`, `apps/pos`, and `packages/shared`.

## Confidence
High. The inventoried callsites are closed at the SQL-read layer, the controller route-param reads satisfy the two-predicate invariant, validator hardening is present for `customer_id`, tests are non-vacuous under pre-fix rollback, and no hidden `CartConversionService` callers were found. Residual risk is limited to Opus's non-blocking defense-in-depth items.
