# api.scheduled-jobs Cluster — Claude Review

Cluster: `api.scheduled-jobs`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Queue job tenant anchoring — specifically the import-processing jobs (`ProcessImportJob`, `ProcessProductImageImport`) that run asynchronously after a user-initiated import. Pre-sweep, jobs serialized to the queue could re-hydrate without restoring tenant context, leading to cross-tenant operations on import outputs.

Inventory callsite total: **2 / 2 fixed**.

## Implementation summary

Fix commit: `b059bb2c` (both callsites).

Top files: `ProcessImportJob`, `ProcessProductImageImport`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/ScheduledJobs/ScheduledJobsTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-07-api-scheduled-jobs-cluster-codex-review.md`
- `2026-05-07-api-scheduled-jobs-cluster-codex-round2-review.md`

## Gates evaluated

1. **Job constructor captures tenant + company IDs explicitly**: `ProcessImportJob::__construct(string $tenantId, string $companyId, ...)` serializes the scope into the queue payload.
2. **Job handle() rebinds context**: `TenantContext::bind($tenantId)` and `CompanyContext::bind($companyId)` invoked at the top of `handle()` before any model interaction.
3. **No reliance on `Auth::user()` mid-handle**: the user who initiated the import may not be the queue worker's runtime context.

## Disposition

APPROVE for master PR.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/ScheduledJobs/ScheduledJobsTenantIsolationTest.php`
