# Opus Review Prompt: web.tanstack-keys Batch 28 (parts catalog)

Review B28 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B28 parts catalog
- Callsites: `web.tanstack-keys.442` through `web.tanstack-keys.457`
- Fix commit: `af0e2a8a`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/parts-catalog/hooks/useArticleDetail.ts`
- `apps/web/src/features/parts-catalog/hooks/useArticles.ts`
- `apps/web/src/features/parts-catalog/hooks/useCriteriaMetadata.ts`
- `apps/web/src/features/parts-catalog/hooks/useCriteriaSearch.ts`
- `apps/web/src/features/parts-catalog/hooks/useManufacturers.ts`
- `apps/web/src/features/parts-catalog/hooks/useModelSeries.ts`
- `apps/web/src/features/parts-catalog/hooks/useMultiSearch.ts`
- `apps/web/src/features/parts-catalog/hooks/useSearchTree.ts`
- `apps/web/src/features/parts-catalog/hooks/useSupplierBrands.ts`
- `apps/web/src/features/parts-catalog/hooks/useVehicles.ts`
- `apps/web/src/features/parts-catalog/hooks/usePartsCatalogTenantScope.ts`
- `apps/web/src/features/parts-catalog/pages/ArticleDetailPage.tsx`
- `apps/web/src/features/parts-catalog/hooks/__tests__/tenantScope.test.tsx`

Expected scanner delta:
- Before B28: `565`
- After B28: `549`
- Expected removals: `16`

Review gates:
1. Scanner delta equals 16 for `.442-.457`.
2. All parts-catalog query keys are wrapped at callsite with `tenantScopedKey([...partsCatalogKeys.*(...)])`.
3. Hooks subscribe via state-value selectors through `usePartsCatalogTenantScope()`:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
4. Existing enabled gates are preserved and additionally require tenant/company scope, including `useQueries` and infinite-query callsites.
5. `ArticleDetailPage` invalidates the exact current-tenant article detail key after inventory add and does not touch tenant-B cache entries.
6. Tests cover scoped key shapes across tenants and the `.457` exact invalidation cascade with per-call counter and tenant-B preservation.

Commands already run by Codex:
- `pnpm vitest run src/features/parts-catalog/hooks/__tests__/tenantScope.test.tsx`
- `pnpm typecheck`
- `node tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `549`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-28-opus-review.md`

Then lock callsites `.442-.457` with:

```bash
for id in $(seq 442 457); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-28-opus-review.md' \
    --review-commit=af0e2a8a
done
```
