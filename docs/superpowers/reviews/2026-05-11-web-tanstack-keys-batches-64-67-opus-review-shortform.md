# web.tanstack-keys Batches 64-67 — Opus Review

Commit reviewed: 105c508a
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: catalog hooks (bundled commit)
- B64 catalog/api/queries.ts — callsites .045-052 (8)
- B65 useCompositeItems.ts — callsites .055-061 (7)
- B66 useModifierGroups.ts — callsites .062-071 (10)
- B67 useRecipes.ts — callsites .072-093 (22)

Scanner delta: 275 → 228 (-47)

## Summary

Typed `CategoryInvalidationShape` predicate for queries.ts. Exported `compositeItemsInvalidationPredicate`, `modifierGroupsInvalidationPredicate`, `recipesInvalidationPredicate`, `variantsInvalidationPredicate` in hook files, each suffix-scoped to tenant/company. Cross-namespace cascades (modifier-group → composite-items; recipes → composite-items + recipes/variants) use the imported sibling predicate, preserving prior semantics of `compositeItemKeys.all`-style invalidations. Production hooks (`useCreateRecipe`, `useCreateVariant`, `useAssignModifierGroup`, `useUpdateCatalogCategory`) exercised via `renderHook` + `mutateAsync`. Tenant-B markers preserved for compositeItems / recipes / variants / categories lists.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-64-67-opus-review.md`.
