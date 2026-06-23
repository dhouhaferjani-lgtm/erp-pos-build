# M-6 Opus Adversarial Review — Scheduled Subledger Reconciliation Alert Command

Date: 2026-06-23
Commit: 15aa81e1c
Item: M-6 (`accounting:check-subledger-reconciliation`)
Reviewer: Opus adversarial pass (refutation mandate; prior gate was Codex-only + Opus-unavailable fallback)

## Summary

The command is well-typed, alert-only (no repair), reports the required `entries_without_partner` field, and is wired into the scheduler. PartnerBalanceService money handling is bcmath-clean. However, the command **never enters the per-tenant database context** before querying tenant-scoped tables (`companies`, `journal_entries`, `accounts`, `partners`). In db-per-tenant production mode (the live mode since 2026-05-28) this means the daily job runs against the central database, where those tables do not exist. The feature test passes only because the test runtime uses a single shared SQLite connection with `tenancy_resolver.db_per_tenant` OFF — a textbook false-confidence test. The acceptance criterion ("scans configured companies/purposes and reports discrepancies") is therefore unmet in production.

## BLOCKER

None strictly fiscal/money-mutating (the command is alert-only and does not write balances). See HIGH-1 — it is borderline-BLOCKER because the feature is non-functional in production, but it is an alerting job and the root cause is a shared base-class gap, so it is rated HIGH.

## HIGH

### HIGH-1 — Command queries tenant-DB tables without initializing tenancy; non-functional in db-per-tenant production
The closure passed to `forEachTenant()` runs per-tenant work but the connection is never swapped to the tenant database:

`apps/api/app/Modules/Accounting/Presentation/Console/CheckSubledgerReconciliationCommand.php:48`
```php
$exit = $this->forEachTenant(function (Tenant $tenant) use ($purposes, &$discrepancies): int {
    $companies = Company::query()
        ->where('tenant_id', $tenant->id)
        ->where('status', CompanyStatus::Active)
        ->get();
```

`forEachTenant()` does NOT initialize tenancy / swap the DB connection — it only iterates `Tenant::all()` and invokes the closure on the still-active default (central) connection:

`apps/api/app/Console/TenantScopedCommand.php:121`
```php
protected function forEachTenant(callable $fn): int
{
    $aggregate = self::SUCCESS;
    foreach (Tenant::all() as $tenant) {
        $exit = $fn($tenant);
        ...
```

Under db-per-tenant (the live model per CLAUDE.md and `docs/.../2026-06-22-...autonomous-session.md:44`), tenant-scoped tables live only in `tenant_<uuid>` databases. Confirmed: `companies`, `journal_entries`, `accounts` exist ONLY in `apps/api/database/migrations/tenant/` (none in `apps/api/database/migrations/`). The only correct way to read tenant data is `$tenant->run(...)` / `tenancy()->initialize($tenant)`, which fires `DatabaseTenancyBootstrapper` to swap the default connection (`apps/api/app/Providers/TenancyServiceProvider.php:25-53`). The command does neither.

Consequences in production (db_per_tenant ON):
- `Company::query()` runs against the central DB which has no `companies` table → a PostgreSQL "relation companies does not exist" error. That `QueryException` is not caught (only `ModelNotFoundException` is caught at line 60), so the scheduled job aborts on the first tenant. The daily reconciliation alert silently fails every night.
- Even if the query somehow returned rows, `reconcileSubledger()` joins `journal_lines`/`journal_entries`/`accounts`/`partners` (`PartnerBalanceService.php:152-213`), all tenant-DB tables — so it would inspect the wrong (empty/absent) database and never detect a real discrepancy.

This is a systemic pre-existing gap shared by sibling per-tenant-iter commands (`CheckExpiringCertifications`, `ScheduleAppointmentReminders`, `ExpireHeldOrdersCommand`), but M-6 newly relies on it for tenant-DB GL data, so the feature as delivered does not work in production. [NEEDS-REAL-PG] to confirm the exact failure mode (error vs. empty) on a flipped multi-DB instance.

