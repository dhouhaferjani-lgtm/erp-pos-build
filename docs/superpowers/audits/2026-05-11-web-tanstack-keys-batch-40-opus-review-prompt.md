# Opus review prompt: web.tanstack-keys batch 40

Review B40 using the same gates as the prior frontend tenant-scope batches.

## Scope

- Batch: B40
- Cluster: `web.tanstack-keys`
- Callsites: `web.tanstack-keys.660` through `web.tanstack-keys.667`
- Fix commit: `6efddadd`
- Scanner delta: `447 -> 439` (8 removals)

## Files changed

- `apps/web/src/features/settings/hooks/useTaxConfigurations.ts`
- `apps/web/src/features/settings/hooks/__tests__/useTaxConfigurations.tenantScope.test.tsx`

## What changed

- Wrapped tax configuration read keys with `tenantScopedKey([...])`.
- Added value selectors for tenant/company scope.
- Gated list/detail/document-type reads until tenant/company are present.
- Converted mutation invalidations to awaited tenant-scoped invalidations:
  - create/delete/reorder invalidate the scoped list key.
  - update invalidates scoped list plus scoped detail via `Promise.all`.
- Added regression coverage for scoped key shapes, no-fetch without scope, per-mutation refetch counters, and tenant-B cache preservation.

## Commands run by Codex

```bash
pnpm vitest run src/features/settings/hooks/__tests__/useTaxConfigurations.tenantScope.test.tsx
pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
php artisan sweep:inventory:verify-history
```

Results:

- Focused Vitest: pass (3 tests; Vitest emitted existing React async `act(...)` warnings)
- Typecheck: pass
- Scanner count: `439`
- Verify history before submit: `verified 4442 event(s) across 1205 callsite(s); 0 problem(s).`

## If approved

Write the review to:

`docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-40-opus-review.md`

Then lock:

```bash
for id in $(seq 660 667); do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --verdict=APPROVE \
    --review-file="../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-40-opus-review.md" \
    --review-commit="<OPUS_REVIEW_COMMIT>" \
    --fix-commit=6efddadd
done
php artisan sweep:inventory:verify-history
```

If changes are needed, mark REQUEST-CHANGES and cite the failing gate.
