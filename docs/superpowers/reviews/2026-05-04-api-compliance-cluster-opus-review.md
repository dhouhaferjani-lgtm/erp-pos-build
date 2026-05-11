# Opus adversarial review — api.compliance cluster (round 1)

Review date: 2026-05-04
Branch tip reviewed: 251c93d2
Reviewer: opus (first-layer adversarial review)

Verdict: REQUEST-CHANGES
Commit reviewed: 251c93d2

## Summary

The 5 inventoried callsites in `FraudAlertController` are correctly fixed and well-tested (7 tests / 23 assertions pass; PHPStan clean; Pint clean; POS surface diff empty for the production commit). The cluster invariant — BOTH `tenant_id` AND `company_id` predicates on every read whose anchor came from a route param — is honored on all four `findOrFail` paths (`show` / `assign` / `dismiss` / `resolve`), and the `assigned_to` validator is correctly tightened to `ScopedExists::tenant('users', $tenantId)` (single predicate is honest because `users` table has only `tenant_id` — confirmed against the migration at `database/migrations/2025_11_30_000003_create_users_table.php`). Defense-in-depth on `index()` and `statistics()` is well executed, with the `$base()` factory closure pattern eliminating per-line scope-drift risk on the seven `count()` calls.

The structural-SQL-log invariants on `show()` and the `assign()` validator are bar-raising and the filtering predicates (`from "fraud_alerts"` + `"id" =` + NOT `count(*)`) correctly isolate the production read query from eager-load side-channels.

**However, the deferred-list judgment is dishonest in two material respects.** Two sibling controllers in the same Compliance Presentation/Controllers/ directory (Nf525ExportController, AuditController) are NOT in the inventory because the AST scanner only catches `unscoped_eloquent_findOrFail` and bare `exists:` patterns — they use a different (worse) anti-pattern: trusting `$request->input('company_id')` / `$request->header('X-Company-Id')` without verifying the company belongs to the user's tenant or that the user has membership. The implementer's defer-list explicitly calls out CLI commands (`VerifyFiscalChainsCommand`, `BackfillFiscalHashesCommand`) and a non-existent `api.audit` cluster but does NOT mention the Nf525 HTTP controller. These belong in api.compliance — same module, same Presentation/Controllers/ folder, same routes file (Nf525) or same provider (Audit).

## Findings

### 1. [REQUEST-CHANGES — high] Nf525ExportController trusts `$request->input('company_id')` without tenant scope

File: `apps/api/app/Modules/Compliance/Presentation/Controllers/Nf525ExportController.php`
Lines: 49, 76, 150 (each method takes `company_id` from request body)
Routes: `POST /api/v1/compliance/nf525/export-jet` (line 60 of `routes.php`), `POST /api/v1/compliance/nf525/verify-chains` (line 64), `GET /api/v1/compliance/nf525/reprint-log` (line 68)

The controller validates `company_id` as `required|uuid` only. It never checks that the provided UUID belongs to the authenticated user's tenant or that the user has a `UserCompanyMembership` for that company. The `Nf525DataProvider::buildExportSnapshot` / `listTerminalsForCompany` / `fetchReprintLog` implementations downstream all filter by `company_id` only (see `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` lines 77, 106, 121, 135, 150 — `Receipt::where('company_id', $companyId)…` with no tenant predicate).

Attack vector: a tenant-A admin holding `compliance.export_jet` (granted by `SetPermissionsTeam` for tenant A only) can submit `{"company_id": "<tenant-B-company-uuid>", "from": "...", "to": "..."}` and exfiltrate tenant B's full NF525 fiscal export — receipts, voids, returns, training rows, reprints, Z-reports, shifts, cash-drawer ops. This is a CRITICAL fiscal-data cross-tenant leak, the highest-stakes domain in the codebase (NF525 chain integrity is the compliance product).

Why this didn't trip the scanner: `php_ast_find` was looking for `unscoped_eloquent_findOrFail` and bare `exists:`. Trusting a request-input company_id is a third pattern the inventory generator did not encode.

