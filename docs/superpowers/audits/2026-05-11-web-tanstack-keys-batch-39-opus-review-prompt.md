# Opus review prompt: web.tanstack-keys batch 39

Review B39 using the same gates as the prior frontend tenant-scope batches.

## Scope

- Batch: B39
- Cluster: `web.tanstack-keys`
- Callsites: `web.tanstack-keys.591` through `web.tanstack-keys.611`
- Fix commit: `6d07073d`
- Submit metadata commit: pending when this prompt is written
- Scanner delta: `468 -> 447` (21 removals)

## Files changed

- `apps/web/src/features/scheduling/hooks/useScheduling.ts`
- `apps/web/src/features/scheduling/hooks/__tests__/useScheduling.tenantScope.test.tsx`

## What changed

- Wrapped all scheduling read query keys with `tenantScopedKey([...])`.
- Added value selectors for tenant/company scope:
  - `useAuthStore((state) => state.user?.tenant_id ?? null)`
  - `useCompanyStore((state) => state.currentCompanyId ?? null)`
- Added `enabled` gates so scheduling reads do not fetch without tenant/company scope.
- Replaced broad scheduling mutation invalidations with one tenant/company-bounded scheduling predicate.
- Made mutation `onSuccess` handlers async and awaited invalidation completion.
- Added regression coverage for:
  - Scoped scheduling query-key shapes.
  - No fetch without tenant/company state.
  - Every scheduling mutation invalidating the active tenant list exactly once.
  - Tenant-B cache preservation during tenant-A mutation invalidation.

## Review gates

Please verify:

1. Scanner delta matches 21 removed callsites for `.591-.611`.
2. Query keys are tenant/company scoped for appointment list/detail, bays, config, day, week, and free slots.
3. State selectors are value selectors, not whole-store selectors.
4. Read queries are gated by tenant/company scope.
5. Mutation invalidation preserves previous scheduling-wide semantics but only within the active tenant/company cache.
6. Tests cover cross-tenant isolation and per-mutation refetch behavior.

## Commands run by Codex

```bash
pnpm vitest run src/features/scheduling/hooks/__tests__/useScheduling.tenantScope.test.tsx
pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
php artisan sweep:inventory:verify-history
```

Results:

- Focused Vitest: pass (3 tests; Vitest emitted existing React async `act(...)` warnings)
- Typecheck: pass
- Scanner count: `447`
- Verify history: `verified 4405 event(s) across 1205 callsite(s); 0 problem(s).`

## If approved

Write the review to:

`docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-39-opus-review.md`

Then lock:

```bash
for id in $(seq 591 611); do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --verdict=APPROVE \
    --review-file="../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-39-opus-review.md" \
    --review-commit="<OPUS_REVIEW_COMMIT>" \
    --fix-commit=6d07073d
done
php artisan sweep:inventory:verify-history
```

If changes are needed, mark REQUEST-CHANGES and cite the failing gate.
