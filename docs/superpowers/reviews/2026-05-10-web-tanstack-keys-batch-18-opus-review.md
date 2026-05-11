Commit reviewed: cd90b658

# Opus review — web.tanstack-keys batch 18 (locations)

Independent second-pair-of-eyes review of the Codex implementation
at `cd90b658`. All 5 review axes pass. Verdict APPROVE.

## Axis-by-axis findings

**Axis 1 — Scanner delta = 2 (659 → 657): PASS.** Cumulative live
count post-B20 = 641, consistent with sum of per-batch deltas.

**Axis 2 — Both query hooks use state-value selectors: PASS.**
- useLocations.ts:23-24 reads `useAuthStore((s) => s.user?.tenant_id
  ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`.
- useLocations.ts:38-39 same pattern for `useLocation(id)`.

**Axis 3 — `useLocation(id)` preserves id guard + adds tenant/company:
PASS.** useLocations.ts:43 reads `enabled: Boolean(id) && !!tenantId
&& !!companyId`. `useLocations()` (no pre-existing condition) gets
`enabled: !!tenantId && !!companyId`.

**Axis 4 — L18 cross-tenant DATA isolation present: PASS.** Test at
useLocations.tenantScope.test.tsx in the `cross-tenant location
isolation` describe block uses a custom QueryClient with
`gcTime: Infinity`, pre-seeds tenant-B `['locations', 'list',
'tenant-B', 'company-1']` with `[locationFixture('leaked-tenant-b-
location')]`, renders tenant-A `useLocations`, asserts:
- tenant-A list data === []
- tenant-A IDs do NOT contain 'leaked-tenant-b-location'
- tenant-B cache entry SURVIVES unchanged

This is the strict L18 shape.

**Axis 5 — L7 not applicable (query-only file): PASS.** Test file's
4th case `does not fetch without tenant/company scope` proves the
enabled gate gates real fetches. No mutation hooks exist in
useLocations.ts so cascade testing is correctly omitted.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/locations/`: **5/5 pass** across
  1 file (4 useQuery shape probes + 1 cross-tenant data isolation).
- `audit-tanstack-keys`: live count 641 (post-B20 cumulative).
- `php artisan sweep:inventory:verify-history`: 3688 events / 1205
  callsites / 0 problems (after B17 lock).

Bonus rigor: tests verify queryKeys differ across tenants by JSON-
serializing both caches and asserting non-equality + presence of
expected tenant markers.

Verdict: APPROVE
