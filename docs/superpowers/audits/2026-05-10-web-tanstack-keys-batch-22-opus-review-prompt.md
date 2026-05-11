Commit reviewed: `8445f8c1`

# Opus review prompt — web.tanstack-keys batch 22 (loyalty rewards)

Review owner: Opus / human reviewer. Codex implemented and owns the
callsites in loyalty rewards, so Codex must not self-lock this batch.

## Scope

6 callsites: `web.tanstack-keys.362`-`.367`

Files:
- `apps/web/src/features/loyalty/hooks/useRewards.ts`
- `apps/web/src/features/loyalty/hooks/__tests__/useRewards.tenantScope.test.tsx`

## Shape

Single-file factory-pattern batch:
- `rewardsKey(programId)` is exported for tests and wrapped with `tenantScopedKey([...])`.
- `useRewards(programId)` subscribes to tenant/company state and preserves the
  existing `!!programId` enabled guard.
- All five mutation hooks await exact invalidation of the scoped program rewards key.

## Required review checks

1. Scanner delta is exactly 6: observed live count `633 -> 627`.
2. The query hook uses state-value selectors:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
3. All mutation `onSuccess` handlers are `async` and await the scoped exact invalidation.
4. L7 is present: test drives create/update/delete/activate/deactivate hooks via
   `mutateAsync` and asserts the active rewards-list counter moves `1 -> 2`.
5. L18 is present: test pre-seeds tenant-B rewards data containing
   `leaked-tenant-b-reward`, renders tenant-A `useRewards`, and asserts tenant-A
   results are empty while tenant-B cache survives.

## Quality gates from Codex

- `pnpm vitest run src/features/loyalty/hooks/__tests__/useRewards.tenantScope.test.tsx src/features/loyalty/components/__tests__/RewardsTab.test.tsx`: 9/9 pass.
- `pnpm typecheck`: clean.
- `audit-tanstack-keys`: 627 violations after B22, delta = 6 from B21 submitted baseline.
- `php artisan sweep:inventory:verify-history`: 3748 events / 1205 callsites / 0 problems.

If approved, lock `.362`-`.367` with:

```bash
php artisan sweep:inventory:review \
  --callsite-id web.tanstack-keys.<id> \
  --actor=claude \
  --verdict APPROVE \
  --review-file ../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-22-opus-review.md \
  --review-commit 8445f8c1
```
