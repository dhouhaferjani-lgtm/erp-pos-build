# UI Wave 0 implementer report — M0b in progress

## Header

- Curated base SHA: `d682b38ec9761a917b9716428091a482745795f6`
- Branch: `codex/ui-wave0-2026-08-11`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0`
- Archived pre-repin evidence branch: `codex/ui-wave0-2026-08-11-pre-repin` at `89d83c6c4a56ce7bc6e351867e90451f0f35c218`
- Commit series: M0 uses `Phase 0.0.<seq>`; M0b uses `Phase 0.0b.<seq>`
- M0b authority record: `docs/handoff/reviews/ui-wave0/OWNER-RULING-2026-08-18-M0b.md`
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

## Enumerated real-defect exceptions

### 1. Arabic locale coverage — owner-confirmed

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

### 2. Offset pagination meta consolidation — bridge confirmation pending

`tools/__tests__/offset-pagination-meta-consolidation.test.mjs` is a valid architecture test and remains unchanged. It identifies the inline pagination shape at `src/features/treasury/statements/api.ts:112`, reintroduced after the shared `OffsetPaginationMeta` consolidation. Matching the test to production would conceal duplicated production type ownership, so this is a real product-code regression rather than stale test debt.

- Historical owning lane: `CODEX-replenishment-followups-2026-07-12.md`, Wave B — pagination-meta consolidation (closed).
- Forward owner: the parent terminal audit must route this to a live production-fix lane before merge; it is not accepted as a standing gap.
- Production site: `StatementListResponse.meta` in `src/features/treasury/statements/api.ts`.
- Disposition: enumerated candidate exception; M0b bridge must confirm.

### 3. Shared singleton cross-tenant invalidation — bridge confirmation pending

`src/components/__tests__/SharedSingletons.tenantScope.test.tsx` is a valid tenant-isolation test and remains unchanged. `AddQuickProductModal` currently calls `invalidateQueries({ queryKey: ['products'] })`, which also marks a seeded foreign-tenant product cache entry invalid. The `cc1332fd` sweep explicitly preserved four tenant-precise invalidations protected by tenant-scope tests but missed this already-pinned site. Changing the assertion to accept that behavior would weaken the existing tenant boundary.

- Historical owning lane: promoted tenant-scoped invalidation sweep, commit `cc1332fd5266a52397656e39d09d8fc8200c5e34` (closed and causal, not a forward owner).
- Forward owner: the parent terminal audit must route this launch-program tenant-isolation defect to a live production-fix lane before merge; it is not accepted as a standing gap.
- Production site: `src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx:209`.
- Disposition: enumerated candidate exception; M0b bridge must confirm.

Failure scenario: a quick-product create under tenant A marks a cached tenant-B product slot invalid. On a later tenant switch, that invalidated slot refetches under the then-active session and risks repopulating a tenant-B-keyed entry from the wrong tenant response. The forward fix should use the tenant-precise invalidation pattern already preserved at the four explicit exception sites in `cc1332fd`.

Adjacent out-of-M0b finding for the same production visit: `AddQuickProductModal.tsx:201,203` uses `parseFloat` on price and tax-rate values, contrary to Rule 19. It is pre-existing at the pinned base and cannot be changed in this tests-only milestone.

None of these defects invalidates the UI audit tasks dispatched in M1–M8.

## Tests-only remediation

Commit `3e87358c5197cbee1fbff10c4c63fef119da9f99` (`Phase 0.0b.3: Align stale web tests`) changes 17 test files and zero production files. Each corrected expectation or harness carries a one-line citation to the promoted lane that changed the behavior. The repairs cover:

- L3 location-scoped keys, filters, and report request parameters.
- UoM unit-precision quantity display.
- Generated permission-map ordering and authorized-role fixtures.
- Repository company-config, statement-reconciliation, stock-rebalancing, and transaction-location harnesses.
- Partner edit hydration before bank-account interaction.

The complete repaired subset passes: 17 files and 168 tests.

## M0b verification evidence

- `pnpm test -- --maxWorkers=1 --reporter=json --outputFile=/tmp/ui-wave0-m0b-full-single-worker.json`: deterministic canonical gate; 4,224 passed, 5 failed, 1 skipped, 3 todo. The only failing files are the three enumerated real-defect exceptions above.
- `pnpm test -- --maxWorkers=4 --reporter=json`: one executor run produced 4,224 passed, 5 failed, 1 skipped, 3 todo, with the five failed assertions belonging only to the three enumerated exception files above. The round-1 reviewer reproduced additional load-only flakes at four workers, so this is superseded by the single-worker gate above.
- The unconstrained full run also reproduced all three exception files; timing/contention failures in `ReviewIngestionPage.test.tsx`, `PartnerForm.test.tsx`, `PosHubPage.test.tsx`, and `SupplierInvoiceDetailPage.test.tsx` passed in a focused rerun and were not classified as defects. The observed partner hydration race was repaired test-only and now passes in the 168-test repaired subset.
- `pnpm typecheck`: pass.
- `pnpm lint`: pass with the repository's pre-existing warning inventory; TanStack keys report 0 new/0 stale, design-system audit 0 new/0 stale, quantity audit 0 new/0 stale, and all custom ESLint rule tests pass.
- `npx react-doctor@latest --verbose --scope changed --base d682b38ec9761a917b9716428091a482745795f6`: 100/100, no issues across 17 changed web files.

## M0b review rounds

- Round 1: `docs/handoff/reviews/ui-wave0/M0b-round1.md` — `CHANGES-REQUIRED`. The reviewer confirmed zero production changes and all stale-expectation fixes, but required a deterministic full-suite command, forward routing for the two newly discovered production defects, stronger tenant-risk characterization, and evidence/comment corrections.
- Round 2: `docs/handoff/reviews/ui-wave0/M0b-round2.md` — `ACCEPT`. The reviewer independently reproduced 4,224 passed / 5 failed under `--maxWorkers=1`, confirmed the failing files are exactly the three enumerated real defects, verified 168/168 repaired tests plus typecheck and lint, and confirmed zero production changes.

## Scope and review state

- Test-only remediation and local verification are complete; production files remain unchanged.
- M0b is bridge-accepted with the three enumerated real-defect exceptions above.
- M1 and all later milestones remain pending; later test gates use `--maxWorkers=1` and inherit only those reviewed exceptions unless a new real defect is individually confirmed.
