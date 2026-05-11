Commit reviewed: 2e77fc3b

# Opus review — web.tanstack-keys batch 29 (POS analytics)

Independent second-pair-of-eyes review of the Codex implementation
at `2e77fc3b`. All 5 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 8 (549 → 541): PASS.** 8 analytics useQuery
hooks wrapped: summary, salesByCategory, salesByProduct, salesByPeriod,
cashiers, discounts, customers, fnb.

**Gate 2 — All POS analytics query keys wrapped at callsite: PASS.**
Every hook uses `tenantScopedKey([...analyticsKeys.<fn>(filters)])`
with factory composition.

**Gate 3 — Hooks subscribe via state-value selectors through
`usePosAnalyticsTenantScope()`: PASS.** Helper reads useAuthStore +
useCompanyStore as state values; returns `hasTenantScope`.

**Gate 4 — Existing date-range enabled gates preserved + extended:
PASS.** Each hook keeps `!!filters.from && !!filters.to` and AND-
combines with `&& hasTenantScope`. The date-range gate gracefully
suppresses fetches before tenant/company are populated.

**Gate 5 — Tests cover scoped key shapes + no-fetch + cross-tenant
cache-slot separation: PASS.** 3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/pos/hooks/__tests__/useAnalytics.tenantScope.test.tsx`:
  **3/3 pass**.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
