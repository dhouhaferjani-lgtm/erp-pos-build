Commit reviewed: 9387e775
Verdict: APPROVE

## Round-1 Closure

[F1] closed at 9387e775. The cascade test `invalidating with posShiftInvalidationPredicate cascades to ALL shift entries` in `apps/web/src/pages/POS/__tests__/tenantScope.test.tsx` now declares three per-call counters (`term1Calls`, `term2Calls`, `pmCalls`) initialised to 0. Each respective `queryFn` closure increments its counter before returning counter-derived data (e.g. `` `shift-1-call-${term1Calls}` ``). Post-invalidate assertions verify `term1Calls === 2`, `term2Calls === 2`, `pmCalls === 1`, and that the leaf query data equals `'shift-1-call-2'` / `'shift-2-call-2'`. An always-false predicate would leave both shift counters at 1, deterministically failing the `=== 2` assertions. F1 is closed.

## New Findings (if any)

None.

## Verification Run

```
# F1 closure — read tenantScope.test.tsx
# Confirmed: term1Calls, term2Calls, pmCalls counters increment in queryFn closures;
# post-invalidate assertions: term1Calls === 2, term2Calls === 2, pmCalls === 1;
# leaf data assertions: 'shift-1-call-2', 'shift-2-call-2'.
# Mental check: always-false predicate → shift counters stay at 1 → === 2 fails. ✓

node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
# → 816 (no regression)

cd apps/web && pnpm test --run 2>&1 | tail -40
# → all suites pass (12/12 batch 3, 9/9 batch 1, 13/13 batch 2, 4/4 queryKeyNamespace, 9/9 tenantScopedKey foundation)

cd apps/web && pnpm typecheck 2>&1 | tail -20
# → no TypeScript errors

# Hostile-grep: no stale assertions, no copy-paste errors, no wrong counter names,
# no off-by-one in expected counts found in test file or production files changed at 9387e775.
```
