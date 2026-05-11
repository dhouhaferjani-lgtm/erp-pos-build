Commit reviewed: 6efddadd

# Opus review — web.tanstack-keys batch 40 (tax configuration hooks)

Independent second-pair-of-eyes review of the Codex implementation
at `6efddadd`. All 6 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 8 (447 → 439): PASS.** useTax-
Configurations.ts: 3 useQuery (list, detail, documentTypes) + 5
mutation invalidations (create/delete/reorder × 1 + update × 2 via
Promise.all) = 8 callsites.

**Gate 2 — Query keys wrapped: PASS.**
- list: `tenantScopedKey([...taxConfigurationKeys.list()])`
- detail: `tenantScopedKey([...taxConfigurationKeys.detail(id)])`
- documentTypes: `tenantScopedKey([...taxConfigurationKeys.documentTypes()])`

**Gate 3 — State-value selectors: PASS.** Hook reads
`useAuthStore((state) => state.user?.tenant_id ?? null)` and
`useCompanyStore((state) => state.currentCompanyId ?? null)`.

**Gate 4 — Reads gated: PASS.** List + documentTypes have
`enabled: hasTenantScope`; detail has `enabled: !!id && hasTenantScope`
(preserves the pre-existing id guard).

**Gate 5 — Mutation invalidations preserved + tenant-bounded: PASS.**
- create / delete / reorder: each invalidates the scoped list key
  exactly once.
- update: awaits `Promise.all([list invalidate, detail invalidate])`
  — exact-match singular detail wrap matches the leaf shape.
All `onSuccess` are async + awaited.

**Gate 6 — Tests cover scoped key shapes, no-fetch, per-mutation
refetch counters, tenant-B cache preservation: PASS.** 3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/settings/hooks/__tests__/useTaxConfigurations.tenantScope.test.tsx`:
  **3/3 pass**.
- `php artisan sweep:inventory:verify-history`: 4534 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
