# Opus adversarial round-2 review — api.compliance cluster

Review date: 2026-05-04
Branch tip reviewed: 2d7d81d5
Reviewer: opus (round-2 first-layer adversarial review)

Verdict: REQUEST-CHANGES
Commit reviewed: 2d7d81d5

## Summary

The round-2 fix correctly closes Findings 1 and 2 as written: `Nf525ExportController` and `AuditController` now resolve `company_id` exclusively via `CompanyContext->requireCompanyId()`, body/query/header `company_id` is silently ignored, the legacy `/api/v1/audit/{events,anomalies}` routes now carry `SetPermissionsTeam` middleware plus `can:compliance.view_reprint_log`, and `CompanyContextMiddleware` (registered globally on the `api` group via `bootstrap/app.php` `appendToGroup('api', [...])`) verifies `X-Company-Id` against `UserCompanyMembership` before any controller runs.

However, in the course of verifying the AuditController fix I discovered a **new round-2 cross-tenant leak still reachable through the now-can-gated controller** that the round-1 review missed. The fix adds the permission gate but the controller's `index()` method dispatches into `AuditService::getEventsForAggregate(string $aggregateType, string $aggregateId)` which has **no `company_id` predicate at all** — the resolved `$companyId` is computed and then unused on that branch (lines 48–52 of `AuditController.php`). A user with `compliance.view_reprint_log` (admin/owner) who knows or guesses any `aggregate_id` UUID can read the audit events for that aggregate across tenant boundaries via `GET /api/v1/audit/events?aggregate_type=Document&aggregate_id=<foreign-uuid>`. The implementer's commit message defers `AuditService`/`AnomalyDetectionService` service-tier scoping to "a future api.audit-events cluster," but that justification does not hold for the in-scope `AuditController` whose `index()` method *is* in this cluster and currently routes into the unscoped helper. This is a Treasury-Finding-14 violation (read whose anchor is a query param has no tenant predicate).

Tests, PHPStan, and Pint are green for the work that was done. No other body/header `company_id` reads remain in the Compliance module (hostile-grep result: 0 in production code; 1 docblock comment).

## Round-1 finding closure

- Finding 1 (Nf525 fiscal data leak): CLOSED. `Nf525ExportController.php` line 39–43 injects `CompanyContext`; `exportJet` (line 59), `verifyChains` (line 82), `reprintLog` (line 147) all call `$this->companyContext->requireCompanyId()`; `company_id` is dropped from all three validators; the only remaining `input('company_id')` reference in the module is a docblock at line 32. Tests `test_export_jet_ignores_cross_tenant_body_company_id`, `test_verify_chains_ignores_cross_tenant_body_company_id`, `test_reprint_log_ignores_cross_tenant_query_company_id` pin the regression. Filename embedding the resolved company UUID is a clean structural assertion.

- Finding 2 (AuditController structural defects): CLOSED for the three named defects.
  - (a) `SetPermissionsTeam::class` now in middleware stack (`ComplianceServiceProvider.php` line 106).
  - (b) `can:compliance.view_reprint_log` now applied per-route on both `/audit/events` and `/audit/anomalies` (lines 110–113).
  - (c) `getCompanyId()` removed; `index()` (line 40) and `anomalies()` (line 86) both call `$this->companyContext->requireCompanyId()`. The new `CompanyContextMiddleware` verifies membership before populating context.
  - **Caveat:** the fix is scope-correct for what was asked, but it introduces / preserves a new leak via the unscoped service helper called from the now-permission-gated controller (see Finding A below).

- Finding 3 (FraudSettingsController structural-upstream-protection annotation): deferred honestly. Commit message acknowledges "deferred to a later inventory pass" and references the schema gap (`company_fraud_settings` has no `tenant_id` column). FraudSettingsController itself is well-isolated via `CompanyContext->requireCompanyId()` on every method.

- Finding 4 (api.audit cluster missing): addressed honestly. AuditController is folded into api.compliance via this fix. The deferred service-tier scope claim is *partly* honest (AnomalyDetectionService, AuditService::getEventsForCompany/ByType/InRange remaining work) but glosses over `getEventsForAggregate` which is reachable from the in-scope controller — see Finding A.

