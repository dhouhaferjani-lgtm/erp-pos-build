# Opus review prompt — web.tanstack-keys batch 16 (inventory)

Commit reviewed: `c209d6e5`

Review owner: Opus / human reviewer. Codex implemented and owns the
callsites in inventory, so Codex must not self-lock this batch.

## Scope

19 callsites: `web.tanstack-keys.300`-`.318`

Files:
- `apps/web/src/features/inventory/_invalidation.ts`
- `apps/web/src/features/inventory/ProductDetailPage.tsx`
- `apps/web/src/features/inventory/ProductForm.tsx`
- `apps/web/src/features/inventory/ProductListPage.tsx`
- `apps/web/src/features/inventory/StockLevelsPage.tsx`
- `apps/web/src/features/inventory/StockMovementsPage.tsx`
- `apps/web/src/features/inventory/api/platformQueries.ts`
- `apps/web/src/features/inventory/components/ProductDocumentsTab.tsx`
- `apps/web/src/features/inventory/components/ProductMovementsTab.tsx`
- `apps/web/src/features/inventory/components/ProductStockLevels.tsx`
- `apps/web/src/features/inventory/components/pricing/PriceInputWithMargin.tsx`
- `apps/web/src/features/inventory/__tests__/tenantScope.test.tsx`

## Shape

Mixed page/component batch, closest references:
- B9/B13 for page-level `tenantScopedKey([...])`
- B14 for exact singular detail wrap + plural predicate cascade
- B11 for sibling/runtime-ish component namespaces

Predicate helper:
- `inventoryProductsInvalidationPredicate(t,c)` matches `products` plural list namespace.
- `stockLevelsInvalidationPredicate(t,c)` matches `stock-levels` namespace.
- Singular `product` details use exact-match `tenantScopedKey(['product', id])`.

## Required review checks

1. Scanner delta is exactly 19: observed live count `695 -> 676`.
2. All 19 queryKey/invalidate callsites are wrapped or predicate-scoped.
3. Every touched query subscribes to state values:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
4. `enabled` gates preserve existing conditions and add `!!tenantId && !!companyId`.
5. Mutation cascades await invalidation before navigation/close.
6. L7 is present: `tenantScope.test.tsx` has per-call counter cascade asserting active
   product and stock-level list counters move `1 -> 2`.
7. L18 is present: test pre-seeds tenant-B `products` data containing
   `leaked-tenant-b-product`, renders tenant-A `ProductListPage`, and asserts
   tenant-A results are empty and do not contain that marker.
8. Existing inventory tests still pass after patching store mocks for scoped queries.

## Quality gates from Codex

- `pnpm vitest run src/features/inventory/__tests__/tenantScope.test.tsx`: 7/7 pass.
- `pnpm vitest run src/features/inventory/`: 45/45 pass across 6 files.
- `pnpm typecheck`: clean.
- `audit-tanstack-keys`: 676 violations after B16, delta = 19 from B15 submitted baseline.
- `php artisan sweep:inventory:verify-history`: 3547 events / 1205 callsites / 0 problems.

If approved, lock `.300`-`.318` with:

```bash
php artisan sweep:inventory:review \
  --callsite-id web.tanstack-keys.<id> \
  --actor=claude \
  --verdict APPROVE \
  --review-file ../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-16-opus-review.md \
  --review-commit c209d6e5
```
