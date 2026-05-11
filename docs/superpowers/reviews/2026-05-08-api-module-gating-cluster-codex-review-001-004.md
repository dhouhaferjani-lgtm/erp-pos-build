# api.module-gating cluster - Codex round-1 review (.001 + .002 + .003 + .004 fix-commit pin)

Branch tip reviewed: 8e91818f
Commit reviewed: 4b9a0fac
Reviewer: codex
Date: 2026-05-08
Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED

(Sibling alias of
`2026-05-08-api-module-gating-cluster-codex-review.md` with the
"Commit reviewed:" line pinned to `4b9a0fac` — the fix_commit for all
four manual rows `.001` (Progression), `.002` (Tenant), `.003`
(Identity), `.004` (Growth Advisor response-id). The canonical file
lists three SHAs (`dc53d188`, `4b9a0fac`, `4a16e4d5`) under a
"Reviewed commits:" header which the inventory parser's single-SHA
invariant rejects. This derivative file's content is identical to the
canonical; only the header line is reformatted to `Commit reviewed:
<single SHA>` for parser compatibility.

Codex's actual eyes were on HEAD `8e91818f` which contains the in-band
test edit committed alongside the verdict. The `4b9a0fac` pin reflects
the production-code commit that all four manual rows share.)

## Verdict
APPROVE-WITH-MINOR-EDITS-APPLIED. The cluster closes 20 line-level
callsites in 6 controllers across 3 modules, plus the Growth Advisor
response-id contract on ProgressionService::getProfile +
::registerCompany. Two cluster invariants closed: (a-1) data-scoping
for 9 callsites in Progression × 3 + Tenant/Onboarding × 1, and (a-2)
audit-attribution for 11 callsites in Tenant/CompanySettings × 3 +
Identity/UserController × 8. Plus 2 endpoints under the response-id
mismatch fail-loud contract.

Mutation checks confirmed both fix-shapes catch regressions:
- `ModuleReadinessController.php:32` `requireCompanyId()` reverted to
  raw header read →
  `test_module_readiness_index_pins_company_id_to_company_context_not_header`
  RED. Restored.
- `ProgressionService::assertResponseCompanyMatches` throw replaced
  with silent return →
  `test_get_profile_throws_when_response_company_id_mismatches` RED.
  Restored.

Hostile grep result: zero remaining live `$request->header('X-Company-Id')`
reads outside the structural protector at
`CompanyContextMiddleware.php:105`.

In-band edit applied (no scope expansion):
- `tests/Unit/Progression/ProgressionServiceResponseIdVerificationTest.php`
  +2 tests — `test_company_id_guard_allows_responses_without_company_id_echo`
  and `test_tenant_id_guard_allows_responses_without_tenant_id_echo`.
  Both invoke private guard helpers via reflection with payloads that
  omit the echo field; assert no throw. Closes the truth-table
  no-echo branch.

Verification (from `apps/api/`):
- `./vendor/bin/phpunit --filter ModuleGatingTenantIsolationTest`
  PASS — 20 tests, 63 assertions.
- `./vendor/bin/phpunit --filter ProgressionServiceResponseIdVerificationTest`
  PASS — 7 tests, 13 assertions.
- `./vendor/bin/phpunit tests/Feature/Progression/ tests/Unit/Progression/`
  PASS — 75 tests, 218 assertions.
- `./vendor/bin/phpunit tests/Architecture/`
  PASS — 10 tests, 53 assertions.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Progression app/Modules/Tenant app/Modules/Identity`
  PASS — no errors.
- `./vendor/bin/pint --test app/Modules/Progression app/Modules/Tenant app/Modules/Identity tests/Feature/Progression tests/Unit/Progression`
  PASS.
- `git diff --stat 9035c20d..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`
  empty.

## Per-callsite Verdict Table

| ID | Verdict | Evidence |
|---|---|---|
| `api.module-gating.001` | APPROVE-WITH-MINOR-EDITS-APPLIED | Progression × 3 controllers: 8 method-level callsites swapped from `$request->header('X-Company-Id')` to `$this->companyContext->requireCompanyId()`; `register` also swaps tenant header to `requireTenantId()`. Behavioral source-pin tests: 8 in `ModuleGatingTenantIsolationTest` (all GREEN). Mutation 1 confirmed regression catch. |
| `api.module-gating.002` | APPROVE-WITH-MINOR-EDITS-APPLIED | Tenant/Onboarding (1) + Tenant/CompanySettings (3): 4 method-level callsites pin to CompanyContext. Onboarding's redundant `if ($companyId === null)` 400 guard removed (CompanyContextMiddleware already 403s upstream, requireCompanyId() throws redundantly). 4 behavioral tests in `ModuleGatingTenantIsolationTest` (all GREEN). |
| `api.module-gating.003` | APPROVE-WITH-MINOR-EDITS-APPLIED | Identity/UserController: 8 method-level callsites pin to CompanyContext for audit-log companyId attribution. 8 behavioral tests in `ModuleGatingTenantIsolationTest` covering store, update, destroy, activate, deactivate, setPosPin (set + clear), resetPassword (all GREEN). AuditEvent table assertions confirm validated-context companyId, not malicious-header companyId. |
| `api.module-gating.004` | APPROVE-WITH-MINOR-EDITS-APPLIED | ProgressionService: `assertResponseCompanyMatches` + `assertResponseTenantMatches` private helpers added. Applied to `getProfile` (company-only) and `registerCompany` (company + tenant). Truth table fully covered: match → no-throw, mismatch → throw, no-echo → no-throw. 7 unit tests in `ProgressionServiceResponseIdVerificationTest` (all GREEN; +2 from in-band edit). Mutation 2 confirmed regression catch. |

## Layering with `api.super-admin-context`

The cluster's behavioral source-pinning layer correctly complements
the static classification layer added by `api.super-admin-context`
(`ControllerTenantContextTest::test_every_controller_method_is_classified`).
The static check admits the (a-2) callsites today via the heuristic
regex matching `CompanyContext` references; this cluster closes the
deeper invariant ("every header-derived value is replaced with a
context-derived value") that PhpParser's data-flow ceiling cannot
reach (api.broadcast-channels round-3 lesson).

## Severity framing held

Both (a-1) and (a-2) framed as HIGH severity in PR body, triage doc,
and commit messages. (a-2) audit-attribution is independently
load-bearing for NF525 + AdminAuditLog + two-tier hash chain
compliance — not "lower severity" than (a-1).

## Scope boundaries verified

POS, Voucher, GrowthAdvisorHttpClient outbound headers (Finding K),
CompanyConfigService cache, SuperAdmin updateExtras: cluster-scoped
diff empty against all surfaces.

APPROVE-WITH-MINOR-EDITS-APPLIED
