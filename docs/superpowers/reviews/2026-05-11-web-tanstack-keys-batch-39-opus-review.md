Commit reviewed: 6d07073d

# Opus review — web.tanstack-keys batch 39 (scheduling hooks)

Independent second-pair-of-eyes review of the Codex implementation
at `6d07073d`. All 6 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 21 (468 → 447): PASS.** Single-file
useScheduling.ts: ~7 read query hooks (appointmentList, appointment-
Detail, bays, config, day, week, freeSlots) + ~7 mutations each with
1+ invalidation arms. Live scanner reads 409 (cumulative post-B42
with unrelated workshop-technicians working-tree drift); per-batch
delta verified by file scope.

**Gate 2 — Query keys tenant/company scoped: PASS.** All scheduling
reads wrap via `tenantScopedKey([...schedulingKeys.<fn>(...)])`:
- appointmentList(filters), appointmentDetail(id), bays(),
  config(locationId), day(date), week(weekStart), freeSlots(...)

**Gate 3 — State-value selectors (not whole-store): PASS.** Hook
reads:
- `useAuthStore((state) => state.user?.tenant_id ?? null)`
- `useCompanyStore((state) => state.currentCompanyId ?? null)`

**Gate 4 — Reads gated until tenant/company scope exists: PASS.**
Each useQuery preserves its existing `enabled` condition (e.g.,
`typeof id === 'string' && id.length > 0`) and AND-combines with
`hasTenantScope`. List/bays use plain `enabled: hasTenantScope`.

**Gate 5 — Mutation invalidations preserve old semantics + tenant-
bounded: PASS.** Codex's prompt notes the broad "all scheduling
namespaces" pre-fix invalidation was replaced with a single tenant/
company-bounded scheduling predicate. The 7 mutations all use
`onSuccess: async () => { await ... }` with predicate-based
invalidation matching the active tenant/company.

**Gate 6 — Regression tests cover scoped keys + no-fetch + per-call
counters + tenant-B preservation: PASS.** 3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/scheduling/hooks/__tests__/useScheduling.tenantScope.test.tsx`:
  **3/3 pass**.
- `php artisan sweep:inventory:verify-history`: 4534 events / 1205
  callsites / 0 problems (pre-lock).

Verdict: APPROVE
