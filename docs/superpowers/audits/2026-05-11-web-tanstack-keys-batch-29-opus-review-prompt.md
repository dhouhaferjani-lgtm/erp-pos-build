# Opus Review Prompt: web.tanstack-keys Batch 29 (POS analytics)

Review B29 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B29 POS analytics
- Callsites: `web.tanstack-keys.465` through `web.tanstack-keys.472`
- Fix commit: `2e77fc3b`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/pos/hooks/useAnalytics.ts`
- `apps/web/src/features/pos/hooks/__tests__/useAnalytics.tenantScope.test.tsx`

Expected scanner delta:
- Before B29: `549`
- After B29: `541`
- Expected removals: `8`

Review gates:
1. Scanner delta equals 8 for `.465-.472`.
2. All POS analytics query keys are wrapped at callsite with `tenantScopedKey([...analyticsKeys.*(...)])`.
3. Hooks subscribe via state-value selectors in `usePosAnalyticsTenantScope()`:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
4. Existing date-range enabled gates are preserved and additionally require tenant/company scope.
5. Tests cover scoped key shapes, cross-tenant cache-slot separation, and no fetch without tenant/company state.

Commands already run by Codex:
- `pnpm vitest run src/features/pos/hooks/__tests__/useAnalytics.tenantScope.test.tsx`
- `pnpm typecheck`
- `node tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `541`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-29-opus-review.md`

Then lock callsites `.465-.472` with:

```bash
for id in $(seq 465 472); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-29-opus-review.md' \
    --review-commit=2e77fc3b
done
```
