# web.tanstack-keys Batch 69 — Opus Review

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `8c002141`
Scope: `apps/web/src/features/catalog/components/CompositeItemSearchSelect.tsx` — 2 callsites (web.tanstack-keys.053-054).
Test: `apps/web/src/features/catalog/components/__tests__/CompositeItemSearchSelect.test.tsx`

Scanner: 223 → 221 (-2 violations). Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Both queries wrapped**: `composite-items-search` and `composite-item-selected` use `tenantScopedKey([...])`.
2. **Both queries gated**: state-value selectors `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`. Search query `enabled` AND-combines existing `isOpen` with tenant/company nullness; selected-item query AND-combines `Boolean(value) && !isOpen`.
3. **Tests pass (11/11)**: pre-existing scenarios preserved.
4. **Test additions**:
   - Selected-item suffix asserted: `queryClient.getQueryData(['composite-item-selected', 'selected-id', 'tenant-A', 'company-1'])` returns the expected payload.
   - Tenantless gating asserted: after `resetTenant()`, mock fetch call count is unchanged.

## Non-blocking findings

1. `afterEach` is used (line 68) but not in the vitest named-import list at line 1; works only because `vitest.config.ts` sets `globals: true`. Consistency nit.
2. The suffix assertion and tenantless gating share one `it` block; splitting would improve failure isolation.
3. The tenantless-gating assertion exercises only the `composite-item-selected` path. `composite-items-search` shares identical gating logic but requires an extra `user.click(trigger)` to open the popover; the chosen path is the simpler representative.

## Locks applied

2 callsites locked at fix commit `8c002141`.
