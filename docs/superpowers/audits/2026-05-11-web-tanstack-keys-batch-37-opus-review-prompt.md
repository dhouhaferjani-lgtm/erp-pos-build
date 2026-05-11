# Opus Review Prompt: web.tanstack-keys Batch 37 (goods receipts)

Review B37 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B37 goods receipts
- Callsites: `web.tanstack-keys.581` through `web.tanstack-keys.585`
- Fix commit: `2f8e5b0b`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/purchases/GoodsReceiptListPage.tsx`
- `apps/web/src/features/purchases/GoodsReceiptListPage.tenantScope.test.tsx`

Expected scanner delta:
- Before B37: `478`
- After B37: `473`
- Expected removals: `5`

Review gates:
1. Scanner delta equals 5 for `.581-.585`.
2. Pending, received, and confirmed-fully-received purchase-order query keys are wrapped at callsite with `tenantScopedKey(...)`.
3. The page subscribes via state-value selectors:
   - `useAuthStore((state) => state.user?.tenant_id ?? null)`
   - `useCompanyStore((state) => state.currentCompanyId ?? null)`
4. Purchase-order list queries require tenant/company scope before fetching.
5. Receive-goods success awaits a `Promise.all` cascade that invalidates only current-tenant `purchase-orders` and `stock-levels` namespaces.
6. Tests cover scoped key shapes, no-fetch without tenant/company, purchase/stock per-call refetch counters, and tenant-B cache preservation.

Commands already run by Codex:
- `pnpm vitest run src/features/purchases/GoodsReceiptListPage.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `473`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-37-opus-review.md`

Then lock callsites `.581-.585` with:

```bash
for id in 581 582 583 584 585; do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-37-opus-review.md' \
    --review-commit=2f8e5b0b
done
```
