Commit reviewed: b751cefe

## Axis 1 — Scanner delta (778 → 769, delta = 9)

Confirmed live. Running node apps/web/tools/audit-tanstack-keys.mjs --json returns 769 violations, confirming the 9-callsite reduction matches the 9 callsites in this batch (web.tanstack-keys.544–.552).

## Axis 2 — State-value selectors in all 3 pages

PriceListListPage.tsx: useAuthStore selector extracts tenantId and companyId via shallow-equal selector (lines ~18-22).
PriceListDetailPage.tsx: same selector pattern (lines ~20-24).
PriceListForm.tsx: same selector pattern (lines ~22-26).
All three pages use state-value selectors rather than subscribing to the full store object.

## Axis 3 — enabled guard combines pre-existing + tenant guards

PriceListDetailPage.tsx: enabled: Boolean(id) && !!tenantId && !!companyId — the pre-existing Boolean(id) guard is preserved and ANDed with the tenant guards.
PriceListForm.tsx (edit mode): enabled: isEditing && !!tenantId && !!companyId — isEditing pre-existing guard preserved.
PriceListListPage.tsx: enabled: !!tenantId && !!companyId — no pre-existing guard needed for the list query.
All combinations are correct.

## Axis 4 — Predicate matches plural namespace, rejects singular + wrong-t/c

_invalidation.ts invalidatePriceLists: uses queryClient.invalidateQueries({ predicate }) where the predicate checks key[0] === tenantId && key[1] === companyId && key[2] === 'price-lists' (plural). Singular namespace 'price-list' and mismatched tenant/company are rejected by the predicate conditions.

## Axis 5 — Singular invalidates use exact-match tenantScopedKey(['price-list', id])

PriceListForm.tsx onSuccess: calls invalidateQueries({ queryKey: tenantScopedKey(['price-list', id]) }) with exact-match — no predicate. This is correct per B3 lesson 4: when the wrap shape matches the leaf key exactly, exact-match invalidation is sufficient and avoids over-invalidation.

## Axis 6 — Mutations onSuccess async + awaited; navigate() after invalidate

PriceListForm.tsx onSuccess: async function, await invalidatePriceLists(...), await invalidateQueries(exact-match singular), then navigate(). The navigation call is correctly deferred until both invalidations settle.

## Axis 7 — Cross-tenant isolation test on plural namespace

apps/web/src/features/pricing/__tests__/tenantScope.test.tsx: test verifies that a query seeded under tenant A does not appear in results when queried under tenant B for the 'price-lists' plural namespace. The predicate-based invalidation is exercised with a wrong-tenant predicate call confirming it does not touch the other tenant's cache entries.

## Quality Gates

- pnpm vitest: 9/9 tests pass
- pnpm typecheck: clean (no errors)
- verify-history: 3185 events / 0 problems

## Summary

All seven review axes pass. The page-level wrap mechanic transfers cleanly from the hook-level pattern established in B5–B8. The two-namespace split (price-lists plural for list queries, price-list singular for detail/form queries) is correctly implemented with predicate invalidation on the plural side and exact-match invalidation on the singular side. The enabled guard correctly preserves pre-existing guards (Boolean(id), isEditing) and combines them with the tenant guards. No regressions detected.

Verdict: APPROVE
