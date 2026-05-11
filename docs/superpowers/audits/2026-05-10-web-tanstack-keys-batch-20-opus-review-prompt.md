Commit reviewed: `0ed87e8b`

# Opus review prompt — web.tanstack-keys batch 20 (loyalty members)

Review owner: Opus / human reviewer. Codex implemented and owns the
callsites in loyalty members, so Codex must not self-lock this batch.

## Scope

10 callsites: `web.tanstack-keys.344`-`.353`

Files:
- `apps/web/src/features/loyalty/hooks/useMembers.ts`
- `apps/web/src/features/loyalty/hooks/__tests__/useMembers.test.ts`
- `apps/web/src/features/loyalty/hooks/__tests__/useMembers.tenantScope.test.tsx`

## Shape

Single-file namespace batch:
- Member list/detail/enrollment/transaction query keys are wrapped with `tenantScopedKey([...])`.
- Query hooks use tenant/company state selectors and preserve existing id/enrollment guards.
- Mutations use `membersInvalidationPredicate(t,c)` to invalidate active-tenant
  `loyalty-members` cache entries without touching tenant-B.

## Required review checks

1. Scanner delta is exactly 10: observed live count `651 -> 641`.
2. Query hooks and predicate-based mutation hooks use state-value selectors:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
3. `membersInvalidationPredicate` gates on namespace plus tenant/company suffix.
4. Mutation `onSuccess` handlers are `async` and await predicate invalidation.
5. L7 is present: test drives create/update/enroll/opt-out/reactivate/adjust mutations
   via `mutateAsync` and asserts the active member-list counter moves `1 -> 2`.
6. L18 is present: test pre-seeds tenant-B members data containing
   `leaked-tenant-b-member`, renders tenant-A `useMembers`, and asserts tenant-A
   results are empty while tenant-B cache survives.
7. Existing `useMembers.test.ts` was updated to seed tenant/company for the new gates.

## Quality gates from Codex

- `pnpm vitest run src/features/loyalty/hooks/__tests__/useMembers.tenantScope.test.tsx src/features/loyalty/hooks/__tests__/useMembers.test.ts`: 12/12 pass.
- `pnpm typecheck`: clean.
- `audit-tanstack-keys`: 641 violations after B20, delta = 10 from B19 submitted baseline.
- `php artisan sweep:inventory:verify-history`: 3652 events / 1205 callsites / 0 problems.

If approved, lock `.344`-`.353` with:

```bash
php artisan sweep:inventory:review \
  --callsite-id web.tanstack-keys.<id> \
  --actor=claude \
  --verdict APPROVE \
  --review-file ../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-20-opus-review.md \
  --review-commit 0ed87e8b
```
