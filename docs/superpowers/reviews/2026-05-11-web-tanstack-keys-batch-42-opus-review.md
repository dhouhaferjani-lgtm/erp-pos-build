Commit reviewed: 1f22922a

# Opus review — web.tanstack-keys batch 42 (workshop bundle hooks)

Independent second-pair-of-eyes review of the Codex implementation
at `1f22922a`. All 6 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 12 (433 → 421): PASS.** useBundles.ts
(list, detail, applicable, expansion + 4 component mutations × 1
exact-match detail invalidate + broad bundle mutation predicate) +
useUnits.ts (1 local UoM read).

**Gate 2 — Query keys wrapped: PASS.**
- list: `tenantScopedKey([...BUNDLES_KEY, 'list', params])`
- detail: `tenantScopedKey([...BUNDLES_KEY, 'detail', id])`
- applicable: `tenantScopedKey([...BUNDLES_KEY, 'applicable', params])`
- expansion: `tenantScopedKey([...BUNDLES_KEY, 'expansion', bundleId,
  qty, vehicleId])`
- units: `tenantScopedKey(['uom', 'units'])`

Note: BUNDLES_KEY is the identifier-shape constant for workshop-
bundles, spread + composed at callsite — consistent with B5/B6/B14
identifier-shape inheritance.

**Gate 3 — State-value selectors: PASS.** Each hook reads
`useAuthStore((state) => state.user?.tenant_id ?? null)` and
`useCompanyStore((state) => state.currentCompanyId ?? null)`.

**Gate 4 — Reads gated: PASS.** list / applicable / units use
`enabled: hasTenantScope`; detail keeps `id !== undefined &&
hasTenantScope`; expansion keeps `bundleId !== undefined &&
hasTenantScope`.

**Gate 5 — Mutation invalidations preserve old semantics + tenant-
bounded: PASS.**
- Broad bundle mutations (e.g., create/update/delete bundle) use a
  current-tenant `workshop-bundles` predicate — preserves the previous
  broad invalidation while bounding to the active tenant/company.
- Component mutations (add/update/remove/reorder component) use
  exact-match `tenantScopedKey([...BUNDLES_KEY, 'detail', bundleId])`
  — preserves the previous singular-detail-only invalidation.
- All `onSuccess` handlers async + awaited.

**Gate 6 — Regression tests cover scoped key shapes, no-fetch, broad
vs exact mutation refetch counters, tenant-B cache preservation: PASS.**
3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/workshop-bundles/hooks/__tests__/tenantScope.test.tsx`:
  **3/3 pass**.
- `php artisan sweep:inventory:verify-history`: 4534 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
