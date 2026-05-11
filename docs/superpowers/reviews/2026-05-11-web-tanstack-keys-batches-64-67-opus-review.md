# web.tanstack-keys Batches 64-67 — Opus Review

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commits reviewed

Fix commit: `105c508a` — 47 callsites across 4 files.

| Batch | File | Callsites |
| --- | --- | --- |
| B64 | apps/web/src/features/catalog/api/queries.ts | web.tanstack-keys.045-052 (8) |
| B65 | apps/web/src/features/catalog/hooks/useCompositeItems.ts | web.tanstack-keys.055-061 (7) |
| B66 | apps/web/src/features/catalog/hooks/useModifierGroups.ts | web.tanstack-keys.062-071 (10) |
| B67 | apps/web/src/features/catalog/hooks/useRecipes.ts | web.tanstack-keys.072-093 (22) |

Test file (shared): `apps/web/src/features/catalog/hooks/__tests__/tenantScope.test.tsx`

Scanner: 275 → 228 (-47 violations). Verify-history: clean.

## Verdict

Verdict: APPROVE

APPROVE — all 4 batches.

## Gates evaluated (all pass)

1. State-value tenant/company selectors used throughout.
2. Read `enabled:` AND-combines `!!id`, `!!locationId`, `!!compositeItemId`, `options?.enabled ?? true` etc. with `tenantId !== null && companyId !== null`.
3. Read `queryKey` wraps `tenantScopedKey([...])`.
4. Mutation invalidations use typed exported predicates (`CategoryInvalidationShape` in queries.ts; `compositeItemsInvalidationPredicate`, `modifierGroupsInvalidationPredicate`, `recipesInvalidationPredicate`, `variantsInvalidationPredicate` in the hook files), each correctly suffix-scoped to tenant/company.
5. Cross-namespace cascades (modifier-group → composite-items; recipes → composite-items + recipes-or-variants) use the **imported** sibling predicate, preserving prior semantics of `compositeItemKeys.all`-style invalidations.
6. Mutations await `Promise.all(...)` for multi-namespace cascades.
7. Per-call counters (`compositeCalls`, `recipeCalls`, `variantCalls`, `modifierGroupCalls`, plus `listCalls`/`treeCalls`/`detailCalls` for categories) track each namespace independently; sibling-detail `detailCalls(99)` asserted to stay at 1 across mutations targeting id=42 — proves no overfire on inactive sibling.
8. Tenant-B markers preserved for `compositeItems list`, `recipes list`, `variants list`, `categories list`.
9. Production hooks (`useCreateRecipe`, `useCreateVariant`, `useAssignModifierGroup`, `useUpdateCatalogCategory`) used via `renderHook` + `mutateAsync` — no predicate-direct stand-ins.

## Per-batch notes

- **B64** (queries.ts — categories): `CategoryInvalidationShape` predicate filters by `k[0]==='categories'`, suffix tenant/company, plus slot checks (`k[1]==='list'|'tree'|'detail'`, `k[2]===id`). `useUpdateCategory` awaits `Promise.all` of detail + list + tree predicates.
- **B65** (useCompositeItems): 3 reads wrapped (lines 49, 59, 125); 4 mutations (`useCreate`, `useUpdate`, `useDelete`, `useDuplicate`) all await predicate invalidations.
- **B66** (useModifierGroups): `useAssignModifierGroup` / `useRemoveModifierGroup` correctly import and reuse `compositeItemsInvalidationPredicate` — preserves prior cross-namespace semantics. 8 mutations total, all awaited.
- **B67** (useRecipes): 9 recipe/variant mutations all await `Promise.all` of own-namespace + `compositeItemsInvalidationPredicate` cross-cascade — mirrors original `compositeItemKeys.all` invalidations. `useCalculateRecipeCost` invalidates only recipes (matches original behavior).

## Non-blocking findings

1. Only `useCompositeItems` has an explicit missing-tenant no-fetch assertion (test line 191-194); the other 10 read hooks rely on the shared `tenantScopedKey` helper for gating but lack per-hook gate tests. Pattern is correct in source; the gate test is the canonical guard against future drift.
2. `(options?.enabled ?? true) && …` (queries.ts:69, 85, 102) assumes `enabled` is `boolean | undefined`; TanStack v5 typings also allow `(query) => boolean`. No current caller passes a function, so this is inert today.
3. Assign/remove modifier-group mutations invalidate only `compositeItems` (matches prior behavior); if the modifier-groups list later needs to refresh an `assignedTo` count, this would silently miss it.
4. After `assignModifierGroup.mutateAsync`, the test asserts `compositeCalls=4` but does not assert `modifierGroupCalls` stays at 1 — would catch an accidental cross-cascade addition.

## Locks applied

All 47 callsites locked at fix commit `105c508a`.
