Commit reviewed: c39bde74

# Opus review prompt - web.tanstack-keys batch 25 (opening balances)

Review the Codex implementation for batch 25.

Scope:
- `web.tanstack-keys.388-.406`
- `apps/web/src/features/opening-balances/api/queries.ts`
- 19 callsites total: 6 query keys, 13 mutation invalidations.

Expected pattern:
- Same tenant-scoped key pattern as the approved prior batches.
- Query hooks subscribe to state values with `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`.
- All useQuery keys are wrapped with `tenantScopedKey([...factory(...)])`, with enabled gates requiring tenant + company.
- Mutation success handlers are async and await invalidation cascades.
- Exact deterministic keys use `tenantScopedKey([...openingBalanceKeys.*(...)])`.
- Rows invalidations use `openingBalanceRowsInvalidationPredicate()` because rows query keys include optional params before the tenant/company suffix.

Codex gates already run:
- `cd apps/web && pnpm vitest run src/features/opening-balances/api/__tests__/queries.tenantScope.test.tsx` -> PASS (6 tests)
- `cd apps/web && node tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `600`
- Scanner delta: `619 -> 600` (19 removals)
- `cd apps/web && pnpm typecheck` -> PASS
- `cd apps/api && php artisan sweep:inventory:verify-history` -> PASS (`3810 event(s) across 1205 callsite(s); 0 problem(s)`)
- `git diff --check` -> PASS

Review axes:
1. Scanner delta exactly matches `.388-.406`.
2. State-value selectors are present in all query hooks and mutation hooks.
3. Rows predicate is narrow: namespace `opening-balances`, segment `rows`, company slot, batch slot, tenant suffix, company suffix.
4. Mutation cascades close the intended active queries and do not over-refetch unrelated active queries.
5. L18 cross-tenant test pre-seeds tenant-B list/rows cache and proves tenant-A results are clean while tenant-B cache survives.
6. Per-call counters verify active refetches for create/delete, import/validate, and post/lock.

If approved, lock these callsites against the fix commit:

```bash
for id in $(seq 388 406); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=fixed \
    --review-commit=c39bde74 \
    --review-file=../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-25-opus-review.md
done
```