Fix (Treasury template): replace `$companyId = (string) $request->input('company_id');` with `$companyId = $this->companyContext->requireCompanyId();` (DI `CompanyContext`), drop the `company_id` field from the validator (or change it to a soft default for backwards compat that is overridden), and add a regression test that asserts cross-tenant `company_id` in body is rejected (404 / 403 / silent override to caller's company — pick one). All three methods (`exportJet`, `verifyChains`, `reprintLog`) need the same fix.

This is in scope of api.compliance — same module, same Presentation/Controllers/ directory, same `routes.php` file (registered alongside FraudAlertController). Adding three callsites + regression tests to the cluster does not exceed Treasury's precedent.

### 2. [REQUEST-CHANGES — high] AuditController has no `SetPermissionsTeam` middleware, no permission gate, and trusts X-Company-Id

File: `apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php`
Routes registered at `apps/api/app/Modules/Compliance/Providers/ComplianceServiceProvider.php` lines 95-100 (legacy "audit/events" + "audit/anomalies" routes — `Route::middleware(['api', 'auth:sanctum'])` only).

Three structural defects:
1. Routes lack `SetPermissionsTeam` middleware — violates CLAUDE.md rule 12 ("Module Routes Must Follow Middleware Pattern: `['api', 'auth:sanctum', SetPermissionsTeam::class]`").
2. Routes have no `can:` gate — any authenticated user can read audit events.
3. `AuditController::getCompanyId` (lines 102-126) reads `X-Company-Id` header directly without going through `CompanyContext->requireCompanyId()`. While `CompanyContextMiddleware` would set the context for non-legacy routes, this controller bypasses it. A request with header `X-Company-Id: <foreign-company-uuid>` is taken at face value; the fallback path queries `$user->companyMemberships()->first()` but the header path does not validate.

Combined with `AuditService::getEventsForCompany` (line 67-69 of AuditService.php — `AuditEvent::where('company_id', $companyId)` with no tenant predicate), this is the same cross-tenant audit-event leak as Finding #1.

The implementer deferred this to a "future api.audit cluster". That cluster does NOT exist in `tenant-isolation-sweep-inventory.yml` (only `api.console-commands` is listed). This is in scope of api.compliance — controller lives in `Compliance/Presentation/Controllers/`, registered by `ComplianceServiceProvider`.

Fix: add `SetPermissionsTeam::class` and a `can:audit-events.view` (or appropriate) gate to both routes, replace `getCompanyId` with `$this->companyContext->requireCompanyId()` (inject CompanyContext via constructor), and confirm `audit_events` table has `tenant_id` (if so, scope reads by tenant_id; if not, document via `structurally_protected_by_upstream_guard` annotation in inventory after the middleware fix lands).

### 3. [NICE-TO-HAVE — info] FraudSettingsController bare `where('company_id', …)` is upstream-protected — please annotate

File: `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php`
Lines: 54, 101, 120-122, 168-171

This controller's reads/writes filter on `company_id` only. I verified the `company_fraud_settings` migration: the table has NO `tenant_id` column (line 5 of `2025_12_23_160000_create_company_fraud_settings_table.php` — `$table->uuid('company_id'); ... $table->unique('company_id');`). So a tenant predicate cannot be added without a schema migration.

The reads ARE upstream-protected because the `$companyId` flows from `CompanyContext->requireCompanyId()`, which `CompanyContextMiddleware` populates only after `userHasAccessToCompany` returns true (`apps/api/app/Http/Middleware/CompanyContextMiddleware.php` line 67-74). This is the same `structurally_protected_by_upstream_guard` pattern annotated for `api.treasury.027/028/030`.

Not a fix-required finding — but the cluster's submit-state YAML should annotate FraudSettingsController callsites with `structurally_protected_by_upstream_guard` rather than silently leaving them off the inventory, OR a follow-up should track the schema migration to add `tenant_id` to `company_fraud_settings` for defense-in-depth parity with `fraud_alerts`. Today's commit message addresses this in passing ("CompanyFraudSettingsRepository / FraudSettingsController / FraudAlertNotificationService — bare CompanyFraudSettings reads; defer to a future api.compliance.fraud-settings sweep") which is at least transparent, but the deferred sweep should be tracked as a known followup with a stable id, not a vague reference.

### 4. [NICE-TO-HAVE — info] Inventory should track that `api.audit` is a missing cluster

The defer-list says `AuditEvent scope / AuditService / AnomalyDetectionService — defer to a future api.audit cluster`. There is no `api.audit` row in `tenant-isolation-sweep-inventory.yml`. Either fold AuditController into api.compliance (preferred — same module) or add a real `api.audit` cluster row to the inventory so the deferred work is tracked, not just hinted at.

## Audit exhaustiveness

- Hostile-grep result count: 13 matches in Compliance/Presentation/Controllers/, all of which I verified by hand:
  - 12 in FraudAlertController — all carry BOTH `tenant_id` + `company_id` predicates (CLEAN).
  - 1 in FraudSettingsController line 54 — bare `company_id` only, on a `company_fraud_settings` table that has no tenant_id column (Finding #3).
  - 0 false positives.
- Categorization:
  - Route-anchored findOrFail (5 inventoried callsites): all FIXED with both predicates leading the Builder before findOrFail.
  - Defense-in-depth `index()` + `statistics()`: scoped correctly with `$base()` factory closure (good pattern; mirrors Treasury template).
  - assigned_to validator: `ScopedExists::tenant('users', $tenantId)` — honest single predicate (users table lacks company_id; verified against migration).
  - Sibling Presentation/Controllers/ files NOT in inventory: Nf525ExportController (Finding #1) and AuditController (Finding #2) trust client-supplied company_id with no tenant scope. FraudSettingsController is upstream-protected (Finding #3, info-only).
- Tests run:
  - `vendor/bin/phpunit tests/Feature/Compliance/FraudAlertTenantIsolationTest.php` → 7 tests / 23 assertions OK (00:07.894s).
  - Cross-tenant findOrFail tests cleanly return 404 (no 500s from empty-Builder anomalies).
  - assign validator test asserts both `'error.code' = VALIDATION_ERROR` and `assigned_to` key in errors array — correctly identifies the validator as the rejecter.
  - Same-tenant control tests on every cross-tenant test (200 / 200 / 200 / 200 / 200) — production code path is exercised, not a cache or 404-by-default.
- PHPStan: `[OK] No errors` on `app/Modules/Compliance` + new test file.
- Pint: `{"result":"pass"}` on the same scope.
- POS surface diff for commit 251c93d2 only: empty.

## Confidence

High confidence on the 5 inventoried callsites: the implementation is correct, well-tested, and the structural-SQL-log invariants close the future-regression door cleanly. The Treasury template is faithfully applied.

High confidence on Finding #1 (Nf525ExportController) and Finding #2 (AuditController) being real cross-tenant data leaks. I traced the data path end-to-end: HTTP entry → controller → service → SQL. Both end in `where('company_id', $companyId)` with no tenant predicate and no upstream check on the company_id provenance (request-body or unverified header). Both controllers live in `Compliance/Presentation/Controllers/` and ARE in scope of api.compliance.

The verdict is REQUEST-CHANGES because Findings #1 and #2 are missed in-scope cross-tenant gaps that the implementer's defer-list does not explain (Finding #1 is not mentioned at all; Finding #2 is deferred to a non-existent cluster). Either fix them in this cluster, or amend the inventory to register them as separate tracked clusters with explicit ids — silent omission is the wrong move.

The two findings should be small-to-medium fixes (constructor-inject CompanyContext, drop request-body company_id or override it, add SetPermissionsTeam + can: gate, add 2-3 regression tests per controller). On par with the FraudAlertController fix that just landed.

cc8cda38 (workflow YAML state) is mechanical and consistent with the production commit; no concerns there.
