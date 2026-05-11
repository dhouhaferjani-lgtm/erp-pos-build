# Opus Review Prompt: web.tanstack-keys Batch 34 (POS shift layout)

Review B34 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B34 POS shift layout
- Callsites: `web.tanstack-keys.524` through `web.tanstack-keys.527`
- Fix commit: `bb05467b`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/pos/layouts/POSLayout.tsx`
- `apps/web/src/features/pos/layouts/ShiftOperationsMenu.tsx`
- `apps/web/src/features/pos/layouts/POSLayout.tenantScope.test.tsx`

Expected scanner delta:
- Before B34: `490`
- After B34: `486`
- Expected removals: `4`

Review gates:
1. Scanner delta equals 4 for `.524-.527`.
2. Current-shift and shift-balance query keys are wrapped at callsite with `tenantScopedKey(...)`.
3. `POSLayout` subscribes via `usePosTenantScope()` and gates shift fetches on tenant/company scope.
4. X-report success invalidation awaits a `Promise.all` cascade for exact scoped shift and shift-balance keys.
5. Tests cover scoped key shapes, no-fetch without tenant/company, per-call refetch counters, and tenant-B cache preservation.

Commands already run by Codex:
- `pnpm vitest run src/features/pos/layouts/POSLayout.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `486`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-34-opus-review.md`

Then lock callsites `.524-.527` with:

```bash
for id in $(seq 524 527); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-34-opus-review.md' \
    --review-commit=bb05467b
done
```
