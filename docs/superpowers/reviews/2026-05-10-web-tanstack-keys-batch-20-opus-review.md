Commit reviewed: 0ed87e8b

# Opus review — web.tanstack-keys batch 20 (loyalty members)

Independent second-pair-of-eyes review of the Codex implementation
at `0ed87e8b`. All 7 review axes pass. Verdict APPROVE.

## Axis-by-axis findings

**Axis 1 — Scanner delta = 10 (651 → 641): PASS.** Cumulative live
count post-B20 = 641, matches final post-batch count exactly.

**Axis 2 — All query + predicate-based mutation hooks use state-value
selectors: PASS.** Pattern at useMembers.ts:
- :39-40 (useMembers), :49-50 (useMember), :103-104 (useEnrollments),
  :175-176 (useTransactions) — 4 query hooks
- :61-62 (useCreateMember), :82-83 (useUpdateMember), :112-113
  (useEnrollMember), :132-133 (useOptOutEnrollment), :152-153
  (useReactivateEnrollment), :195-196 (useAdjustPoints) — 6 mutations

Every site reads `useAuthStore((s) => s.user?.tenant_id ?? null)` +
`useCompanyStore((s) => s.currentCompanyId ?? null)`.

**Axis 3 — `membersInvalidationPredicate` gates by namespace + tail
t/c: PASS.** Predicate at useMembers.ts:24-37 matches
`k[0]==='loyalty-members' && k[k.length-2]===tenantId && k[k.length
-1]===companyId`. Pure namespace gate (no k[1] discriminator) because
all member-shape leaf keys (list, detail, enrollments, transactions)
share the same `loyalty-members` root and should ALL be invalidated
by member-level mutations.

**Axis 4 — Mutation onSuccess async + awaited: PASS.** All 6 mutations
use `onSuccess: async () => { await queryClient.invalidateQueries({
predicate: membersInvalidationPredicate(tenantId, companyId) }) }`.
Pattern at useMembers.ts:66-71, :87-92, :117-122, :137-142, :157-162,
:200-205.

**Axis 5 — L7 per-call counter cascade present: PASS.** 4 cascade
tests via shared `expectListRefetchAfterMutation` helper:
- useCreateMember refetches active tenant member queries (.346)
- useUpdateMember refetches active tenant member queries (.347)
- enrollment mutations (enroll, optOut, reactivate) refetch (.349-.351)
- useAdjustPoints refetches active tenant member queries (.353)

Each test drives `mutateAsync(...)`, asserts listCalls goes 1→2.
Vacuous predicate would leave listCalls at 1 and fail.

**Axis 6 — L18 cross-tenant DATA isolation present: PASS.** Test at
useMembers.tenantScope.test.tsx in the `cross-tenant member isolation`
describe block uses a custom QueryClient with `gcTime: Infinity`,
pre-seeds tenant-B `['loyalty-members', params, 'tenant-B',
'company-1']` with `memberListResponse([memberFixture('leaked-tenant-
b-member')])`, renders tenant-A `useMembers(params)`, asserts:
- tenant-A data.data === []
- tenant-A member IDs do NOT contain 'leaked-tenant-b-member'
- tenant-B cache entry SURVIVES unchanged

This is the strict L18 shape.

**Axis 7 — Existing useMembers.test.ts patched for new gates: PASS.**
useMembers.test.ts gets minimal additions: imports for useAuthStore +
useCompanyStore, a `setTenant` helper, and `beforeEach` / `afterEach`
that call `setTenant('tenant-A', 'company-1')` and reset. No
behavior assertions changed.

Bonus: MEMBERS_KEY const is now `export const ... as const` (was
private const) — allows the test to assert the factory shape
directly. Minimal API surface expansion.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/loyalty/hooks/__tests__/useMembers.tenantScope.test.tsx src/features/loyalty/hooks/__tests__/useMembers.test.ts`:
  **12/12 pass** (9 tenantScope + 3 pre-existing useMembers.test).
- `audit-tanstack-keys`: live count 641 (post-B20, matches expected
  delta from 651).
- `php artisan sweep:inventory:verify-history`: 3696 events / 1205
  callsites / 0 problems (after B19 lock).

Verdict: APPROVE
