# Opus review prompt: web.tanstack-keys batch 43

Review B43 using the same gates as the prior frontend tenant-scope batches.

## Scope

- Batch: B43
- Cluster: `web.tanstack-keys`
- Callsites: `web.tanstack-keys.811` through `web.tanstack-keys.825`
- Fix commit: `b8b51f87`
- Scanner delta: `421 -> 406` (15 removals)

## Files changed

- `apps/web/src/features/workshop-technicians/hooks/useAuthoring.ts`
- `apps/web/src/features/workshop-technicians/hooks/useTechnicians.ts`
- `apps/web/src/features/workshop-technicians/hooks/__tests__/tenantScope.test.tsx`

## What changed

- Wrapped workshop technician read keys with `tenantScopedKey([...])`.
- Added tenant/company value selectors and read gates for:
  - certifications
  - time off
  - time entries
  - technician list/detail/availability
- Converted authoring mutation invalidations to awaited async handlers.
- Preserved exact certification invalidation with an exact scoped key.
- Preserved ranged time-off/time-entry invalidation semantics with active-tenant predicates, because prefix invalidation would not match tenant-suffixed range keys.
- Added regression coverage for scoped key shapes, no-fetch without scope, all nine authoring mutation refetch counters, and tenant-B cache preservation.

## Commands run by Codex

```bash
pnpm vitest run src/features/workshop-technicians/hooks/__tests__/tenantScope.test.tsx
pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
php artisan sweep:inventory:verify-history
```

Results:

- Focused Vitest: pass (3 tests; Vitest emitted existing React async `act(...)` warnings)
- Typecheck: pass
- Scanner count: `406`
- Verify history before submit: `verified 4534 event(s) across 1205 callsite(s); 0 problem(s).`

## If approved

Write the review to:

`docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-43-opus-review.md`

Then lock:

```bash
for id in $(seq 811 825); do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --verdict=APPROVE \
    --review-file="../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-43-opus-review.md" \
    --review-commit="<OPUS_REVIEW_COMMIT>" \
    --fix-commit=b8b51f87
done
php artisan sweep:inventory:verify-history
```

If changes are needed, mark REQUEST-CHANGES and cite the failing gate.
