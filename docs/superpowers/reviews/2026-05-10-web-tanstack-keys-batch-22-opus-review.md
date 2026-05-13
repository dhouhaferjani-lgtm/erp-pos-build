# web.tanstack-keys Batch 22 — Opus Review

Commit reviewed: 8445f8c1
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-10

## Commit reviewed

Fix commit: `8445f8c1` — fix(tenant-isolation): wrap web.tanstack-keys batch 22 (loyalty rewards)
Scope: 6 callsites in `useRewards.ts`. Same idiom as B23/B24 — namespace-stable `rewardsKey(programId)` + 1 read + 5 invalidate sites (create/update/delete/activate/deactivate).

Test: `useRewards.tenantScope.test.tsx` (new, 238 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 6 sites use `tenantScopedKey([...rewardsKey(programId)])`.
2. **State-value selectors / enabled gates**: Same as B23/B24 — read subscribes, mutation hooks rely on consumer's subscription.
3. **Async invalidate**: All 5 mutation onSuccess `async/await`.

## Locks applied

6 callsites locked at fix commit `8445f8c1`:
web.tanstack-keys.362-.367.
