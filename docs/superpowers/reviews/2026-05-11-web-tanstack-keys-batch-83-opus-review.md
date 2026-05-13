# web.tanstack-keys Batch 83 — Opus Review

Commit reviewed: a6b71531
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `a6b71531` — fix(tenant-isolation): wrap web.tanstack-keys batch 83 (dashboard)
Scope: 4 callsites in `Dashboard.tsx`.
- .137 read onboarding-status, .138 read dashboard.stats, .139 read dashboard.documents, .140 read dashboard.payments

Test: `Dashboard.tenantScope.test.tsx` (new, 136 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 4 reads use `tenantScopedKey([...])`. Pure-read page, no invalidate sites.
2. **State-value selectors**: Selects `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: All 4 reads AND-combine `tenantId !== null && companyId !== null`. No prior predicate to combine with.

## Non-blocking findings

1. The page renders 4 widget queries that share the same gate predicate — could refactor into a shared hook, but out of scope.

## Locks applied

4 callsites locked at fix commit `a6b71531`:
web.tanstack-keys.137, .138, .139, .140.
