Commit reviewed: 9b9fc606

# Opus review — web.tanstack-keys batch 17 (inventory counting)

Independent second-pair-of-eyes review of the Codex implementation
at `9b9fc606`. All 8 review axes pass. Verdict APPROVE.

## Axis-by-axis findings

**Axis 1 — Scanner delta = 17 (676 → 659): PASS.** Cumulative live
count post-B20 = 641, consistent with sum of per-batch deltas.

**Axis 2 — All 17 callsites wrapped/predicate-scoped: PASS.** Single
file `api/queries.ts` — 5 useQuery hooks all wrapped with
`tenantScopedKey([...countingKeys.*(...)])`; 6 mutation onSuccess
blocks switched to predicate (for list) + tenantScopedKey
exact-match (for dashboard/detail/reconciliation).

**Axis 3 — State-value selectors in all 5 query hooks: PASS.** Every
hook subscribes to `useAuthStore((s) => s.user?.tenant_id ?? null)`
+ `useCompanyStore((s) => s.currentCompanyId ?? null)`. Pattern at
queries.ts:35-36, :44-45, :52-53, :61-62, :70-71.

**Axis 4 — Existing enabled gates preserved + extended: PASS.**
`useCountingDetail` keeps `!!id`, `useReconciliation` /
`useDiscrepancyReport` keep `!!countingId`, all gated with
`&& !!tenantId && !!companyId`. `useCountingDashboard` and
`useCountingList` add new tenant/company gates.

**Axis 5 — Mutation cascades await invalidation: PASS.** All 6
mutations (create, activate, cancel, finalize, triggerThirdCount,
manualOverride) use `async onSuccess` with `await Promise.all([...])`.

**Axis 6 — Predicate gates on namespace + list discriminator + t/c
suffix: PASS.** `countingListInvalidationPredicate` at queries.ts:22-36
matches `k[0]==='counting' && k[1]==='list' && tail t/c`. The `k[1]
==='list'` discriminator (B14 lesson) intentionally rejects detail/
dashboard/reconciliation/report keys to avoid double-invalidate when
paired with the exact-match wraps on those singular slots. Test at
tenantScope.test.tsx:131-149 covers positive (list) + 6 negative
cases (detail, dashboard, reconciliation, report, wrong-tenant,
wrong-company).

**Axis 7 — L7 per-call counter cascade present: PASS.** Two cascade
tests at tenantScope.test.tsx:
- `useCreateCounting cascades counting.list + dashboard with
  per-call counters` (.324, .325) — drives `create.mutateAsync(...)`,
  asserts listCalls + dashboardCalls go 1→2 while detailCalls stays
  at 1 (predicate-correctly skips detail slot).
- `useActivateCounting cascades exact detail + dashboard keys`
  (.326, .327) — drives `activate.mutateAsync(7)`, asserts detailCalls
  + dashboardCalls go 1→2 while listCalls stays at 1 (exact-match
  wrap on detail; list NOT cascaded by activate).

Both tests would fail with an always-false predicate or missing wrap.

**Axis 8 — L18 cross-tenant DATA isolation present: PASS.** Test at
tenantScope.test.tsx:'cross-tenant counting isolation' uses a custom
QueryClient with `gcTime: Infinity`, pre-seeds tenant-B counting list
with `[{id: 90, uuid: 'leaked-tenant-b-counting'}]`, renders tenant-A
`useCountingList`, asserts:
- tenant-A data.data === []
- tenant-A uuid array does NOT contain 'leaked-tenant-b-counting'
- tenant-B cache entry SURVIVES unchanged

This is the strict L18 shape.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/inventory-counting/`: **18/18 pass**
  across 3 files (8 tenantScope + 3 CountingListPage + 7
  CreateCountingPage).
- `audit-tanstack-keys`: live count 641 (post-B20 cumulative).
- `php artisan sweep:inventory:verify-history`: 3671 events / 1205
  callsites / 0 problems (after B16 lock).

Verdict: APPROVE
