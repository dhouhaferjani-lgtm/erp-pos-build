# web.tanstack-keys Batch 79 — Opus Review

Commit reviewed: bf0d9839
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `bf0d9839` — fix(tenant-isolation): wrap web.tanstack-keys batch 79 (instrument detail)
Scope: 6 callsites in `InstrumentDetailPage.tsx`.
- .670 read instrument, .671 read repositories
- .672/.673/.674/.675 invalidate instrument on deposit/clear/bounce/transfer

Test: `InstrumentDetailPage.tenantScope.test.tsx` (new, 216 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 6 sites use `tenantScopedKey([...])` for exact keys (no predicate needed — single document detail view).
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: Both reads AND-combine `tenantId !== null && companyId !== null` with `Boolean(id)`.
4. **Async invalidate**: All 4 mutation onSuccess handlers `async/await`.

## Locks applied

6 callsites locked at fix commit `bf0d9839`:
web.tanstack-keys.670, .671, .672, .673, .674, .675.
