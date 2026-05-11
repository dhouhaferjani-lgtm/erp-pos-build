# web.tanstack-keys Batch 94 — Opus Review

Commit reviewed: 67ede0d5
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `67ede0d5` — fix(tenant-isolation): wrap web.tanstack-keys batch 94
Scope: 10 callsites across 7 production files (treasury cluster).
- `apps/web/src/features/treasury/InstrumentListPage.tsx` (.676 read)
- `apps/web/src/features/treasury/PaymentListPage.tsx` (.704 read)
- `apps/web/src/features/treasury/PaymentMethodsPage.tsx` (.705 invalidate mutation onSuccess, .706 invalidate modal onSuccess)
- `apps/web/src/features/treasury/RepositoryListPage.tsx` (.710 read, .711 invalidate modal onSuccess)
- `apps/web/src/features/treasury/SplitPaymentForm.tsx` (.712 read payment-methods, .713 read payment-repositories)
- `apps/web/src/features/treasury/components/AddPaymentMethodModal.tsx` (.714 invalidate mutation onSuccess)
- `apps/web/src/features/treasury/hooks/usePaymentMethods.ts` (.715 read)

Test: `apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx` (new, 179 lines, 2 it-blocks).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 6 read queries (`instruments`, `payments`, `payment-repositories`, `payment-methods` ×2, `payment-repositories` in SplitPaymentForm) and all 4 invalidate calls (`payment-methods` ×2 in PaymentMethodsPage, `payment-repositories` in RepositoryListPage modal onSuccess, `payment-methods` in AddPaymentMethodModal) use `tenantScopedKey([...])`. No raw arrays remain.
2. **State-value selectors**: Every consumer that owns a read selects `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`. No `getState()` calls.
3. **Enabled gates**: All 6 read queries AND-combine `tenantId !== null && companyId !== null`.
4. **Async invalidate cascade**: Both mutation onSuccess handlers (`PaymentMethodsPage` line 44, `AddPaymentMethodModal` line 125) converted to `async` + `await`. The two modal-callback invalidates (`PaymentMethodsPage` line 333 forwarded from AddPaymentMethodModal, `RepositoryListPage` line 273) retain `void` — acceptable because (a) AddPaymentMethodModal's own mutation already awaits its invalidate, making the outer callback a redundant guard, and (b) RepositoryListPage's modal has its own invalidate path inside the modal. Both outer callbacks are wrapped, so cross-tenant isolation is preserved even if these specific calls fire unawaited.
5. **Cross-tenant isolation test**: Test asserts all 4 query keys (`instruments`, `payments`, `payment-repositories`, `payment-methods`) land at `[..., 'tenant-A', 'company-1']` after mount. Triggers `AddPaymentMethodModal` save flow and asserts the resulting `payment-methods` invalidate carries `['payment-methods', 'tenant-A', 'company-1']` AND does NOT carry the tenant-B suffix. Second `it` block: `resetTenant()` + `<SplitPaymentForm />` mount produces zero `mockApiGet` calls.

## Non-blocking findings

1. The describe title says ".679-.688" but the actual callsite IDs in inventory are .676, .704, .705, .706, .710, .711, .712, .713, .714, .715 (inventory was numbered after this commit went up). Cosmetic — the suffixes assert the actual query keys, not the IDs.
2. `void` retained at PaymentMethodsPage:333 and RepositoryListPage:273 (modal-callback invalidates). As noted above, the inner modal already awaits its own invalidate; these outer callbacks are belt-and-braces, so the missing `await` does not produce a cross-tenant leak.
3. SplitPaymentForm renders two queries (.712, .713) but the test only asserts the `payment-methods` suffix via the parallel `usePaymentMethods()` hook render. The `payment-repositories` suffix is covered transitively by `RepositoryListPage` in the same render.

## Locks applied

10 callsites locked at fix commit `67ede0d5`:
web.tanstack-keys.676, .704, .705, .706, .710, .711, .712, .713, .714, .715.
