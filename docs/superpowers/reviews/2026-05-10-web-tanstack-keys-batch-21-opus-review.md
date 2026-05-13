# web.tanstack-keys Batch 21 — Opus Review

Commit reviewed: 0ec0f76b
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-10

## Commit reviewed

Fix commit: `0ec0f76b` — fix(tenant-isolation): wrap web.tanstack-keys batch 21 (loyalty programs)
Scope: 8 callsites in `usePrograms.ts`.
- .354/.355/.356 reads: usePrograms, useProgram(id), useActivePrograms
- .357-.361 invalidate on create/update/delete/activate/deactivate (all use predicate path)

Test: `usePrograms.tenantScope.test.tsx` (new, 264 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 8 sites use `tenantScopedKey([...])` for reads and `programsInvalidationPredicate(tenantId, companyId)` for invalidates.
2. **State-value selectors**: Each read hook AND each mutation hook independently subscribes to `tenantId`/`companyId`. This is more conservative than the B22-B24 pattern (where mutation hooks relied on consumer subscription).
3. **Enabled gates**: All 3 reads AND-combine `!!tenantId && !!companyId` with existing predicates.
4. **Async invalidate**: All 5 mutation onSuccess `async/await`.
5. **Dedicated predicate exported**: `programsInvalidationPredicate` is exported alongside `PROGRAMS_KEY`, allowing consumers to invalidate using the same idiom. Cleaner than the inlined helper later batches adopt.

## Non-blocking findings

1. The 5 mutation hooks each subscribe their own selectors — works but creates extra subscriptions. A shared internal hook would dedupe. Out of scope.
2. The exported `programsInvalidationPredicate` is dedicated to this namespace; a generic `scopedNamespacePredicate('loyalty-programs', ...)` would also work but loses the namespace pin. Both are valid.

## Locks applied

8 callsites locked at fix commit `0ec0f76b`:
web.tanstack-keys.354-.361.
