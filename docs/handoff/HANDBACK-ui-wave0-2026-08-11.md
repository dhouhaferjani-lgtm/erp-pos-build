# UI Wave 0 implementer report — M0b in progress

## Header

- Curated base SHA: `d682b38ec9761a917b9716428091a482745795f6`
- Branch: `codex/ui-wave0-2026-08-11`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0`
- Archived pre-repin evidence branch: `codex/ui-wave0-2026-08-11-pre-repin` at `89d83c6c4a56ce7bc6e351867e90451f0f35c218`
- Commit series: M0 uses `Phase 0.0.<seq>`; M0b uses `Phase 0.0b.<seq>`
- Wave status: `in_progress` in M0b; M1 has not started.

## M0 repin

- Renamed the original branch and removed its worktree only after confirming it was clean.
- Recreated the required branch/worktree directly from the curated SHA without fetching.
- Restored the dispatch brief and progress register, set `base_sha` to the exact ruled SHA, inserted M0b between M0 and M1, and installed dependencies with `pnpm install --frozen-lockfile`.
- M0 control commit: `3dcb31d72bf36dedca6a9dda125b94c1ae16fbd5` (`Phase 0.0.1: Re-pin UI Wave 0 controls`).

## M0b failing-set inventory

The initial full `apps/web pnpm test` run reported 20 failed files, 45 failed tests, 4,179 passed, 1 skipped, and 3 todo. A structured full repeat reported 19 failed files, 44 failed tests, 4,180 passed, 1 skipped, and 3 todo. The one-run difference was a timeout in `CreateStockTransferPage.availability.test.tsx` that did not recur.

The repeat's 19 failing files were:

1. `tools/__tests__/offset-pagination-meta-consolidation.test.mjs`
2. `src/__tests__/i18n/arLocaleCoverage.test.ts`
3. `src/components/__tests__/SharedSingletons.tenantScope.test.tsx`
4. `src/features/finance/api.test.ts`
5. `src/features/treasury/treasury.test.tsx`
6. `src/hooks/__tests__/usePermissions.expenseRecurrences.test.ts`
7. `src/features/expenses/__tests__/tenantScope.test.tsx`
8. `src/features/expenses/hooks/usePayExpense.test.tsx`
9. `src/features/expenses/pages/ExpenseDetailPage.test.tsx`
10. `src/features/inventory/pages/StockByLocationPage.test.tsx`
11. `src/features/pos/pages/reportPages.tenantScope.test.tsx`
12. `src/features/replenishment/__tests__/CreateTransferDialog.test.tsx`
13. `src/features/purchases/supplier-invoices/SupplierInvoiceListPage.test.tsx`
14. `src/features/treasury/__tests__/TreasuryTenantScope.test.tsx`
15. `src/features/finance/hooks/__tests__/tenantScope.test.tsx`
16. `src/features/owner-dashboard/hooks/__tests__/useOwnerReports.test.ts`
17. `src/features/pos/hooks/__tests__/useAnalytics.tenantScope.test.tsx`
18. `src/features/pos/pages/POSPage/POSPage.test.tsx`
19. `src/features/treasury/hooks/__tests__/cashPositionAndMovementsTenantScope.test.tsx`

## Confirmed hard-stop finding

`pnpm exec vitest run src/__tests__/i18n/arLocaleCoverage.test.ts` reproduces 3 failures and 7 passes in isolation:

- `ar/common.json`: 122 EN keys missing in AR.
- `ar/workshop-technicians.json`: 1 key missing (`authoring.tabsAriaLabel`).
- `ar/vehicles.json`: 19 keys missing.

This is a current product defect rather than stale test debt:

- The coverage test explicitly protects the AutoSpecs Tunisia go-live translation contract and self-tests its missing-key detector.
- `src/lib/i18n.ts` loads the incomplete Arabic bundles directly, labels these namespaces fully translated, and falls back to English for missing keys.
- The test entered in `f683715db750904fad8e45f57bcb49939d45a8c7`; later English locale changes added keys without maintaining Arabic parity.

A test-only edit could only remove namespaces or allow missing keys, masking the real fallback behavior. The actual repair requires production changes to Arabic locale JSON files, which violates M0b's zero-production-code constraint.

Parent ruling 2026-08-18 confirms this as an enumerated real-defect exception owned by `CODEX-DISPATCH-arabic-i18n-backfill-2026-08-10.md`; Arabic parity remains outside launch scope. M0b resumed to classify and repair the remaining failing files. Every exception must be independently confirmed by the bridge review.

## Scope and review state

- Test-only remediation is in progress; production files remain off-limits.
- No production files changed.
- The known UoM quantity and L3 `locationScopedKey` stale expectations remain in the working inventory.
- The M0b bridge review is pending completion of classification and test-only repairs.
- M1 and all later milestones remain pending.
