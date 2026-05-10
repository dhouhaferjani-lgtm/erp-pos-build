Commit reviewed: `9b9fc606`

# Opus review prompt — web.tanstack-keys batch 17 (inventory counting)

Review owner: Opus / human reviewer. Codex implemented and owns the
callsites in inventory-counting, so Codex must not self-lock this batch.

## Scope

17 callsites: `web.tanstack-keys.319`-`.335`

Files:
- `apps/web/src/features/inventory-counting/api/queries.ts`
- `apps/web/src/features/inventory-counting/__tests__/tenantScope.test.tsx`

## Shape

Single-file factory-pattern batch:
- `countingKeys.*` query factories are wrapped with `tenantScopedKey([...])`.
- `countingListInvalidationPredicate(t,c)` matches only plural `['counting', 'list', ...]`
  cache entries for the active tenant/company.
- Singular dashboard/detail/reconciliation/report entries use exact-match
  `tenantScopedKey([...countingKeys.*(...)])` invalidations.

## Required review checks

1. Scanner delta is exactly 17: observed live count `676 -> 659`.
2. All 17 queryKey/invalidate callsites are wrapped or predicate-scoped.
3. All five query hooks subscribe to state values:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
4. Query `enabled` gates preserve existing id guards and add `!!tenantId && !!companyId`.
5. Mutation cascades use `async` `onSuccess` and `await Promise.all([...invalidates])`.
6. L7 is present: `tenantScope.test.tsx` drives production `mutateAsync` and asserts
   active list/dashboard/detail counters move `1 -> 2` where expected.
7. L18 is present: test pre-seeds tenant-B counting data containing
   `leaked-tenant-b-counting`, renders tenant-A `useCountingList`, and asserts
   tenant-A results are empty and do not contain that marker while tenant-B cache survives.
8. Existing inventory-counting page tests still pass.

## Quality gates from Codex

- `pnpm vitest run src/features/inventory-counting/__tests__/tenantScope.test.tsx`: 8/8 pass.
- `pnpm vitest run src/features/inventory-counting`: 18/18 pass across 3 files.
- `pnpm typecheck`: clean.
- `audit-tanstack-keys`: 659 violations after B17, delta = 17 from B16 submitted baseline.
- `php artisan sweep:inventory:verify-history`: 3598 events / 1205 callsites / 0 problems.

If approved, lock `.319`-`.335` with:

```bash
php artisan sweep:inventory:review \
  --callsite-id web.tanstack-keys.<id> \
  --actor=claude \
  --verdict APPROVE \
  --review-file ../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-17-opus-review.md \
  --review-commit 9b9fc606
```
