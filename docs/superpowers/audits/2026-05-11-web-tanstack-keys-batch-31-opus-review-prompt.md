# Opus Review Prompt: web.tanstack-keys Batch 31 (POS orders)

Review B31 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B31 POS orders
- Callsites: `web.tanstack-keys.485` through `web.tanstack-keys.499`
- Fix commit: `62c4a6d5`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/pos/hooks/useOrders.ts`
- `apps/web/src/features/pos/hooks/__tests__/useOrders.tenantScope.test.tsx`

Expected scanner delta:
- Before B31: `529`
- After B31: `514`
- Expected removals: `15`

Review gates:
1. Scanner delta equals 15 for `.485-.499`.
2. Order list and detail query keys are wrapped at callsite with `tenantScopedKey([...orderKeys.*(...)])`.
3. Hooks subscribe via state-value selectors through `usePosTenantScope()`, reusing the B30 POS tenant/company scope helper.
4. List and detail queries require tenant/company scope before fetching.
5. Create-order invalidation targets only current-tenant `orders/list` caches.
6. Line/order state mutations await a `Promise.all` cascade: exact scoped order detail invalidation plus tenant/company-matching `orders/list` invalidation.
7. Tests cover scoped key shapes, no-fetch without tenant/company, list/detail per-call refetch counters, and tenant-B cache preservation.

Commands already run by Codex:
- `pnpm vitest run src/features/pos/hooks/__tests__/useOrders.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `514`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-31-opus-review.md`

Then lock callsites `.485-.499` with:

```bash
for id in $(seq 485 499); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-31-opus-review.md' \
    --review-commit=62c4a6d5
done
```
