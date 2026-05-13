# web.tanstack-keys Batch 23 — Opus Review

Commit reviewed: 5fdf2fd8
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-10

## Commit reviewed

Fix commit: `5fdf2fd8` — fix(tenant-isolation): wrap web.tanstack-keys batch 23 (loyalty stamp cards)
Scope: 4 callsites in `useStampCards.ts`. Same pattern as B24 (loyalty tiers) — namespace-stable `stampCardsKey(programId)` factory + 4 sites (1 read + 3 invalidate).

Test: `useStampCards.tenantScope.test.tsx` (new, 205 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 4 sites use `tenantScopedKey([...stampCardsKey(programId)])`.
2. **State-value selectors** + **Enabled gates**: Read selects/gates on `tenantId`/`companyId`. Mutation hooks rely on consumer's subscription (see B24 review for the same reasoning).
3. **Async invalidate**: All 3 mutation onSuccess `async/await`.

## Locks applied

4 callsites locked at fix commit `5fdf2fd8`:
web.tanstack-keys.368-.371.
