# Codex second-layer review — api.pricing reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: 30069a66
Reviewer: codex (round-1 second-layer review post-Opus APPROVE)

Verdict: REQUEST-CHANGES
Commit reviewed: da535327

## Opus claim verification
- Verified: `CouponApplicationService::recordUsage` now constructor-injects `CompanyContext` and scopes the coupon lookup with both `where('tenant_id', $company->tenant_id)` and `where('company_id', $company->id)` before `findOrFail($couponId)`.
- Verified: `da535327` adds the three claimed tests in `tests/Feature/Pricing/PricingTenantIsolationTest.php`: cross-tenant denial, same-tenant control, and structural SQL predicate coverage.
- Verified: full pricing tenant-isolation PHPUnit run passed: `OK (22 tests, 65 assertions)`.
- Verified: PHPStan passed on `app/Modules/Coupon` plus the pricing tenant isolation test file.
- Verified: Pint passed on `app/Modules/Coupon` plus the pricing tenant isolation test file.
- Verified: `sweep:inventory:verify-history` passed with `1409 event(s) across 299 callsite(s); 0 problem(s)`.
- Verified: test honesty. Temporarily replacing only the fixed lookup with pre-fix `Coupon::findOrFail($couponId)` made the three new tests fail as expected: `Tests: 3, Assertions: 5, Failures: 2`. The cross-tenant denial test failed because no exception was thrown, and the structural SQL test captured `select * from "coupons" where "coupons"."id" = ? ...` with no `"tenant_id"`. Restored the fix and reran the three tests: `OK (3 tests, 9 assertions)`.
- Verified with nuance: POS surface diff is empty when pinned to the reviewed tip (`dev..30069a66` and `30069a66^..30069a66`). Current branch `HEAD` has later POS commits, so `dev..HEAD` is no longer empty; that is branch drift after this review target, not a defect in `da535327`.
- Verified: api.unmapped.017 currently has `cluster_id: api.pricing`, `status: under_review`, `owner: codex`, `fix_commit: da535327`, and the history contains the reassignment event plus claim/start at `9630e58b` and submit at `da535327`.
- Verified: commit author `otospexsolutions` with YAML owner `codex` is informational only and matches the orchestrator-claims-on-behalf pattern.

## New findings (round 1, second-layer)
- REQUEST-CHANGES: Opus missed sibling Coupon route-param reads that still violate the stated cluster invariant. `apps/api/app/Modules/Coupon/Presentation/routes.php` exposes `coupons/{id}` for show, update, destroy, revoke, and reactivate. Each corresponding `CouponController` method reads the route-param `$id` through `Coupon::query()->forCompany($companyId)->findOrFail($id)` at lines 76, 102, 116, 187, and 208. `forCompany` only adds `company_id`; it does not add `tenant_id`. That may be practically bounded if company IDs are globally unique, but the workflow invariant for this review is literal: route-param anchored reads must carry both `tenant_id` and `company_id` predicates. Fix by deriving `$company = $this->companyContext->requireCompany()` in those handlers and chaining `where('tenant_id', $company->tenant_id)->where('company_id', $company->id)` before `findOrFail`, with at least one structural SQL test covering a `coupons/{id}` route.
- No production caller currently invokes `CouponApplicationService::recordUsage`; only the three new tests call it. POS/cart/document paths use coupon code validation through `CouponValidatorContract::validateAndCalculate`, and that lookup is scoped by `CartContext` tenant/company values.
- No `Coupon` model `redeem`, `use`, or `decrementUsage` helper methods exist in this codebase. The only model-side usage helper found was `customerUsageCount`, a relationship-bounded `usages()->where('partner_id', ...)` count on an already-loaded `Coupon`.

## Audit exhaustiveness
- Hostile-grep result count: 7
- Tests: full pricing tenant-isolation suite passed (`22 tests, 65 assertions`); pre-fix honesty run failed the two expected tests; post-restore focused run passed (`3 tests, 9 assertions`).
- PHPStan: `[OK] No errors`.
- Pint: `{"result":"pass"}`.
- verify-history: `verified 1409 event(s) across 299 callsite(s); 0 problem(s)`.
- POS surface diff: empty for the reviewed tip (`dev..30069a66` / `30069a66^..30069a66`); non-empty only at current later HEAD due unrelated POS commits after `30069a66`.
- Wider scan: `Coupon::query|find|findOrFail` across `app/` returns only `CouponApplicationService` and `CouponController`. The fixed service call is clean; the controller route-param reads are the missed invariant issue above. `recordUsage` has no production callers.

## Confidence
High on the one-callsite remediation: the fixed lookup is correct, the tests are honest, and the requested gates pass. High on the request-changes finding: the sibling Coupon controller methods are route-param reads and their SQL shape cannot include `"tenant_id"` because they only call `forCompany($companyId)` before `findOrFail`.
