# Opus review prompt: web.tanstack-keys batch 41

Review B41 using the same gates as the prior frontend tenant-scope batches.

## Scope

- Batch: B41
- Cluster: `web.tanstack-keys`
- Callsites: `web.tanstack-keys.656` through `.659`, plus `.668` through `.669`
- Fix commit: `cab5cddf`
- Scanner delta: `439 -> 433` (6 removals)

## Files changed

- `apps/web/src/features/settings/hooks/useCountries.ts`
- `apps/web/src/features/settings/hooks/usePosRefundPolicies.ts`
- `apps/web/src/features/settings/hooks/useSubscription.ts`
- `apps/web/src/features/settings/hooks/useUpdatePosRefundPolicies.ts`
- `apps/web/src/features/settings/hooks/__tests__/smallSettingsHooks.tenantScope.test.tsx`

## What changed

- Wrapped countries, country detail, POS refund policies, subscription, and POS refund invalidation keys with `tenantScopedKey(...)`.
- Added tenant/company value selectors in each hook so scope changes causally re-render the query keys.
- Gated reads until tenant/company scope is present; POS refund policies still also require the current company.
- Converted POS refund policy mutation invalidations to awaited `Promise.all`, preserving both previous invalidation arms:
  - `pos-refund-policies`
  - shared `reservation-settings`
- Added regression coverage for scoped key shapes, no-fetch without scope, per-call refetch counter, and tenant-B cache preservation.

## Commands run by Codex

```bash
pnpm vitest run src/features/settings/hooks/__tests__/smallSettingsHooks.tenantScope.test.tsx
pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
php artisan sweep:inventory:verify-history
```

Results:

- Focused Vitest: pass (3 tests; Vitest emitted existing React async `act(...)` warnings)
- Typecheck: pass
- Scanner count: `433`
- Verify history before submit: `verified 4462 event(s) across 1205 callsite(s); 0 problem(s).`

## If approved

Write the review to:

`docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-41-opus-review.md`

Then lock:

```bash
for id in 656 657 658 659 668 669; do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --verdict=APPROVE \
    --review-file="../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-41-opus-review.md" \
    --review-commit="<OPUS_REVIEW_COMMIT>" \
    --fix-commit=cab5cddf
done
php artisan sweep:inventory:verify-history
```

If changes are needed, mark REQUEST-CHANGES and cite the failing gate.
