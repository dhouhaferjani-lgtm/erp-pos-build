# web.tanstack-keys Batch 38 — Opus Review

Commit reviewed: 414ba72b
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `414ba72b` — fix(tenant-isolation): wrap web.tanstack-keys batch 38 (reports pages)
Scope: 5 callsites across 2 files.
- `ReportsPage.tsx` (.586-.589 reads: documents, partners, products, payments)
- `pages/AgedReceivablesPage.tsx` (.590 read aged-receivables)

Test: `reports.tenantScope.test.tsx` (new, 149 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 5 reads use `tenantScopedKey([...])`.
2. **State-value selectors**: Both pages select `tenantId`/`companyId` with `?? null` and compose `hasTenantScope` boolean.
3. **Enabled gates**: All 5 reads use `enabled: hasTenantScope`. No prior predicate to combine with.

## Non-blocking findings

1. The `hasTenantScope` boolean composition is a cleaner pattern than `tenantId !== null && companyId !== null` inline; subsequent batches kept the inline form. Mild inconsistency.

## Locks applied

5 callsites locked at fix commit `414ba72b`:
web.tanstack-keys.586-.590.
