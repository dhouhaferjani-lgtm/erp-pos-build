Commit reviewed: ef4d99a4

# Opus review — web.tanstack-keys batch 19 (loyalty earning rules)

Independent second-pair-of-eyes review of the Codex implementation
at `ef4d99a4`. All 5 review axes pass. Verdict APPROVE.

## Axis-by-axis findings

**Axis 1 — Scanner delta = 6 (657 → 651): PASS.** Cumulative live
count post-B20 = 641, consistent with sum of per-batch deltas.

**Axis 2 — `useEarningRules` uses state-value selectors: PASS.**
useEarningRules.ts:21-22 reads `useAuthStore((s) => s.user?.tenant_id
?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`.

**Axis 3 — All 5 mutation onSuccess handlers are async + await: PASS.**
- useCreateEarningRule.ts:34-37 — async onSuccess + await invalidate
- useUpdateEarningRule.ts:48-51 — same
- useDeleteEarningRule.ts:62-65 — same
- useActivateEarningRule.ts:76-79 — same
- useDeactivateEarningRule.ts:90-93 — same

Each invalidates `tenantScopedKey([...earningRulesKey(programId)])` —
exact-match wrap, same shape as the leaf useQuery. No predicate
needed (B3 lesson 4: when invalidate shape matches leaf shape,
exact-match suffices).

**Axis 4 — L7 per-call counter cascade present: PASS.** 5 cascade
tests via shared `expectListRefetchAfterMutation` helper, one per
mutation hook (.339-.343). Each:
1. Sets up `mockListEarningRules` with a per-call counter.
2. Renders `useEarningRules('program-1')`, asserts initial fetch
   (listCalls === 1).
3. Drives `mutateAsync(...)` on the relevant mutation hook.
4. Asserts listCalls === 2 (refetch fired after invalidate).

Vacuous wrap would leave listCalls at 1 and fail.

**Axis 5 — L18 cross-tenant DATA isolation present: PASS.** Test at
useEarningRules.tenantScope.test.tsx in the `cross-tenant earning
rule isolation` describe block uses a custom QueryClient with
`gcTime: Infinity`, pre-seeds tenant-B `['loyalty-earning-rules',
'program-1', 'tenant-B', 'company-1']` with `[earningRuleFixture(
'leaked-tenant-b-rule')]`, renders tenant-A `useEarningRules(
'program-1')`, asserts:
- tenant-A data === []
- tenant-A IDs do NOT contain 'leaked-tenant-b-rule'
- tenant-B cache entry SURVIVES unchanged

This is the strict L18 shape.

Bonus: `earningRulesKey` is now exported (was private const before)
to allow direct factory contract testing — minimal API surface
expansion, used only by the test.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/loyalty/hooks/__tests__/useEarningRules.tenantScope.test.tsx`:
  **9/9 pass** (1 factory + 1 useQuery shape probe + 1 enabled-gate +
  5 cascade tests + 1 L18 data isolation).
- `audit-tanstack-keys`: live count 641 (post-B20 cumulative).
- `php artisan sweep:inventory:verify-history`: 3690 events / 1205
  callsites / 0 problems (after B18 lock).

Verdict: APPROVE
