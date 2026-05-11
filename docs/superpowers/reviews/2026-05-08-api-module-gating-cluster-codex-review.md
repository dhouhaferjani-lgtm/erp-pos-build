# Codex adversarial review - api.module-gating cluster

Review date: 2026-05-08
Reviewer: Codex
Cluster: `api.module-gating`
Owner: claude
Branch: `feat/tenant-isolation-sweep-execution`
Reviewed commits: `dc53d188`, `4b9a0fac`, `4a16e4d5`

## Findings

| Severity | Surface | Finding | Recommendation / status |
|---|---|---|---|
| MINOR - applied | `apps/api/tests/Unit/Progression/ProgressionServiceResponseIdVerificationTest.php:150`, `apps/api/tests/Unit/Progression/ProgressionServiceResponseIdVerificationTest.php:161` | The response-id unit suite covered match, company mismatch, tenant mismatch, and client-null short-circuit, but did not directly exercise the no-echo guard branches described in the triage/self-audit checklist. | Applied in-band: added two unit tests that invoke `assertResponseCompanyMatches` and `assertResponseTenantMatches` with missing echo fields and assert no throw. Re-ran the unit suite and full Progression feature/unit suite green. |

No blocking findings remain.

## Hostile-grep result

Command:

```bash
grep -rn "request->header.*X-Company-Id\|->header('X-Company-Id" \
  apps/api/app --include='*.php' | grep -v Test | grep -v Middleware || true
```

Result: no live non-middleware code reads remain. The only matches are explanatory docblocks:

- `apps/api/app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php:25`
- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:33`
- `apps/api/app/Modules/Progression/Presentation/Controllers/ModuleReadinessController.php:16`

I also confirmed the structural protector remains the middleware read at `apps/api/app/Http/Middleware/CompanyContextMiddleware.php:105`, with membership validation before `CompanyContext::setCompanyId()`.

## Anti-vacuousness checks

- The 20 line-level callsites in the six reviewed controllers now source company-bearing arguments from `CompanyContext::requireCompanyId()`. `CompanyProgressionController::register` also sources tenant id from `CompanyContext::requireTenantId()`.
- The reviewed controller callsites do not wrap `requireCompanyId()` in a catch that swallows the missing-context failure. The only nearby catch is the existing non-fatal invitation notification catch in `UserController::store`, after audit logging.
- The feature tests deliberately bypass `CompanyContextMiddleware`, bind a mock `CompanyContext` returning company A, send malicious company B headers, and assert downstream service calls or `AuditEvent.company_id` use company A. This is non-vacuous because natural middleware flow would make header and context agree.
- The audit-log `if ($companyId === null) return;` guards are now unreachable from the fixed callsites but acceptable as defense in depth. Keeping the nullable helper signature avoids expanding the diff surface.
- The existing `CompanyProgressionControllerTest` updates are honest: the mocks now echo the real test company and tenant ids so the new response-id contract is satisfied without weakening the original endpoint assertions.
- Response-id truth table after the minor edit:
  - `response['id'] === expected` returns DTO: covered.
  - `response['id'] !== expected` throws: covered for `getProfile` and `registerCompany`.
  - `response['id']` absent returns from the guard without throwing: covered by the added unit test.
  - `response['tenant_id'] !== expected` throws for `registerCompany`: covered.
  - `response['tenant_id']` absent returns from the guard without throwing: covered by the added unit test.
- The six URL-bound Growth Advisor shapes without company echo are not guarded by the company-id assertion. Feature coverage exercises those methods with module/recommendation/milestone ids that are not company ids, proving the company-id guard is not applied to those response shapes.
- The triage doc and commit message consistently frame (a-2) audit attribution as HIGH severity and independently load-bearing for compliance, not lower severity metadata cleanup.
- Scope boundaries held. A diff check against cluster start for POS, Voucher, `apps/pos`, `GrowthAdvisorHttpClient`, `CompanyConfigService`, and SuperAdmin surfaces returned empty.

## Manual rows

All four manual rows are present and submitted at fix commit `4b9a0fac`:

- `api.module-gating.001` - `manual:api.module-gating:progression-controllers-companycontext-injection`
- `api.module-gating.002` - `manual:api.module-gating:tenant-controllers-companycontext-injection`
- `api.module-gating.003` - `manual:api.module-gating:identity-user-controller-companycontext-audit-attribution`
- `api.module-gating.004` - `manual:api.module-gating:growth-advisor-response-id-verification`

Their inventory status is `under_review`, as expected before this review verdict is processed.

## Verification

Commands run from `apps/api/` unless noted:

| Command | Result |
|---|---|
| `./vendor/bin/phpunit --filter ModuleGatingTenantIsolationTest` | PASS - 20 tests, 63 assertions. PHPUnit deprecation noise only. |
| `./vendor/bin/phpunit --filter ProgressionServiceResponseIdVerificationTest` | PASS after minor edit - 7 tests, 13 assertions. PHPUnit deprecation noise only. |
| `./vendor/bin/phpunit tests/Feature/Progression/ tests/Unit/Progression/` | PASS after minor edit - 75 tests, 218 assertions. |
| `./vendor/bin/phpunit tests/Architecture/` | PASS - 10 tests, 53 assertions. |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Progression app/Modules/Tenant app/Modules/Identity` | PASS - no errors. |
| `./vendor/bin/pint --test app/Modules/Progression app/Modules/Tenant app/Modules/Identity tests/Feature/Progression tests/Unit/Progression` | PASS. |

## Mutation checks

1. Mutated `apps/api/app/Modules/Progression/Presentation/Controllers/ModuleReadinessController.php:32` from `requireCompanyId()` to `$request->header('X-Company-Id', '')`.
   - Expected RED confirmed: `test_module_readiness_index_pins_company_id_to_company_context_not_header` failed because the mocked Growth Advisor client received the malicious header company id instead of the context company id.
   - Mutation restored.

2. Mutated `ProgressionService::assertResponseCompanyMatches` from the fail-loud throw to a silent `return`.
   - Expected RED confirmed: `test_get_profile_throws_when_response_company_id_mismatches` failed with "Failed asserting that exception of type RuntimeException is thrown."
   - Mutation restored.

Post-restore focused suites were re-run green:

- `ModuleGatingTenantIsolationTest`: PASS - 20 tests, 63 assertions.
- `ProgressionServiceResponseIdVerificationTest`: PASS - 7 tests, 13 assertions.

## Justification

The structural fix is sound: every reviewed controller source-pins company ids to the middleware-validated `CompanyContext`, and `register` pins tenant id through the same context rather than a raw header. The behavioral tests are non-vacuous because they bypass the middleware and distinguish header-derived from context-derived values. The response-id contract now fails loud on mismatches, covers match and mismatch branches, and after the in-band edit covers the no-echo guard branches. Hostile grep shows no remaining live raw `X-Company-Id` reads outside the middleware protector, and the cluster did not drift into the explicitly out-of-scope modules.

APPROVE-WITH-MINOR-EDITS-APPLIED
