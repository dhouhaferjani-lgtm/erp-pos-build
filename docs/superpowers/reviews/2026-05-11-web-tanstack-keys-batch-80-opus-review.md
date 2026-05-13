# web.tanstack-keys Batch 80 — Opus Review

Commit reviewed: 7efb658b
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `7efb658b` — fix(tenant-isolation): wrap web.tanstack-keys batch 80 (settings locations roles)
Scope: 10 callsites across 2 production files.
- `LocationsPage.tsx` (.629 read locations, .630-.633 4 invalidate-locations predicate calls on create/update/delete/setDefault)
- `RolesPage.tsx` (.634 read roles, .635 read permissions, .636-.638 3 invalidate-roles predicate calls on create/update/delete)

Test: `LocationsPage.tenantScope.test.tsx` + `RolesPage.tenantScope.test.tsx` (new, 191+197 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 3 reads use `tenantScopedKey([...])`. All 7 invalidate sites use `scopedNamespacePredicate(namespace, ...)`.
2. **State-value selectors**: Both pages select `tenantId`/`companyId`.
3. **Enabled gates**: All 3 reads AND-combine `tenantId !== null && companyId !== null`.
4. **Async invalidate**: All 7 mutation onSuccess handlers `async/await`.

## Non-blocking findings

1. `scopedNamespacePredicate` duplicated in both files. Tracked.

## Locks applied

10 callsites locked at fix commit `7efb658b`:
web.tanstack-keys.629, .630, .631, .632, .633, .634, .635, .636, .637, .638.
