Commit reviewed: `5fdf2fd8`

# Opus review prompt — web.tanstack-keys batch 23 (loyalty stamp cards)

Review owner: Opus / human reviewer. Codex implemented and owns the
callsites in loyalty stamp cards, so Codex must not self-lock this batch.

## Scope

4 callsites: `web.tanstack-keys.368`-`.371`

Files:
- `apps/web/src/features/loyalty/hooks/useStampCards.ts`
- `apps/web/src/features/loyalty/hooks/__tests__/useStampCards.tenantScope.test.tsx`

## Shape

Single-file factory-pattern batch:
- `stampCardsKey(programId)` is exported for tests and wrapped with `tenantScopedKey([...])`.
- `useStampCards(programId)` subscribes to tenant/company state and preserves the
  existing `!!programId` enabled guard.
- All three mutation hooks await exact invalidation of the scoped program stamp-cards key.

## Required review checks

1. Scanner delta is exactly 4: observed live count `627 -> 623`.
2. The query hook uses state-value selectors:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
3. All mutation `onSuccess` handlers are `async` and await the scoped exact invalidation.
4. L7 is present: test drives create/update/delete hooks via `mutateAsync` and asserts
   the active stamp-cards list counter moves `1 -> 2`.
5. L18 is present: test pre-seeds tenant-B stamp-card data containing
   `leaked-tenant-b-stamp-card`, renders tenant-A `useStampCards`, and asserts tenant-A
   results are empty while tenant-B cache survives.

## Quality gates from Codex

- `pnpm vitest run src/features/loyalty/hooks/__tests__/useStampCards.tenantScope.test.tsx`: 4/4 pass.
- `pnpm typecheck`: clean.
- `audit-tanstack-keys`: 623 violations after B23, delta = 4 from B22 submitted baseline.
- `php artisan sweep:inventory:verify-history`: 3760 events / 1205 callsites / 0 problems.

If approved, lock `.368`-`.371` with:

```bash
php artisan sweep:inventory:review \
  --callsite-id web.tanstack-keys.<id> \
  --actor=claude \
  --verdict APPROVE \
  --review-file ../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-23-opus-review.md \
  --review-commit 5fdf2fd8
```
