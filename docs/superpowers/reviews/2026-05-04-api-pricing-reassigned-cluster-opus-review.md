# Opus adversarial review — api.pricing reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: 30069a66
Reviewer: opus (round-1 first-layer adversarial review)

Verdict: APPROVE
Commit reviewed: da535327

## Summary

The fix for the single reassigned callsite (api.unmapped.017 → api.pricing,
`CouponApplicationService::recordUsage` at line 51) closes the cluster
invariant gap cleanly:

- Pre-fix `Coupon::findOrFail($couponId)` is replaced with a tenant- and
  company-scoped lookup
  (`Coupon::query()->where('tenant_id', $company->tenant_id)
  ->where('company_id', $company->id)->findOrFail($couponId)`),
  derived from a constructor-injected `CompanyContext`. The implementation
  mirrors the api.pricing service-tier defense-in-depth template
  (`PricingService::getPrice` — uses identical
  `$this->companyContext->requireCompany()` pattern with constructor
  injection).
- Three new tests in
  `apps/api/tests/Feature/Pricing/PricingTenantIsolationTest.php` pin the
  Treasury template invariants:
  cross-tenant denial (with strong post-condition assertions on
  `use_count`, `status`, and absence of a `CouponUsage` row),
  same-tenant control, and the bar-raising structural-SQL-log invariant
  that asserts both `"tenant_id"` and `"company_id"` appear in the
  captured `select * from "coupons"` lookup query.
- Tests are HONEST. I reverted the service file to its pre-fix state and
  re-ran the three new tests: cross-tenant denial and structural-SQL
  invariant both fail (denial: `null is not null`; SQL: actual SQL
  `select * from "coupons" where "coupons"."id" = ? and ... limit 1` does
  not contain `"tenant_id"`). Same-tenant control passes pre- and post-fix
  as expected. This proves the tests detect the exact bug class the cluster
  invariant exists to prevent.
- The full pricing tenant-isolation suite is green
  (22 tests / 65 assertions), PHPStan + Pint clean on `app/Modules/Coupon`
  and the test file, no new findings from hostile grep.
- POS surface diff dev..HEAD is empty
  (apps/api/app/Modules/POS, apps/web/src/features/pos|POS — zero changes;
  the broader dev..HEAD diff only touches tooling/tests, none of it on POS
  feature code).

The constructor-injection choice (vs. service-method parameter pattern) is
consistent with the rest of api.pricing — `PricingService` and
`PricingController` both use `private readonly CompanyContext`. No
`app()` helper, no `mixed`, strict types preserved.

No blocking findings, no nice-to-haves worth a follow-up commit.

## Findings

1. None — fix is structurally sound and the test pin is honest.

### Non-blocking observations (informational only, no action requested)

- The submitter's `recordUsage` lookup uses
  `where('tenant_id', ...)->where('company_id', ...)` directly rather than
  the existing `forCompany($companyId)` model scope. Both shapes are valid;
  the bare-`where` form is in fact stricter because it pins both predicates
  literally and is what the structural-SQL-log invariant test asserts on.
  Not a defect — flagging only because the sibling `validateAndCalculate`
  method at line 31 uses the `forCompany` scope. Stylistic only.
- `recordUsage` is NOT part of the `CouponValidatorContract` interface
  (only `validateAndCalculate` is). I checked all callers of `recordUsage`
  in the codebase via `grep -rEn "->recordUsage\("` — only the three new
  test callers and the implementation itself reference it. There is no
  production caller yet (the POS checkout path that will eventually call
  it is not wired to this method). The fix is therefore preemptive but
  correct: when the POS wiring lands, the scope guard is already in place.
  Cluster owner may want to track this as a follow-up "wire recordUsage
  into the POS checkout path" item, but that is out of scope for this
  remediation.
- The inventory YAML row sets `owner: codex` and `status: under_review`
  while the fix commit is authored by `Claude Opus 4.7 (1M context)`.
  That is a coordination-contract observation, not a structural defect of
  the fix. (The certification SOT and history trail correctly capture the
  claim/start events.)

## Audit exhaustiveness

