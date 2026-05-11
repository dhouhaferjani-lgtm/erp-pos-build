Commit reviewed: `ef4d99a4`

# Opus review prompt — web.tanstack-keys batch 19 (loyalty earning rules)

Review owner: Opus / human reviewer. Codex implemented and owns the
callsites in loyalty earning rules, so Codex must not self-lock this batch.

## Scope

6 callsites: `web.tanstack-keys.338`-`.343`

Files:
- `apps/web/src/features/loyalty/hooks/useEarningRules.ts`
- `apps/web/src/features/loyalty/hooks/__tests__/useEarningRules.tenantScope.test.tsx`

## Shape

Single-file factory-pattern batch:
- `earningRulesKey(programId)` is exported for tests and wrapped with `tenantScopedKey([...])`.
- `useEarningRules(programId)` subscribes to tenant/company state and preserves the
  existing `!!programId` enabled guard.
- All five mutation hooks await exact invalidation of the scoped program list key.

## Required review checks

1. Scanner delta is exactly 6: observed live count `657 -> 651`.
2. The query hook uses state-value selectors:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
3. All mutation `onSuccess` handlers are `async` and await the scoped exact invalidation.
4. L7 is present: test drives all five production mutation hooks via `mutateAsync`
   and asserts the active earning-rules list counter moves `1 -> 2`.
5. L18 is present: test pre-seeds tenant-B earning-rule data containing
   `leaked-tenant-b-rule`, renders tenant-A `useEarningRules`, and asserts tenant-A
   results are empty while tenant-B cache survives.

## Quality gates from Codex

- `pnpm vitest run src/features/loyalty/hooks/__tests__/useEarningRules.tenantScope.test.tsx`: 9/9 pass.
- `pnpm typecheck`: clean.
- `audit-tanstack-keys`: 651 violations after B19, delta = 6 from B18 submitted baseline.
- `php artisan sweep:inventory:verify-history`: 3622 events / 1205 callsites / 0 problems.

If approved, lock `.338`-`.343` with:

```bash
php artisan sweep:inventory:review \
  --callsite-id web.tanstack-keys.<id> \
  --actor=claude \
  --verdict APPROVE \
  --review-file ../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-19-opus-review.md \
  --review-commit ef4d99a4
```
