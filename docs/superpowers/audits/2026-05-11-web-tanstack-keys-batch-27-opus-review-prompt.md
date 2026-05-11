# Opus Review Prompt: web.tanstack-keys Batch 27 (partners)

Review B27 using the same gates as the prior approved frontend TanStack key batches.

Scope:
- Batch: B27 partners
- Callsites: `web.tanstack-keys.427` through `web.tanstack-keys.441`
- Fix commit: `090ec64c`
- Submit status: under review by Codex, awaiting Opus verdict

Files changed:
- `apps/web/src/features/partners/PartnerDetailPage.tsx`
- `apps/web/src/features/partners/PartnerForm.tsx`
- `apps/web/src/features/partners/PartnerListPage.tsx`
- `apps/web/src/features/partners/hooks/usePartnerBalanceRealtime.ts`
- `apps/web/src/features/partners/hooks/usePartnerContacts.ts`
- `apps/web/src/features/partners/_invalidation.ts`
- `apps/web/src/features/partners/__tests__/tenantScope.test.tsx`
- `apps/web/src/features/partners/partners.test.tsx`

Expected scanner delta:
- Before B27: `580`
- After B27: `565`
- Expected removals: `15`

Review gates:
1. Scanner delta equals 15 for `.427-.441`.
2. Query keys are wrapped with `tenantScopedKey(...)` and gated by state-value selectors:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
3. Partner list-like invalidations use tenant/company suffix predicates and do not match singular detail keys accidentally.
4. Partner form mutation cascades await invalidations before navigation:
   - create invalidates current-tenant partner list caches
   - update invalidates current-tenant partner list caches plus exact current-tenant detail
5. Realtime invalidation is scoped to current tenant/company for partners, exact partner detail, and account balances.
6. Tests cover scoped key shapes, predicate gates, per-call refetch counters, and tenant-B cache preservation.

Commands already run by Codex:
- `pnpm vitest run src/features/partners/__tests__/tenantScope.test.tsx src/features/partners/partners.test.tsx`
- `pnpm typecheck`
- `node tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `565`
- `php artisan sweep:inventory:verify-history` -> `0 problem(s)`

If approved, write review to:
- `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-27-opus-review.md`

Then lock callsites `.427-.441` with:

```bash
for id in $(seq 427 441); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=APPROVE \
    --review-file='../../docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batch-27-opus-review.md' \
    --review-commit=090ec64c
done
```