### HIGH-2 — Test gives false confidence; it cannot exercise the production code path
`apps/api/tests/Feature/Accounting/SubledgerReconciliationCommandTest.php` runs with `RefreshDatabase` on the default SQLite connection and never sets `config(['tenancy_resolver.db_per_tenant' => true])`. With db_per_tenant OFF, `tenancy()->initialize()`/`$tenant->run()` are no-ops on one shared connection (`TenancyServiceProvider.php:33-36`), so `Company::query()` and the GL joins all resolve against the same DB the seeder wrote to. The test passes (verified: 2 passed, 6 assertions) even though the command would error or no-op in production. The test would still pass if the command were missing every tenancy-context call — it does not assert the one behavior that breaks in prod. No PG-mode test of the per-tenant iteration exists (`grep` shows only this Feature test + the architecture classifier reference the command).

## MEDIUM

### MED-1 — `array_unique($purposes, SORT_REGULAR)` on enum instances is fragile dedup
`CheckSubledgerReconciliationCommand.php:139`
```php
return array_values(array_unique($purposes, SORT_REGULAR));
```
`array_unique` with `SORT_REGULAR` compares enum objects by loose comparison; for backed enums this happens to work (same case → equal), but it is an implicit reliance on PHP enum identity semantics rather than `===`/spl_object_id. A clearer/strict dedup (e.g. keying by `$purpose->value`) would be unambiguous. Low functional risk, but brittle.

### MED-2 — Difference computed at scale 4 while money columns are scale 3
`PartnerBalanceService::reconcileSubledger` computes `bcsub($controlBalance, $subledgerTotal, 4)` and `is_balanced = bccomp($difference, '0', 4) === 0` (`PartnerBalanceService.php:203,216`). The underlying GL columns are decimal(N,3). Comparing at scale 4 is harmless for equality of scale-3 values, but the command prints `difference` verbatim into the alert line as a 4-dp string. This is pre-existing service behavior (not introduced by M-6) and not a correctness defect — noting for consistency only.

### MED-3 — `INVALID` exit on empty purposes is unreachable / dead branch
`executeCommand()` guards `if ($purposes === []) return self::INVALID;` (line 41), but `purposesFromOptions()` returns the non-empty default set whenever no `--purpose` is supplied, and returns `[]` only after already printing an error for an invalid value. The empty-default path cannot occur, so the guard's INVALID is effectively only reachable via the invalid-value path. Minor; harmless.

## LOW

### LOW-1 — Aggregate exit semantics vs. discrepancy FAILURE
The closure always returns `self::SUCCESS` per tenant; discrepancy signalling is done via the outer `$discrepancies` counter returning `self::FAILURE`. That is fine, but it means `forEachTenant`'s aggregate exit (`$exit`) is only surfaced when `$discrepancies === 0`. If a tenant iteration ever returned non-SUCCESS while discrepancies also existed, the discrepancy FAILURE wins — acceptable, just note the two exit-signal mechanisms coexist.

### LOW-2 — Structured-log + line duplication
Each discrepancy both `Log::warning(...)` and `$this->line(...)` with overlapping fields. Fine for alerting, slight redundancy.

## Positive findings (held up under refutation)
- Alert-only: the command never calls `refreshPartnerBalance`/`update`; no repair, no mutation. Matches scope boundary.
- Money handling in `reconcileSubledger`/`getSubledgerTotal`/`getControlAccountBalance` is bcmath throughout; no `(float)` cast, no `number_format`. Liability magnitude (`liabilityMagnitude`) correctly stores non-negative magnitude and clamps net-debit to `0.000` with a warning. Sign conventions consistent with the documented contract.
- No event renamed/restructured/deleted; no GL posting introduced; constructor injection only (no `app()` in command body). Strict types throughout; enums used for purpose/status. No cross-module model import violations beyond the already-accepted Company/Tenant domain references used by the TenantScopedCommand base pattern.

## Verdict

NEEDS-REVISION.

The deliverable is correct in shape (alert-only, right fields, scheduled, typed) but does not function in the live db-per-tenant runtime because it queries tenant-scoped tables without entering tenant context, and its sole test cannot catch this because it runs single-connection with db_per_tenant OFF. Required before this can be considered done:
1. Wrap the per-tenant work in `$tenant->run(...)` (or have `forEachTenant` do so) so the connection swaps to the tenant DB before any `Company`/GL query — and confirm sibling per-tenant-iter commands are not silently broken the same way.
2. Add a PG-mode test (`config(['tenancy_resolver.db_per_tenant' => true])`, real Postgres, ≥2 tenants) proving the command reads each tenant's own GL and does not leak/empty-read across tenant DBs.
3. The autonomous-session log and work-list mark M-6 DONE with `opus-review: PENDING`; that PENDING is now resolved as not-done-as-merged.
