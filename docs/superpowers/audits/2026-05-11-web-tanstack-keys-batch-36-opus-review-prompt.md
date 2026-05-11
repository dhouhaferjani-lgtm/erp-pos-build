# Opus Review Prompt: web.tanstack-keys Batch 36 (POS smart prompts)

Review B36 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B36 POS smart prompts
- Callsites: `web.tanstack-keys.541` through `web.tanstack-keys.543`
- Fix commit: `03ffd55e`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/pos/smart-prompts/hooks/useCartRecommendations.ts`
- `apps/web/src/features/pos/smart-prompts/hooks/useContactProfile.ts`
- `apps/web/src/features/pos/smart-prompts/hooks/__tests__/smartPrompts.tenantScope.test.tsx`

Expected scanner delta:
- Before B36: `481`
- After B36: `478`
- Expected removals: `3`

Review gates:
1. Scanner delta equals 3 for `.541-.543`.
2. Smart recommendation and contact-profile query keys are wrapped at callsite with `tenantScopedKey(...)`.
3. Hooks subscribe via `usePosTenantScope()` and gate fetches on tenant/company scope.
4. Contact-profile mutation awaits exact scoped contact-profile invalidation.
5. Tests cover scoped key shapes, no-fetch without tenant/company, contact-profile per-call refetch counter, and tenant-B cache preservation.

Commands already run by Codex:
- `pnpm vitest run src/features/pos/smart-prompts/hooks/__tests__/smartPrompts.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `478`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-36-opus-review.md`

Then lock callsites `.541-.543` with:

```bash
for id in 541 542 543; do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-36-opus-review.md' \
    --review-commit=03ffd55e
done
```
