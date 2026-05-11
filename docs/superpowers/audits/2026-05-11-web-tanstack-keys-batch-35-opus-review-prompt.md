# Opus Review Prompt: web.tanstack-keys Batch 35 (POS report pages)

Review B35 using the same frontend TanStack key gates as prior approved batches.

Scope:
- Batch: B35 POS report pages
- Callsites: `web.tanstack-keys.536` through `web.tanstack-keys.540`
- Fix commit: `a9bd4aa2`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/pos/pages/ShiftHistoryPage/ShiftHistoryPage.tsx`
- `apps/web/src/features/pos/pages/ZReportDetailPage/ZReportDetailPage.tsx`
- `apps/web/src/features/pos/pages/ZReportListPage/ZReportListPage.tsx`
- `apps/web/src/features/pos/pages/reportPages.tenantScope.test.tsx`

Expected scanner delta:
- Before B35: `486`
- After B35: `481`
- Expected removals: `5`

Review gates:
1. Scanner delta equals 5 for `.536-.540`.
2. Shift history, Z-report detail, Z-report list, and POS terminal lookup query keys are wrapped at callsite with `tenantScopedKey(...)`.
3. Pages subscribe via `usePosTenantScope()` and gate fetches on tenant/company scope.
4. Existing route/filter enabled gates are preserved and additionally require tenant/company scope.
5. Tests cover scoped key shapes for the report pages and no-fetch behavior without tenant/company state.

Commands already run by Codex:
- `pnpm vitest run src/features/pos/pages/reportPages.tenantScope.test.tsx`
- `pnpm typecheck`
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `481`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-35-opus-review.md`

Then lock callsites `.536-.540` with:

```bash
for id in 536 537 538 539 540; do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-35-opus-review.md' \
    --review-commit=a9bd4aa2
done
```
