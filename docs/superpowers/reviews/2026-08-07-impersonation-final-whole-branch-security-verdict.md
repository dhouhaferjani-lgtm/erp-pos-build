# Tenant Impersonation Final Whole-branch Security Review

Date: 2026-08-07

Reviewed implementation commit: `63f14987a07c2198db3162f1e583b1b7f7d0dc60`

Reviewed CI/test-infrastructure head: `c52d0f71faf66352a439e3d834813f96f43406be`

Branch: `codex/tenant-impersonation`

## Scope

This review re-evaluates the complete branch against the locked tenant-impersonation design, the 2026-08-07 consolidated fix brief, and the final tenancy/authorization, fiscal/audit, and frontend re-reviews. It focuses on tenant isolation, token binding, permission intersection, revocation, hard-block enforcement, fail-closed behavior, and authoritative audit continuity.

## Security findings

No blocker, major, or minor impersonation security finding remains at the reviewed implementation commit.

- The Sanctum token remains tokenable to the subject tenant user and is bound to the operator, tenant, session, and personal-access-token claims. Cross-tenant subject selection remains impossible.
- Tenant-claim enforcement precedes impersonation context resolution. Malformed claims, missing grants, expired or terminal state, unavailable audit, and malformed hard-block configuration fail closed.
- Effective permissions are recomputed from live subject permissions on every request and can only shrink. Self-referential support-access, identity, and role-administration permissions are never intersectable.
- Support-access mutations, tenant deletion, password reset, role/identity mutations, and every registered POS, Fiscal, and Accounting write route remain hard-blocked even during write elevation.
- Revoking a grant commits the grant, every live child session, authoritative chained lifecycle events, terminal session state, and durable delivery rows atomically before mirror delivery. The next bearer request is denied and chained before token deletion.
- Session and grant chains are independently verifiable and tamper tests fail verification. Both audit mirrors retain impersonator and session attribution without changing the pre-existing fiscal hash bytes.
- The persistent banner is mounted above all application routes, tenant history is sanitized and paginated, and subject-token state is memory-only.

The exact-commit re-review verdicts are unanimous:

- Tenancy and authorization: **ACCEPT**
- Fiscal and audit: **ACCEPT**
- Frontend: **ACCEPT**

All three reviewers separately reconfirmed **ACCEPT** at the CI-only branch head. The `63f14987a..2f779989` delta changes only `.github/workflows/ci.yml`; it aligns the named central connection with the disposable CI database and partitions the full backend gate across all paths configured by `phpunit.xml`, while continuing after individual failures and aggregating a final failure status.

All three reviewers then reconfirmed **ACCEPT** at `c52d0f71f`. Its sole delta is a test-lifecycle guard that permits the PostgreSQL-only acceptance test to skip under SQLite without cleanup queries against a schema that was never migrated. PostgreSQL setup failures remain failures, cleanup remains unchanged after setup succeeds, and the dedicated PostgreSQL path still exercises 132 assertions.

## Verification and promotion disposition

Implementation-head CI run: [31173960530](https://github.com/otospexsolutions/erp/actions/runs/31173960530)

CI/test-infrastructure-head run: [31187640701](https://github.com/otospexsolutions/erp/actions/runs/31187640701)

Final exact-head run: [31202946159](https://github.com/otospexsolutions/erp/actions/runs/31202946159)

The feature-scoped SupportAccess suite passes with 85 tests and 594 assertions, plus one expected PostgreSQL-only skip. The live local PostgreSQL grant-to-revocation acceptance path passes with 132 assertions. PHPStan level 8, TypeScript checking, generated-type drift, focused frontend tests, and the feature-scoped formatting checks pass.

The first run proved that `always()` starts the manual backend gate after Unit failure, but the single 11,473-test PHP process exhausted its 2 GiB memory limit after 8,968 tests. Its PostgreSQL lane ran all 132 SupportAccess acceptance assertions before an environment-mismatch teardown failure. Commit `2f7799890` corrects both infrastructure defects. Run `31187640701` confirms the dedicated PostgreSQL SupportAccess test and teardown pass and that all 75 configured backend paths reach `tests/Architecture` without memory exhaustion. It also exposed an SQLite-skip cleanup error confined to the PostgreSQL-only acceptance test; `c52d0f71f` fixes that test lifecycle.

Final exact-head run `31202946159` completes **failure**. PHPStan, TypeScript, POS Vitest, and generated-type drift pass. The PostgreSQL lane again confirms the live SupportAccess test and teardown pass; it reports 889 passed, 3 skipped, 1 failed, and 3,669 assertions, with the sole failure at the pre-existing fiscal chokepoint inventory call site. The manual gate runs all 75 configured paths through `tests/Architecture` without memory exhaustion: 11,473 tests and 52,972 assertions, with 87 errors, 103 failures, 385 skipped, and 3 incomplete on repository baselines. The SupportAccess partition itself is clean: 82 tests, 571 assertions, and one intended PostgreSQL-only skip.

## Verdict

**ACCEPT — impersonation security implementation. DO NOT MERGE — repository promotion remains blocked by any mandatory failing exact-head gate.**

This verdict does not waive Pint, Deptrac, chokepoint, PostgreSQL invariant, full backend, frontend, or preflight failures. It accepts only the reviewed implementation's security posture. The branch remains handback-only and must never be pushed to `origin/dev`.

Impersonation **must not be used on a real Tunisian tenant** until the E-4 business-partner Tunisia Law 2004-63 / INPDP validation is completed and recorded. Break-glass access remains out of scope.