- Hostile-grep result count: 7 matches in `app/Modules/Coupon/` for the
  union pattern
  `where\(['"](id|coupon_id|partner_id|company_id|tenant_id)['"]|::find\(|::findOrFail\(|exists:`
  (excluding tests). Each was verified clean:
  - `CouponApplicationService.php:32` — `where('tenant_id', $cart->tenantId)`
    inside the `validateAndCalculate` chain, paired with
    `forCompany($cart->companyId)` and `byCode($code)`.
    Structurally protected by upstream guard:
    `CartContext::tenantId|companyId` are populated by callers
    (`CouponController::validate`, `DiscountOrchestratorService::handle`)
    from `CompanyContext::requireCompany()`. Not a route-anchor read.
  - `CouponApplicationService.php:61–62` — the new fix itself; both
    predicates present and derived from the injected CompanyContext.
  - `Coupon.php:163` — `$this->usages()->where('partner_id', $partnerId)`
    is a relationship-bounded count on a Coupon already loaded under a
    scoped chain. No tenant-anchor drift possible.
  - `Coupon.php:187` — the `forCompany` scope definition itself.
  - `StoreCouponRequest.php:40` and `UpdateCouponRequest.php:41` —
    `Rule::unique('coupons', 'code')->where('company_id', $companyId)`;
    `$companyId` comes from `CompanyContext::requireCompanyId()` in the
    request's authorize step. Structurally protected.

  Wider check on the canonical model entry points
  (`Coupon::find|findOrFail|query`) across `app/`: 8 matches, all in
  `CouponApplicationService` and `CouponController`, every one of them
  scoped via `forCompany` or the new explicit
  `tenant_id + company_id` chain. Zero bare entries remain.

  Caller hunt: `grep -rEn --include='*.php' "->recordUsage\("` and
  `grep -rEn "validateAndCalculate"` over `app/` and `tests/`. Only the
  three new test callers invoke `recordUsage` — no production caller
  bypasses the new scoping. `validateAndCalculate` is invoked from
  `CouponController::validate` and `DiscountOrchestratorService::handle`,
  both of which build the `CartContext` from `CompanyContext`.

  Sibling-method check: confirmed `CouponApplicationService` exposes only
  `validateAndCalculate` and `recordUsage`. `CouponManagementService`
  (revoke/reactivate) accepts an already-scoped `Coupon` entity from the
  controller and does not perform any unscoped lookup. No sibling
  unscoped pattern was missed.

- Tests:
  - `vendor/bin/phpunit tests/Feature/Pricing/PricingTenantIsolationTest.php`
    → `OK (22 tests, 65 assertions)` — matches submitter claim.
  - Test honesty: I replaced `CouponApplicationService.php` with its
    pre-fix version (extracted via `git show da535327^:`) and re-ran
    the three new tests. Result:
    `Tests: 3, Assertions: 5, Failures: 2` — exactly the two new
    cross-tenant assertions fail, with the structural-SQL test
    pinpointing the missing `"tenant_id"` predicate in the captured
    SQL `select * from "coupons" where "coupons"."id" = ? ...`. The
    same-tenant control passes (correctly, because the bug allows
    same-tenant operations too). The post-fix file was restored cleanly
    (`git status -s apps/api/app/Modules/Coupon/` empty).
  - Structural-SQL-log invariant honesty: the matcher selects the right
    query by filtering on
    `from "coupons"` + `"id" =` + NOT `count(*)` — that uniquely matches
    the lookup `findOrFail` query and excludes the subsequent `select count(*)`
    used by `usages()->create` and the increment update. Pre-fix output
    confirms the matcher captures the exact lookup query, not a side
    channel. Honest.
- PHPStan:
  `vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Coupon tests/Feature/Pricing/PricingTenantIsolationTest.php`
  → `[OK] No errors`.
- Pint:
  `vendor/bin/pint --test app/Modules/Coupon tests/Feature/Pricing/PricingTenantIsolationTest.php`
  → `{"result":"pass"}`.
- POS surface diff:
  `git diff dev..HEAD --stat -- apps/api/app/Modules/POS apps/web/src/features/pos apps/web/src/features/POS`
  → empty. Broader `apps/web` dev..HEAD diff touches only tooling and
  tests (`apps/web/tools/audit-*.mjs`, `apps/web/vitest.config.ts`),
  none of it on POS feature code. POS surface invariant satisfied.

## Confidence

High. The fix is a one-line, surgical, structurally-correct application of
the api.pricing service-tier template. The test pin is honest under the
strongest available test (revert-and-rerun produces the expected
failures, restore produces a clean tree). The cluster invariant
(both `tenant_id` AND `company_id` predicates on every read whose anchor
came from a route param) is satisfied via the constructor-injected
`CompanyContext`-derived `tenant_id` + `company_id` pair, and the
surrounding `CouponApplicationService::validateAndCalculate` /
`CouponController` / `CouponManagementService` callers were independently
re-verified as scoped or structurally protected by upstream guard. POS
surface diff is empty. PHPStan + Pint clean. Cluster aggregate is safe to
roll forward to closed once the SOT is updated for this row.
