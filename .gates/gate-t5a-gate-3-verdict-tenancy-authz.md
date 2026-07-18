GATE VERDICT: REJECT

The implementation is tenancy-correct everywhere I could trace it. The rejection rests on a coverage gap, not a defect in shipped behaviour.

## What I verified as correct (no findings)

**1. Route middleware and permission split — clean.** All four routes sit inside the single existing Treasury group (`routes.php:32`) inheriting `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`. No parallel group. Split is exactly per spec: `clear-outbound` guards clear/bounce/represent (`routes.php:146,150,154`); `cancel-outbound` separately guards cancellation (`routes.php:158`).

**2. Permission catalog and role grants — correct.** Both permissions created (`RolesAndPermissionsSeeder.php:224-225`). Admin gets them via `syncPermissions(Permission::all())` (line ~452). Accountant receives both (line 706). Manager receives **neither** — its instrument list (line 482) was deliberately left untouched. Confirmed by reading the role blocks, not by grep alone.

**3. Malformed UUID and tenant scope — correct.** `findInstrument` (`PaymentInstrumentController.php:446-457`) calls `Str::isUuid` → `abort(404)` *before* any query, so no malformed value reaches a PostgreSQL `uuid` comparison. Scope is `tenant_id` + `company_id` from `companyContext->requireCompany()`.

**4. No payload-derived identity.** Tenant/company come from `CompanyContext`, never the request body; the actor is `$request->user()` with a 401 guard (`:436-443`). The service re-scopes independently under `lockForUpdate` (`OutboundInstrumentService.php:62-67`).

**5. Inbound → 422.** Direction guard at `OutboundInstrumentService.php:97-98` throws `DomainException` → `domainError()` returns 422 (`:459-467`). I specifically probed whether the idempotent-replay short-circuit at `:84-95` could bypass the direction guard that follows it at `:97` — it cannot: the `instrument:{id}:clear:{cycle}` key is written *only* by this service after the guard passes, and the sole other `action_key` writer uses a distinct `:issue` key (`PaymentController.php:1017`). Not a finding.

**6. Deferred-supplier lookups — scoped.** Repository (`PaymentController.php:987-990`) and instrument re-fetch (`:1010-1015`) both filter tenant+company. `OutboundRepositoryValidator` re-validates tenant, company, type, active, GL account, currency, and bank match, each as a canonical 422.

**7. Console context — correct.** Both commands extend `TenantScopedCommand`, iterate tenants then companies explicitly, and never read request `CompanyContext`. Scale uses `getScaleSafe($company->currency, 3)` / `$repository->currency` (rule 19 — no bare no-arg `getScale()`). Notification recipients are `forCompany($tenant_id, $company_id)`, so no cross-company mixing.

**8. Deploy hazard already recorded.** The seeder re-sync + per-tenant `permission:cache-reset` (tenant-blind Spatie cache → otherwise silent 403 for existing accountants) is captured in the plan at line 25, with the checklist correctly deferred to Wave 4. Not a Wave 3 miss.

## Findings

**Important — no cross-company/cross-tenant deny test on the four new mutating endpoints.**
`tests/Feature/Treasury/OutboundInstrumentEndpointsTest.php:54-55` builds exactly one tenant and one company. Every test acts within it. There is no case asserting that an instrument belonging to a *different* company returns 404. Failure scenario: a future refactor of `findInstrument` (`PaymentInstrumentController.php:446-457`) that drops the `company_id` filter — or a switch to implicit route-model binding on `{instrument}` — silently opens cross-company disclosure *and mutation* on four endpoints, one of which reopens AP, with no test failing. The scoping is right today; nothing pins it. Contrast `InstrumentMaturityAlertsTest.php:111-122`, which does build a second company and asserts isolation — that is the standard this file should meet.

**Minor — manager deny asserted on only 2 of 4 routes.**
`OutboundInstrumentEndpointsTest.php:117-126` asserts 403 for clear-outbound and cancel-outbound. `bounce-outbound` and `represent` are unasserted. They share `can:instruments.clear-outbound` (`routes.php:150,154`), which I verified by reading, so this is coverage tightness rather than an exposure.

I found no vacuous allow-only authorization (the manager 403s are real `assertForbidden`), no test weakening, and no SQLite-only behaviour that would diverge on PostgreSQL — the reported dual-engine evidence is consistent with the 5 test methods present.

VERDICT: spec ✅ + quality CHANGES-REQUESTED

Before Wave 4: add a cross-company deny case to `OutboundInstrumentEndpointsTest.php` — create a second company, put an outbound instrument under it, and assert 404 from all four endpoints for a user scoped to the first company; while there, extend the manager 403 assertions to `bounce-outbound` and `represent`.
