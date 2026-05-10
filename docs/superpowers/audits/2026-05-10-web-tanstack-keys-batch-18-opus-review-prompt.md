Commit reviewed: `cd90b658`

# Opus review prompt — web.tanstack-keys batch 18 (locations)

Review owner: Opus / human reviewer. Codex implemented and owns the
callsites in locations, so Codex must not self-lock this batch.

## Scope

2 callsites: `web.tanstack-keys.336`-`.337`

Files:
- `apps/web/src/features/locations/hooks/useLocations.ts`
- `apps/web/src/features/locations/hooks/__tests__/useLocations.tenantScope.test.tsx`

## Shape

Small query-only factory batch:
- `locationKeys.list()` and `locationKeys.detail(id)` are wrapped with
  `tenantScopedKey([...])`.
- Both hooks subscribe to auth/company state values and gate fetches on
  `!!tenantId && !!companyId`.
- No mutation hooks exist in this file, so no invalidation cascade applies.

## Required review checks

1. Scanner delta is exactly 2: observed live count `659 -> 657`.
2. Both query hooks use state-value selectors:
   - `useAuthStore((s) => s.user?.tenant_id ?? null)`
   - `useCompanyStore((s) => s.currentCompanyId ?? null)`
3. `useLocation(id)` preserves the existing id guard and adds tenant/company guards.
4. L18 is present: test pre-seeds tenant-B `locations.list` with
   `leaked-tenant-b-location`, renders tenant-A `useLocations`, and asserts
   tenant-A results are empty and tenant-B cache survives.
5. Query-only file: L7 mutation cascade is not applicable.

## Quality gates from Codex

- `pnpm vitest run src/features/locations/hooks/__tests__/useLocations.tenantScope.test.tsx`: 5/5 pass.
- `pnpm typecheck`: clean.
- `audit-tanstack-keys`: 657 violations after B18, delta = 2 from B17 submitted baseline.
- `php artisan sweep:inventory:verify-history`: 3604 events / 1205 callsites / 0 problems.

If approved, lock `.336`-`.337` with:

```bash
php artisan sweep:inventory:review \
  --callsite-id web.tanstack-keys.<id> \
  --actor=claude \
  --verdict APPROVE \
  --review-file ../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-18-opus-review.md \
  --review-commit cd90b658
```
