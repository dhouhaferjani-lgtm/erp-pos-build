# Opus review prompt: web.tanstack-keys batch 42

Review B42 using the same gates as the prior frontend tenant-scope batches.

## Scope

- Batch: B42
- Cluster: `web.tanstack-keys`
- Callsites: `web.tanstack-keys.799` through `web.tanstack-keys.810`
- Fix commit: `1f22922a`
- Scanner delta: `433 -> 421` (12 removals)

## Files changed

- `apps/web/src/features/workshop-bundles/hooks/useBundles.ts`
- `apps/web/src/features/workshop-bundles/hooks/useUnits.ts`
- `apps/web/src/features/workshop-bundles/hooks/__tests__/tenantScope.test.tsx`

## What changed

- Wrapped workshop bundle read keys and the local UoM units key with `tenantScopedKey(...)`.
- Added tenant/company value selectors and read gates for list/detail/applicable/expansion/units.
- Preserved broad bundle mutation semantics with a current-tenant `workshop-bundles` predicate.
- Preserved component mutation semantics as exact scoped detail invalidations.
- Converted mutation invalidations to awaited async handlers.
- Added regression coverage for scoped key shapes, no-fetch without scope, broad vs exact mutation refetch counters, and tenant-B cache preservation.

## Commands run by Codex

```bash
pnpm vitest run src/features/workshop-bundles/hooks/__tests__/tenantScope.test.tsx
pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
php artisan sweep:inventory:verify-history
```

Results:

- Focused Vitest: pass (3 tests; Vitest emitted existing React async `act(...)` warnings)
- Typecheck: pass
- Scanner count: `421`
- Verify history before submit: `verified 4492 event(s) across 1205 callsite(s); 0 problem(s).`

## If approved

Write the review to:

`docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-42-opus-review.md`

Then lock:

```bash
for id in $(seq 799 810); do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --verdict=APPROVE \
    --review-file="../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-42-opus-review.md" \
    --review-commit="<OPUS_REVIEW_COMMIT>" \
    --fix-commit=1f22922a
done
php artisan sweep:inventory:verify-history
```

If changes are needed, mark REQUEST-CHANGES and cite the failing gate.
