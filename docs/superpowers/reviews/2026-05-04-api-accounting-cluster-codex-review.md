# Codex second-layer review — api.accounting cluster (round 1)

Review date: 2026-05-05
Branch tip reviewed: HEAD at session-end (post Opus auto-flips)
Reviewer: codex (round-1 second-layer review post-Opus APPROVE)
Commit reviewed: a1963bbd

Verdict: REQUEST-CHANGES

## Summary

I verified Opus's review and reproduced the rollback / gate verifications, but I do **not** concur with the PartnerBalanceService classification. The `PartnerBalanceController` does not validate the URL `{companyId}` against the authenticated user's tenant or company-membership context. The `CompanyContextMiddleware` validates the `X-Company-Id` header (or falls back to the user's first membership), but the controller routes pass the URL `{companyId}` straight into the service.

The attack shape is therefore: tenant-A user sends `X-Company-Id: companyA` (middleware happy) AND hits `/api/v1/companies/{companyB}/partners/{partnerB}/balance/refresh`. The company-only service scope `Partner::query()->where('company_id', $companyB)->whereKey($partnerB)` resolves tenant-B's partner. The api.accounting.004/005 fix is **not** a strict improvement here — it remains fully exploitable along the route-driven cross-tenant path.

## Findings

1. **Severity: REQUEST-CHANGES (BLOCKING for api.accounting.004/005)** — `PartnerBalanceService::refreshPartnerBalance` and `::getCachedOrCalculateBalance` scope by company_id only. With the unguarded `PartnerBalanceController` URL `{companyId}` parameter, a foreign `(companyId, partnerId)` pair is fully exploitable. Companies-are-tenant-owned-UUIDs is true at the SCHEMA level but DOES NOT translate to a route-level boundary because the controller doesn't enforce that the authenticated user can access that company.
   - Files:
     - `apps/api/app/Modules/Accounting/Presentation/Controllers/PartnerBalanceController.php:160` (refresh route — no membership validation)
     - `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:296` (api.accounting.004 — refreshPartnerBalance scoped by company_id only)
     - same for getCachedOrCalculateBalance line ~373 (api.accounting.005)
   - Suggested fix (cluster-scoped): add `string $tenantId` parameter to both methods. Caller passes `$request->user()->tenant_id`. Scope queries with both predicates. The wider PartnerBalanceController membership-validation gap remains a separate api.accounting / api.partner-balance-controller cluster concern.
   - Test gap: my AccountingTenantIsolationTest covers cross-tenant `companyA + partnerB` (which fails the company_id predicate). It does NOT cover the real attack shape `companyB + partnerB` under tenantA auth (which currently SUCCEEDS — the company_id predicate matches because both are tenant-B values). Test must be extended.

2. **Severity: NICE / OUT-OF-SCOPE referral (not api.accounting.004/005)** — `AccountController::show/update` tenant-only lookup is a real within-tenant cross-company exposure. Belongs to a sibling cluster (api.accounting-account-controller).

3. **Severity: NICE / OUT-OF-SCOPE referral** — `Account::findByPurposeOrFail($companyId, $purpose)` is constrained by `SystemAccountPurpose` enum (so foreign purpose can't smuggle), but still trusts unchecked `$companyId`. Enum is not a tenant boundary. Belongs to sibling cluster.

## Audit exhaustiveness

- Pre-fix rollback (`git checkout a1963bbd~1 -- apps/api/app/Modules/Accounting apps/api/tests/Feature/Accounting`) reproduced: `10 tests, 11 assertions, 3 errors, 5 failures` — predicted shape (3 errors from getAccountDetails signature mismatch, 5 failures from unscoped reads / 200 vs 422).
- HEAD-restore: `AccountingTenantIsolationTest` passes `10 tests, 22 assertions`.
- PHPStan: OK.
- Pint: pass.
- sweep:inventory:verify-history: `1089 events / 268 callsites / 0 problems`.
- POS/Voucher diff dev..HEAD: empty.

## Confidence

High that REQUEST-CHANGES is the right verdict. The route-attack shape is reachable today and the api.accounting.004/005 fix at a1963bbd does not close it. Remediation is small (add a tenant_id parameter to two service methods + update one controller passthrough + add an honest test).

Note: Codex sandbox could not write this verdict file directly (apps/api-only write permission). Verdict text written by orchestrator (Claude) verbatim from Codex's `--output-last-message` summary.
