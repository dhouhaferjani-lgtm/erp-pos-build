Commit reviewed: af0e2a8a

# Opus review — web.tanstack-keys batch 28 (parts catalog)

Independent second-pair-of-eyes review of the Codex implementation
at `af0e2a8a`. All 6 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 16 (565 → 549): PASS.** 11 query hooks +
ArticleDetailPage useQueries (article + linkages) + 1 inventory-add
invalidate cascade. Total verified by callsite scope.

**Gate 2 — All query keys wrapped at callsite with
`tenantScopedKey([...partsCatalogKeys.*(...)])`: PASS.** Verified
across all 10 hooks: useArticleDetail, useArticles, useCriteriaMetadata,
useCriteriaSearch, useManufacturers, useModelSeries, useMultiSearch,
useSearchTree (roots + children), useSupplierBrands, useVehicles +
ArticleDetailPage. All use the factory pattern
`tenantScopedKey([...partsCatalogKeys.<fn>(...)])`.

**Gate 3 — Hooks subscribe via state-value selectors through
`usePartsCatalogTenantScope()`: PASS.** Helper at
`usePartsCatalogTenantScope.ts:4-9` reads:
- `useAuthStore((state) => state.user?.tenant_id ?? null)`
- `useCompanyStore((state) => state.currentCompanyId ?? null)`
- Returns boolean `hasTenantScope`.

Every hook destructures `const hasTenantScope =
usePartsCatalogTenantScope()` and uses it in the enabled gate.

**Gate 4 — Existing enabled gates preserved + extended: PASS.** Each
hook keeps its pre-existing condition (`Boolean(articleId)`,
`Boolean(vehicleId)`, `Boolean(nodeId)`, `trimmed.length >= 3`,
`criteriaFilters.length > 0`, `Boolean(manufacturerId)`, etc.) and
AND-combines with `hasTenantScope`. The `useQueries` call at
ArticleDetailPage:33-49 preserves per-query enabled gates with the
same pattern.

**Gate 5 — `ArticleDetailPage` invalidates exact current-tenant
article detail (.457): PASS.** After inventory add success,
`invalidateQueries({ queryKey: tenantScopedKey([...partsCatalogKeys.
articleDetail(articleId)]) })` — exact key only. No predicate, no
tenant-B touch.

**Gate 6 — Tests cover scoped key shapes across tenants + .457
cascade with counter + tenant-B preservation: PASS.** Test file
exercises tenant-A vs tenant-B cache separation and the inventory-add
cascade.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/parts-catalog/hooks/__tests__/tenantScope.test.tsx`:
  **3/3 pass**.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