## New findings (round 2)

### Finding A — REQUEST-CHANGES — AuditController.index() leaks cross-tenant via getEventsForAggregate

**File:** `apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php` lines 48–52
**File:** `apps/api/app/Modules/Compliance/Services/AuditService.php` lines 80–86

`AuditController::index()` resolves `$companyId` from `CompanyContext->requireCompanyId()` (line 40), but on the `aggregate_type && aggregate_id` branch (line 48) it dispatches into:

```php
$events = $this->auditService->getEventsForAggregate(
    (string) $aggregateType,
    (string) $aggregateId
);
```

`AuditService::getEventsForAggregate` (lines 80–86) executes `AuditEvent::where('aggregate_type', ...)->where('aggregate_id', ...)->orderBy('occurred_at')->get()` — **no `company_id` predicate, no `tenant_id` predicate**. The resolved `$companyId` is unused on this branch.

**Attack vector:** tenant-A admin/owner with `compliance.view_reprint_log` sends `GET /api/v1/audit/events?aggregate_type=Document&aggregate_id=<tenant-B-document-uuid>`. The aggregate UUID can be obtained from any tenant-B leaked URL, public order link, support-ticket attachment, screenshot, or by enumeration. The response returns the full audit-event payload + metadata for that tenant-B aggregate (event_type, payload, metadata, user_id, event_hash, occurred_at).

**Why this is in-scope:** the round-1 verdict and round-2 fix both classify `AuditController` as in-scope cluster work. The controller's dispatch table now includes a code path that the can-gate alone does not protect (cluster invariant: BOTH `tenant_id` AND `company_id` predicates regardless of role). The deferred-scope justification ("AuditService is a future api.audit-events cluster") only applies to read methods *not reachable from in-scope controllers*; `getEventsForAggregate` IS reachable from this round's in-scope controller.

**Required fix (one of):**
1. Add `string $companyId` to `getEventsForAggregate` signature and predicate the query on `company_id` (preferred — defense-in-depth tenant_id is also recommended).
2. OR explicitly disable the `aggregate_type && aggregate_id` branch in `AuditController::index()` until the service-tier sweep lands (e.g., `abort(501, 'Aggregate query path deferred to api.audit-events cluster')`).
3. OR add a regression test that proves cross-tenant `getEventsForAggregate` is blocked AND a follow-up YAML row tracking the service-tier sweep as a hard prerequisite for unblocking.

**Test gap:** no test in `ComplianceCrossTenantHardeningTest.php` exercises the `aggregate_type` + `aggregate_id` branch. The existing `test_audit_events_legacy_route_resolves_company_from_context_not_header` only hits the no-query-param branch, which routes through `getEventsForCompany` (correctly scoped). Add a test asserting that an `aggregate_type=Document&aggregate_id=<tenant-B-uuid>` query returns no rows for tenant-A admin.

### Finding B — NICE-TO-HAVE — Permission semantics: compliance.view_reprint_log is overloaded

**File:** `apps/api/app/Modules/Compliance/Providers/ComplianceServiceProvider.php` lines 110–113

`compliance.view_reprint_log` is documented in `RolesAndPermissionsSeeder.php` line 285 as a fiscal NF525 permission ("Compliance / NF525"). Reusing it as the gate for `/audit/events` and `/audit/anomalies` is a semantic stretch — audit events span document.created, vehicle.owner_changed, etc., not just NF525 reprints. The commit message acknowledges this ("semantically a fiscal audit-log read; same set of roles already authorized for the NF525 audit log") and chose it for role-coverage convenience.

