# web.tanstack-keys Batch 24 — Opus Review

Commit reviewed: c001e1cd
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-10

## Commit reviewed

Fix commit: `c001e1cd` — fix(tenant-isolation): wrap web.tanstack-keys batch 24 (loyalty tiers)
Scope: 4 callsites in `useTiers.ts`.
- .372 read tiers, .373/.374/.375 invalidate on create/update/delete

Test: `useTiers.tenantScope.test.tsx` (new, 200 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 4 sites use `tenantScopedKey([...tiersKey(programId)])`. The `tiersKey` helper is exported and namespace-stable across the three mutation hooks.
2. **State-value selectors**: `useTiers` selects `tenantId`/`companyId`. The three mutation hooks (`useCreateTier`/`useUpdateTier`/`useDeleteTier`) do not subscribe — they read fresh state via `tenantScopedKey`'s internal `getState()` at invalidate time. This works because the consumer component renders the read with subscribed selectors, so the cache entry under the active suffix is what gets invalidated.
3. **Enabled gate**: Read AND-combines `!!programId && !!tenantId && !!companyId`. Uses truthy-check `!!` rather than `!== null` — equivalent given store typing.
4. **Async invalidate**: All 3 mutation onSuccess handlers `async/await`.

## Non-blocking findings

1. The mutation hooks don't subscribe to auth/company stores; this is fine since the consumer's render owns the subscription. If a mutation is ever called from a context that does NOT consume a tenant-scoped read first, the invalidate would still hit the current store state (which is what we want). Pattern is sound.
2. Uses `!!tenantId` truthy-check instead of `!== null` — minor inconsistency with later batches.

## Locks applied

4 callsites locked at fix commit `c001e1cd`:
web.tanstack-keys.372, .373, .374, .375.
