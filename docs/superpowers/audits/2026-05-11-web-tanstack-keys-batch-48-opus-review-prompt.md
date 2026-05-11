# Opus Review Prompt: web.tanstack-keys Batch 48

You are Opus reviewing Codex implementation work for the web.tanstack-keys tenant-scope sweep.

## Scope

- Batch: B48 batch hooks
- Callsites: `web.tanstack-keys.030` through `web.tanstack-keys.044`
- Fix commit: `af17652e`
- Scanner delta: `346 -> 331`
- Review output target: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-48-opus-review.md`

## Changed Files

- `apps/web/src/features/batches/hooks/useBatches.ts`
- `apps/web/src/features/batches/hooks/__tests__/tenantScope.test.tsx`

## Gates To Check

Use the same review gates as the prior approved batches:

1. Scanner delta matches exactly 15 callsites removed.
2. Read hooks use state-value selectors for tenant/company and gate fetches until both are present.
3. Read query keys are wrapped in `tenantScopedKey(...)` while preserving the existing `batchKeys` factory shape.
4. Batch collection invalidation uses an active tenant/company predicate and excludes `detail` keys to avoid duplicate detail refetches.
5. Detail invalidations use exact active-tenant `tenantScopedKey(batchKeys.detail(...))` keys where detail is affected.
6. Cross-namespace product-detail invalidations use exact active-tenant `tenantScopedKey(['products', 'detail', productId])`.
7. Mutation invalidations are `async` and awaited with `Promise.all` for cascades.
8. Test covers tenant-B cache preservation for batch list/detail/stock/expiring/fefo/product-batches and product detail.
9. Test uses per-call counters to prove the active tenant refetches intended keys without duplicate detail overfire.
10. `php artisan sweep:inventory:verify-history` remains clean.

## Verification Already Run By Codex

From `apps/web`:

```bash
pnpm vitest run src/features/batches/hooks/__tests__/tenantScope.test.tsx
pnpm typecheck
```

From repo root:

```bash
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
```

Result: `331`.

From `apps/api`:

```bash
php artisan sweep:inventory:verify-history
```

Result before submit: `verified 4806 event(s) across 1205 callsite(s); 0 problem(s).`

## Expected Verdict Action

If all gates pass, write the review file with verdict `APPROVE` and lock:

```bash
cd apps/api
for id in $(seq -f "%03g" 30 44); do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --review-commit=af17652e
done
php artisan sweep:inventory:verify-history
```

If any gate fails, write `REQUEST-CHANGES` with exact file/line findings and do not lock.
