# Opus Review Prompt: web.tanstack-keys Batch 30 (POS operations)

Review B30 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B30 POS operations
- Callsites: `web.tanstack-keys.473` through `web.tanstack-keys.484`
- Fix commit: `19cc5ba7`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/pos/hooks/useDiscountPermissions.ts`
- `apps/web/src/features/pos/hooks/useDiscountPreview.ts`
- `apps/web/src/features/pos/hooks/useHeldOrders.ts`
- `apps/web/src/features/pos/hooks/useKitchenChannel.ts`
- `apps/web/src/features/pos/hooks/useKitchenOrders.ts`
- `apps/web/src/features/pos/hooks/usePosTenantScope.ts`
- `apps/web/src/features/pos/hooks/__tests__/posOperations.tenantScope.test.tsx`

Expected scanner delta:
- Before B30: `541`
- After B30: `529`
- Expected removals: `12`

Review gates:
1. Scanner delta equals 12 for `.473-.484`.
2. Discount, held-order, kitchen, and realtime query keys are wrapped at callsite with `tenantScopedKey(...)`.
3. Hooks subscribe via state-value selectors in `usePosTenantScope()`:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
4. Existing terminal/request enabled gates are preserved and additionally require tenant/company scope.
5. Held-order invalidations use the tenant/company-aware `scopedKeyPredicate('held-orders', ...)`.
6. Kitchen mutation invalidations await exact scoped kitchen invalidation; served-order cascade also invalidates only tenant/company-matching `orders` list caches.
7. Tests cover scoped key shapes, no-fetch without tenant/company, cross-tenant cache preservation, realtime invalidation, mutation cascades, and per-call refetch counters.

Commands already run by Codex:
- `pnpm vitest run src/features/pos/hooks/__tests__/posOperations.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `529`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-30-opus-review.md`

Then lock callsites `.473-.484` with:

```bash
for id in $(seq 473 484); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-30-opus-review.md' \
    --review-commit=19cc5ba7
done
```
