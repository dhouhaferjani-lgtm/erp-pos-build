# Opus Review Prompt: web.tanstack-keys Batch 33 (POS terminals)

Review B33 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B33 POS terminals
- Callsites: `web.tanstack-keys.511` through `web.tanstack-keys.523`
- Fix commit: `6148ebfa`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/pos/hooks/useTerminals.ts`
- `apps/web/src/features/pos/hooks/__tests__/useTerminals.tenantScope.test.tsx`

Expected scanner delta:
- Before B33: `503`
- After B33: `490`
- Expected removals: `13`

Review gates:
1. Scanner delta equals 13 for `.511-.523`.
2. Terminal list and detail query keys are wrapped at callsite with `tenantScopedKey([...terminalKeys.*(...)])`.
3. Hooks subscribe via state-value selectors through `usePosTenantScope()`, reusing the B30 POS tenant/company scope helper.
4. Terminal list and detail queries require tenant/company scope before fetching.
5. Create/archive/delete invalidations target only current-tenant terminal list caches.
6. Update/activate/deactivate/toggle invalidations await a `Promise.all` cascade: current-tenant terminal list invalidation plus exact scoped terminal detail invalidation.
7. Tests cover scoped key shapes, no-fetch without tenant/company, list/detail per-call refetch counters, and tenant-B cache preservation.

Commands already run by Codex:
- `pnpm vitest run src/features/pos/hooks/__tests__/useTerminals.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `490`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-33-opus-review.md`

Then lock callsites `.511-.523` with:

```bash
for id in $(seq 511 523); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-33-opus-review.md' \
    --review-commit=6148ebfa
done
```
