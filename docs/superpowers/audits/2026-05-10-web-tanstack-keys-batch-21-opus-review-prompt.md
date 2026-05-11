Commit reviewed: `0ec0f76b`

# Opus review prompt — web.tanstack-keys batch 21 (loyalty programs)

Review owner: Opus / human reviewer. Codex implemented and owns the
callsites in loyalty programs, so Codex must not self-lock this batch.

## Scope

8 callsites: `web.tanstack-keys.354`-`.361`

Files:
- `apps/web/src/features/loyalty/hooks/usePrograms.ts`
- `apps/web/src/features/loyalty/hooks/__tests__/usePrograms.test.ts`
- `apps/web/src/features/loyalty/hooks/__tests__/usePrograms.tenantScope.test.tsx`

## Shape

Single-file namespace batch:
- Program list/detail/active query keys are wrapped with `tenantScopedKey([...])`.
- Query hooks use tenant/company state selectors and preserve the detail id guard.
- Mutations use `programsInvalidationPredicate(t,c)` to invalidate active-tenant
  `loyalty-programs` cache entries without touching tenant-B.

## Required review checks

1. Scanner delta is exactly 8: observed live count `641 -> 633`.
2. Query hooks and predicate-based mutation hooks use state-value selectors:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
3. `programsInvalidationPredicate` gates on namespace plus tenant/company suffix.
4. Mutation `onSuccess` handlers are `async` and await predicate invalidation.
5. L7 is present: test drives create/update/delete/activate/deactivate mutations via
   `mutateAsync` and asserts the active programs-list counter moves `1 -> 2`.
6. L18 is present: test pre-seeds tenant-B programs data containing
   `leaked-tenant-b-program`, renders tenant-A `usePrograms`, and asserts tenant-A
   results are empty while tenant-B cache survives.
7. Existing `usePrograms.test.ts` was updated to seed tenant/company for the new gates.

## Quality gates from Codex

- `pnpm vitest run src/features/loyalty/hooks/__tests__/usePrograms.tenantScope.test.tsx src/features/loyalty/hooks/__tests__/usePrograms.test.ts`: 11/11 pass.
- `pnpm typecheck`: clean.
- `audit-tanstack-keys`: 633 violations after B21, delta = 8 from B20 locked baseline.
- `php artisan sweep:inventory:verify-history`: 3730 events / 1205 callsites / 0 problems.

If approved, lock `.354`-`.361` with:

```bash
php artisan sweep:inventory:review \
  --callsite-id web.tanstack-keys.<id> \
  --actor=claude \
  --verdict APPROVE \
  --review-file ../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-21-opus-review.md \
  --review-commit 0ec0f76b
```
