# api.console-commands Cluster — Claude Review

Cluster: `api.console-commands`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Artisan commands that iterate cross-tenant data: NF525 export job, appointment reminders, certification expiry checks. Pre-sweep, console commands ran in an unauthenticated context with `Auth::user() === null` and could read or mutate across tenants if predicates were not explicit.

Inventory callsite total: **3 / 3 fixed**.

## Implementation summary

Fix commit: `0f51492f` (all 3 callsites).

Top files: `ExportNf525JetCommand`, `ScheduleAppointmentReminders`, `CheckExpiringCertifications`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Console/ConsoleCommandsTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-07-api-console-commands-cluster-codex-review.md`
- `2026-05-07-api-console-commands-cluster-codex-round2-review.md`

## Gates evaluated

1. **Explicit per-tenant iteration**: commands iterate `Tenant::all()` then `TenantContext::bind($tenant->id)` before per-tenant work.
2. **No `Auth::user()` reliance**: all model lookups carry explicit `tenant_id` + `company_id` predicates.
3. **Audit trail**: each command logs the tenant iteration boundary for post-run review.

## Disposition

APPROVE for master PR.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/Console/ConsoleCommandsTenantIsolationTest.php`