This is functionally correct (admin/owner roles have it; cashiers don't) and the test `test_audit_events_legacy_route_requires_can_compliance_view_reprint_log` proves it works. But long-term the permission should be `audit-events.view` or similar, and the audit/* routes should ideally retire to a dedicated `api.audit-events` cluster with proper permission separation (NF525 reprint log vs domain audit events are different concerns). Track in MEMORY.md / api-cluster-certification YAML; do not block this round.

### Finding C — NICE-TO-HAVE — Test for query-string company_id ignore is structurally weak

**File:** `tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php` lines 177–194

`test_reprint_log_ignores_cross_tenant_query_company_id` asserts only that the response is 200 with the correct JSON structure — it does NOT prove tenant-B reprint rows are excluded, because no tenant-B reprint rows are seeded. The test comment honestly admits this: "without seeding cross-tenant reprint rows we can only assert the non-failure path." A stronger test would seed a `ReceiptPrint` row for tenant B and assert it does not appear in tenant A's response. Recommend tightening in a follow-up; not blocking.

## Audit exhaustiveness

- Hostile-grep result count: 1 in `app/Modules/Compliance/` for `header.*X-Company-Id` / `input('company_id')` / `query('company_id')` — and that 1 is a docblock comment in `Nf525ExportController.php` line 32 explaining the prior anti-pattern. No production-code instances remain.
- Sibling controllers in `Compliance/Presentation/Controllers/` (FraudAlertController, FraudSettingsController) verified to use `CompanyContext` exclusively. FraudAlertController uses tenant_id+company_id dual predicate (cluster invariant compliant). FraudSettingsController uses company_id only — schema-level gap acknowledged in Finding 3 deferral.
- Service-tier reachable-leak audit: `AuditService::getEventsForAggregate` is the only reachable unscoped read from in-scope controllers. `AnomalyDetectionService` reads (lines 65, 119, 155, 198, 219, 279) all use `company_id` predicate but lack tenant_id — defense-in-depth gap deferred to api.audit-events cluster honestly.
- `Nf525DataProvider` (POS module) uses `company_id` predicate for terminal/receipt reads (lines 77, 106, 121, 135, 150) — within cluster invariant baseline; tenant_id defense-in-depth deferred to a POS-cluster sweep.
- Tests: `vendor/bin/phpunit tests/Feature/Compliance` → 110 tests / 471 assertions OK (4 PHPUnit deprecations pre-existing; 5 skipped; 0 fails). The new `ComplianceCrossTenantHardeningTest` class adds 5 tests / 15 assertions, all green.
- PHPStan: targeted analysis on the 4 changed files clean — `[OK] No errors`. The implementer's commit-message claim about pre-existing errors at `Nf525JetExportTest.php` lines 102/145/174 is honest (they are not on lines this commit touched and were not introduced by this round).
- Pint: clean (use ordering applied per commit message; no further drift).
- POS surface: legacy audit routes are `/api/v1/audit/events` and `/api/v1/audit/anomalies`. Neither is invoked from the React POS surface (`apps/web/src/`). NF525 endpoints are admin-dashboard-only. POS surface diff dev..HEAD is empty as the commit message claims.
- Frontend API contract: dropping `company_id` from the export-jet/verify-chains/reprint-log validators is silently breaking for any client that sent `company_id` in body/query — but Laravel's `validate()` would have errored on missing required `company_id` before this fix, so any working client must already be sending the X-Company-Id header (CompanyContextMiddleware enforces it). Behavior change is purely additive (extra body field is silently ignored, no longer required).

## Confidence

High confidence on Finding A (verified by reading source line-by-line and confirming the unscoped query in `AuditService::getEventsForAggregate`; the controller dispatch is unambiguous). The cluster invariant from Treasury Finding 14 is unambiguous: read whose anchor came from a route/query param requires both `tenant_id` AND `company_id` predicates. The aggregate_id query parameter is the anchor on this branch and there is no tenant predicate.

High confidence on Findings 1, 2, 3, 4 closure verdicts (verified by line-by-line read of the four changed files and the new test).

Medium confidence on Finding B/C nice-to-haves — these are stylistic/test-quality concerns that don't block but should be tracked.

Recommended next step: implementer adds the `companyId` argument to `AuditService::getEventsForAggregate` and adds the corresponding regression test. Once that lands, the cluster will be approve-grade.
