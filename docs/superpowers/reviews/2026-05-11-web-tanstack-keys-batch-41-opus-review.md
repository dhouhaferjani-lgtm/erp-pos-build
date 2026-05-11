Commit reviewed: cab5cddf

# Opus review — web.tanstack-keys batch 41 (small settings hooks)

Independent second-pair-of-eyes review of the Codex implementation
at `cab5cddf`. All 6 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 6 (439 → 433): PASS.** Across 4 hook files:
- useCountries.ts: 2 reads (countries list, country detail)
- usePosRefundPolicies.ts: 1 read
- useSubscription.ts: 1 read
- useUpdatePosRefundPolicies.ts: 2 invalidation arms (pos-refund-
  policies + reservation-settings)

Total: 6 callsites covering `.656-.659` (settings reads) + `.668-.669`
(POS refund policy mutation invalidations).

**Gate 2 — Query keys wrapped: PASS.**
- countries list: `tenantScopedKey(['countries', filters])`
- country detail: `tenantScopedKey(['country', code])`
- pos-refund-policies: `tenantScopedKey(['pos-refund-policies',
  currentCompany?.id])`
- subscription: `tenantScopedKey(['subscription'])`

**Gate 3 — State-value selectors: PASS.** Each hook reads
`useAuthStore((state) => state.user?.tenant_id ?? null)` and
`useCompanyStore((state) => state.currentCompanyId ?? null)`.

**Gate 4 — Reads gated: PASS.** countries/subscription gate on
`hasTenantScope`; country detail keeps `!!code && hasTenantScope`;
POS refund policies preserves the existing currentCompany?.id guard
and adds the tenant/company gate.

**Gate 5 — POS refund mutation invalidations preserve both arms +
tenant-bounded: PASS.** `useUpdatePosRefundPolicies`'s onSuccess is
async and awaits `Promise.all([...])` of:
- `tenantScopedKey(['pos-refund-policies', currentCompany?.id])`
- `tenantScopedKey(['reservation-settings', currentCompany?.id])`

This preserves the previous two-arm invalidation contract while
bounding both arms to the active tenant/company.

**Gate 6 — Tests cover scoped key shapes + no-fetch + per-call
counter + tenant-B preservation: PASS.** 3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/settings/hooks/__tests__/smallSettingsHooks.tenantScope.test.tsx`:
  **3/3 pass**.
- `php artisan sweep:inventory:verify-history`: 4534 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
