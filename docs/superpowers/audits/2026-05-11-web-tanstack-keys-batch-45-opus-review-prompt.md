# Opus Review Prompt: web.tanstack-keys Batch 45

You are Opus reviewing Codex implementation work for the web.tanstack-keys tenant-scope sweep.

## Scope

- Batch: B45 vehicle hook-only group
- Callsites: `web.tanstack-keys.762` through `web.tanstack-keys.770`
- Fix commit: `94fe0000`
- Scanner delta: `393 -> 384`
- Review output target: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-45-opus-review.md`

## Changed Files

- `apps/web/src/features/vehicles/hooks/useLogVehicleMileage.ts`
- `apps/web/src/features/vehicles/hooks/usePartnerVehicles.ts`
- `apps/web/src/features/vehicles/hooks/useTransferVehicleOwnership.ts`
- `apps/web/src/features/vehicles/hooks/useVehicleMileageHistory.ts`
- `apps/web/src/features/vehicles/hooks/useVehicleOwnershipHistory.ts`
- `apps/web/src/features/vehicles/hooks/useVehicleWithCurrentOwner.ts`
- `apps/web/src/features/vehicles/hooks/__tests__/tenantScope.test.tsx`

## Gates To Check

Use the same review gates as the prior approved batches:

1. Scanner delta matches exactly 9 callsites removed.
2. Read hooks use state-value selectors for tenant/company and gate fetches until both are present.
3. Query keys are wrapped in `tenantScopedKey(...)`.
4. Mutation invalidations are awaited and scoped to the active tenant/company.
5. Predicate invalidation for partner vehicles matches only active-scope `partner-vehicles` keys for the destination partner.
6. Test covers cross-tenant cache preservation with tenant-B cache markers.
7. Test uses per-call counters to prove invalidation refetches the intended active-tenant queries.
8. `php artisan sweep:inventory:verify-history` remains clean.

## Verification Already Run By Codex

From `apps/web`:

```bash
pnpm vitest run src/features/vehicles/hooks/__tests__/tenantScope.test.tsx
pnpm typecheck
```

From repo root:

```bash
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
```

Result: `384`.

From `apps/api`:

```bash
php artisan sweep:inventory:verify-history
```

Result before submit: `verified 4653 event(s) across 1205 callsite(s); 0 problem(s).`

## Expected Verdict Action

If all gates pass, write the review file with verdict `APPROVE` and lock:

```bash
cd apps/api
for id in $(seq 762 770); do
  php artisan sweep:inventory:lock \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=opus \
    --review-commit=94fe0000
done
php artisan sweep:inventory:verify-history
```

If any gate fails, write `REQUEST-CHANGES` with exact file/line findings and do not lock.
