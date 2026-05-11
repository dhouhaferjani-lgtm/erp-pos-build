# Opus Review Prompt: web.tanstack-keys Batch 38 (reports pages)

Review B38 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B38 reports pages
- Callsites: `web.tanstack-keys.586` through `web.tanstack-keys.590`
- Fix commit: `414ba72b`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/reports/ReportsPage.tsx`
- `apps/web/src/features/reports/pages/AgedReceivablesPage.tsx`
- `apps/web/src/features/reports/reports.tenantScope.test.tsx`

Expected scanner delta:
- Before B38: `473`
- After B38: `468`
- Expected removals: `5`

Review gates:
1. Scanner delta equals 5 for `.586-.590`.
2. Documents, partners, products, payments, and aged-receivables query keys are wrapped at callsite with `tenantScopedKey(...)`.
3. Pages subscribe via state-value selectors:
   - `useAuthStore((state) => state.user?.tenant_id ?? null)`
   - `useCompanyStore((state) => state.currentCompanyId ?? null)`
4. Report dashboard and aged-receivables queries require tenant/company scope before fetching.
5. Tests cover scoped key shapes and no-fetch behavior without tenant/company state.

Commands already run by Codex:
- `pnpm vitest run src/features/reports/reports.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `468`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-38-opus-review.md`

Then lock callsites `.586-.590` with:

```bash
for id in 586 587 588 589 590; do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-38-opus-review.md' \
    --review-commit=414ba72b
done
```
