# Opus review prompt: web.tanstack-keys batch 44

Review B44 using the same gates as the prior frontend tenant-scope batches.

## Scope

- Batch: B44
- Cluster: `web.tanstack-keys`
- Callsites: `web.tanstack-keys.826` through `web.tanstack-keys.838`
- Fix commit: `1538d2b2`
- Scanner delta: `406 -> 393` (13 removals)

## Files changed

- `apps/web/src/features/workshop-work-orders/hooks/useWorkOrders.ts`
- `apps/web/src/features/workshop-work-orders/hooks/__tests__/tenantScope.test.tsx`

## What changed

- Wrapped work order list/detail read keys with `tenantScopedKey([...])`.
- Added tenant/company value selectors and read gates.
- Converted mutation invalidations to awaited async handlers.
- Preserved list invalidation semantics with a current-tenant list predicate, because prefix invalidation would not match tenant-suffixed filtered list keys.
- Preserved detail invalidation semantics with exact scoped detail keys.
- Added regression coverage for scoped key shapes, no-fetch without scope, six mutation refetch counters, and tenant-B cache preservation.

## Commands run by Codex

```bash
pnpm vitest run src/features/workshop-work-orders/hooks/__tests__/tenantScope.test.tsx
pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
php artisan sweep:inventory:verify-history
```

Results:

- Focused Vitest: pass (3 tests; Vitest emitted existing React async `act(...)` warnings)
- Typecheck: pass
- Scanner count: `393`
- Verify history before submit: `verified 4622 event(s) across 1205 callsite(s); 0 problem(s).`

## If approved

Write the review to:

`docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-44-opus-review.md`

Then lock:

```bash
for id in $(seq 826 838); do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --verdict=APPROVE \
    --review-file="../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-44-opus-review.md" \
    --review-commit="<OPUS_REVIEW_COMMIT>" \
    --fix-commit=1538d2b2
done
php artisan sweep:inventory:verify-history
```

If changes are needed, mark REQUEST-CHANGES and cite the failing gate.
