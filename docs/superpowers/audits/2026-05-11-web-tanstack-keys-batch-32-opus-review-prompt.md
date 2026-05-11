# Opus Review Prompt: web.tanstack-keys Batch 32 (POS products/tables)

Review B32 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B32 POS products/tables
- Callsites: `web.tanstack-keys.500` through `web.tanstack-keys.510`
- Fix commit: `51852720`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/pos/hooks/usePOSProducts.ts`
- `apps/web/src/features/pos/hooks/useTables.ts`
- `apps/web/src/features/pos/hooks/__tests__/posProductsTables.tenantScope.test.tsx`

Expected scanner delta:
- Before B32: `514`
- After B32: `503`
- Expected removals: `11`

Review gates:
1. Scanner delta equals 11 for `.500-.510`.
2. Product, floor, and table list query keys are wrapped at callsite with `tenantScopedKey([...factory.*(...)])`.
3. Hooks subscribe via state-value selectors through `usePosTenantScope()`, reusing the B30 POS tenant/company scope helper.
4. Product, floor, and table queries require tenant/company scope before fetching.
5. Floor mutations await exact scoped floor invalidation only.
6. Table mutations await tenant/company-matching `tables` namespace invalidation, covering current-tenant floors and table lists while preserving other tenants.
7. Tests cover scoped key shapes, no-fetch without tenant/company, per-call refetch counters, and tenant-B cache preservation.

Commands already run by Codex:
- `pnpm vitest run src/features/pos/hooks/__tests__/posProductsTables.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `503`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-32-opus-review.md`

Then lock callsites `.500-.510` with:

```bash
for id in $(seq 500 510); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-32-opus-review.md' \
    --review-commit=51852720
done
```
