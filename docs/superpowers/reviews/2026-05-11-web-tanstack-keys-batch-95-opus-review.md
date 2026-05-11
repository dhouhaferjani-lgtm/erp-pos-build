# web.tanstack-keys Batch 95 — Opus Review

Commit reviewed: 52f111ad
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `52f111ad` — fix(tenant-isolation): wrap web.tanstack-keys batch 95
Scope: 7 callsites across 6 production files.
- `apps/web/src/features/settings/components/ReceiptSettingsTab.tsx` (.652 read, .653 invalidate onSuccess)
- `apps/web/src/features/settings/components/SetupChecklist.tsx` (.654 read)
- `apps/web/src/features/settings/components/UserEditModal.tsx` (.655 invalidate onSuccess)
- `apps/web/src/features/vat-reporting/hooks/useVatPeriods.ts` (.752 read)
- `apps/web/src/features/vehicles/VehicleDetailPage.tsx` (.755 invalidate onSuccess)
- `apps/web/src/features/vehicles/VehicleListPage.tsx` (.761 read)

Test: `apps/web/src/features/settings/__tests__/TailTenantScope.test.tsx` (new, 208 lines, 2 it-blocks).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 4 read queries (`receipt-settings`, `onboarding-status`, `vat-periods`, `vehicles`) and all 3 invalidate calls (`receipt-settings`, `users`, `vehicles`) use `tenantScopedKey([...])`. No raw arrays remain.
2. **State-value selectors**: Each consumer selects `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)` rather than calling `getState()`, so subscription updates trigger re-renders on tenant/company switch.
3. **Enabled gates**: `ReceiptSettingsTab` AND-combines existing `!!currentCompany?.id` with `tenantId !== null && companyId !== null`. `SetupChecklist`, `useVatPeriods`, and `VehicleListPage` all gate on `tenantId !== null && companyId !== null`. `UserEditModal` and `VehicleDetailPage` are mutation-only — no read to gate.
4. **Async invalidate cascade**: `ReceiptSettingsTab.onSuccess`, `UserEditModal.onSuccess`, and `VehicleDetailPage.onSuccess` are now `async` and `await queryClient.invalidateQueries({...})` so downstream awaits resolve only after refetch enqueues.
5. **Cross-tenant isolation test**: Test asserts the four read queries land at `['…', 'tenant-A', 'company-1']`, asserts the `users` invalidate hits `['users', 'tenant-A', 'company-1']`, and asserts it does NOT hit `['users', 'tenant-B', 'company-2']`. Second `it` block asserts `resetTenant()` followed by `<VehicleListPage />` mount produces zero `mockApiGet` calls.

## Non-blocking findings

1. The negative assertion `expect(invalidateSpy).not.toHaveBeenCalledWith({ queryKey: ['users', 'tenant-B', 'company-2'] })` is trivially satisfied because the test never switches to tenant-B. Adequate as a regression guard but does not exercise post-switch behavior; the per-cluster tenantSwitchCacheInvalidation suite owns that path.
2. `useVatPeriods` is exercised via `renderHook` parallel to the JSX render — the resulting query lands in the same `queryClient`, so the suffix assertion works, but the wrapper indirection is slightly awkward. Cosmetic only.
3. `mockApiGet` for `/vehicles*` returns `{ data: [], meta: { total: 0 } }` while the page reads `data?.data ?? []`; this is fine because the page only consumes the `data` array, but the suffix assertion `['vehicles', '', 'tenant-A', 'company-1']` confirms the query did execute.

## Locks applied

7 callsites locked at fix commit `52f111ad`:
web.tanstack-keys.652, .653, .654, .655, .752, .755, .761.
