# Codex round-2 second-layer review — api.pricing reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: 6ca27b7aca63359e78335c11edecb81b5ba5f407
Reviewer: codex (round-2 second-layer review)

Verdict: APPROVE
Commit reviewed: da535327

Commits reviewed (api.pricing reassignment cluster, cumulative):
- da535327 — round-1 fix: api.unmapped.017 (CouponApplicationService::recordUsage). The single inventoried reassigned callsite. Already pinned in inventory at fix_commit=da535327.
- eccc5b2a — round-2 IMPROVEMENT: 6 sibling CouponController route handlers tightened from forCompany-only to forTenant + forCompany. Out of scope for the inventoried callsite but closes the cluster invariant violation Codex round-1 surfaced. Reviewed against the round-1 fix's pin (da535327) per workflow design — round-2 is a strict improvement, not a re-pin.

The workflow's review-command commit-linkage parser reads the FIRST `Commit reviewed:` line. api.unmapped.017's inventory fix_commit is da535327, so that hash leads to satisfy the per-callsite linkage check.

## Round-1 finding closure (verified independently)
- Finding (CouponController forCompany-only chains): closed. `git show eccc5b2a` adds `Coupon::scopeForTenant(Builder $query, string $tenantId): Builder`, mirroring `scopeForCompany(Builder $query, string $companyId): Builder` and returning the same `Builder<Coupon>` pattern.
- Verified all six CouponController routes now resolve `$companyId = $this->companyContext->requireCompanyId()` plus `$tenantId = $this->companyContext->requireCompany()->tenant_id`, with no `app()` helper or mixed context access.
- Verified the six route reads are tenant+company scoped before lookup/pagination:
  - `index`: `Coupon::query()->forTenant($tenantId)->forCompany($companyId)`
  - `show`: `Coupon::query()->forTenant($tenantId)->forCompany($companyId)->findOrFail($id)`
  - `update`: same tenant+company chain before `findOrFail($id)`
  - `destroy`: same tenant+company chain before `findOrFail($id)`
  - `revoke`: same tenant+company chain before `findOrFail($id)`
  - `reactivate`: same tenant+company chain before `findOrFail($id)`
- Verified no remaining CouponController route-param lookup is left at `forCompany($companyId)` only.
- Test honesty check: temporarily reverting the `show` lookup to pre-fix `Coupon::query()->forCompany($companyId)->findOrFail($id)` made `test_show_coupon_query_includes_tenant_and_company_predicates` fail with captured SQL lacking `"tenant_id"`. After restore, the two new show tests passed (`OK (2 tests, 5 assertions)`). Nuance: `test_show_coupon_rejects_cross_tenant_id` is a valid 404 control, but it is not independently pre-fix sensitive because its fixture uses a different tenant and a different company; the pre-fix company-only lookup also returns 404 for that case.

## New findings (round 2, second-layer)
- None requiring changes.
- Hostile Coupon-module scan found no sibling controller, service, or repository that performs a Coupon entity lookup through a route-param or coupon-id anchor with `forCompany()` only. `CouponApplicationService::validateAndCalculate` is scoped by `tenant_id` and `company_id` through `CartContext`; `recordUsage` is scoped by `CompanyContext` tenant and company before `findOrFail`.
- Noted but not classified as a defect: `StoreCouponRequest` and `UpdateCouponRequest` still use company-only `Rule::unique(...)->where('company_id', $companyId)` checks. These are code uniqueness validation reads, not Coupon entity fetches; `UpdateCouponRequest::ignore($couponId)` does not select the ignored row. They are outside the round-1 route-anchored Coupon lookup finding.

## Audit exhaustiveness
- `git pull --ff-only` was attempted but the sandbox could not open `.git/FETCH_HEAD` (`Operation not permitted`). Verified instead that `eccc5b2a` is an ancestor of the reviewed local tip.
- `vendor/bin/phpunit tests/Feature/Pricing/PricingTenantIsolationTest.php`: passed on this checkout with `OK (24 tests, 70 assertions)`. The prompt expected `34/106`; the observed file content has 24 tests at the reviewed tip.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Coupon tests/Feature/Pricing/PricingTenantIsolationTest.php`: passed, `[OK] No errors`.
- `./vendor/bin/pint --test app/Modules/Coupon tests/Feature/Pricing/PricingTenantIsolationTest.php`: passed, `{"result":"pass"}`.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: passed, `verified 1415 event(s) across 299 callsite(s); 0 problem(s).`
- Inventory state for `api.unmapped.017`: `cluster_id: api.pricing`, `status: under_review`, `fix_commit: da535327`. The round-2 controller hardening commit `eccc5b2a` is not pinned in that callsite row, which matches the row still being under review from the original recordUsage remediation.

## Confidence
High. The exact round-1 finding is closed in the live tree, the new tenant scope composes cleanly with existing Coupon scopes, the structural SQL regression test is pre-fix sensitive, and the requested static/test gates pass. Residual risk is limited to the noted test-quality nuance: the cross-tenant 404 control is not itself a tenant-predicate regression test.
