# UI Wave 0 implementer report — M1 review pending

## Header

- Curated base SHA: `d682b38ec9761a917b9716428091a482745795f6`
- Branch: `codex/ui-wave0-2026-08-11`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0`
- Archived pre-repin evidence branch: `codex/ui-wave0-2026-08-11-pre-repin` at `89d83c6c4a56ce7bc6e351867e90451f0f35c218`
- Commit series: M0 uses `Phase 0.0.<seq>`; M0b uses `Phase 0.0b.<seq>`; T1 uses `Phase 0.1.<seq>`.
- M0b authority record: `docs/handoff/reviews/ui-wave0/OWNER-RULING-2026-08-18-M0b.md`
- Wave status: M0b passed; M1 implementation is committed and awaiting bridge review.

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

### 2. Offset pagination meta consolidation — bridge-confirmed

`tools/__tests__/offset-pagination-meta-consolidation.test.mjs` is a valid architecture test and remains unchanged. It identifies the inline pagination shape at `src/features/treasury/statements/api.ts:112`, reintroduced after the shared `OffsetPaginationMeta` consolidation. Matching the test to production would conceal duplicated production type ownership, so this is a real product-code regression rather than stale test debt.

- Historical owning lane: `CODEX-replenishment-followups-2026-07-12.md`, Wave B — pagination-meta consolidation (closed).
- Forward owner: the parent terminal audit must route this to a live production-fix lane before merge; it is not accepted as a standing gap.
- Production site: `StatementListResponse.meta` in `src/features/treasury/statements/api.ts`.
- Disposition: enumerated real-defect exception confirmed by the M0b round-2 bridge.

### 3. Shared singleton cross-tenant invalidation — bridge-confirmed

`src/components/__tests__/SharedSingletons.tenantScope.test.tsx` is a valid tenant-isolation test and remains unchanged. `AddQuickProductModal` currently calls `invalidateQueries({ queryKey: ['products'] })`, which also marks a seeded foreign-tenant product cache entry invalid. The `cc1332fd` sweep explicitly preserved four tenant-precise invalidations protected by tenant-scope tests but missed this already-pinned site. Changing the assertion to accept that behavior would weaken the existing tenant boundary.

- Historical owning lane: promoted tenant-scoped invalidation sweep, commit `cc1332fd5266a52397656e39d09d8fc8200c5e34` (closed and causal, not a forward owner).
- Forward owner: the parent terminal audit must route this launch-program tenant-isolation defect to a live production-fix lane before merge; it is not accepted as a standing gap.
- Production site: `src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx:209`.
- Disposition: enumerated real-defect exception confirmed by the M0b round-2 bridge.

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
- Later test gates use `--maxWorkers=1` and inherit only those reviewed exceptions unless a new real defect is individually confirmed.

## M1 — T1 component-graph-aware listing census

Commit `7512553248a91c9be99f03265158d5bd8554d4de` (`Phase 0.1.1: Build graph-aware listing census`) adds exactly the three T1 deliverables. Review-fix commit `5383a246753b5820b5f9ca4de51ae1f03c6b9f0f` (`Phase 0.1.3: Resolve M1 review findings`) tightens the analyzer and report without touching production source:

- `apps/web/tools/audit-listing-census.mjs`: filesystem discovery, feature-local rendered-import traversal, source-attributed listing classification, route-tree extraction, and navigation-reference reachability. It is not chained into lint or CI and exits 0 as an analysis tool.
- `apps/web/tools/__tests__/audit-listing-census.test.mjs`: 12 tests runnable under both `node --test` and Vitest, including nested organism pagination, empty state, filters, dynamic param links, bounded prefix-dynamic links, generic-template rejection, compound-condition protection, breadcrumbs, and the real Expense/Bundles worked examples.
- `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md`: reproducible report and machine-readable tables.

Red-first evidence: `node --test tools/__tests__/audit-listing-census.test.mjs` initially failed with `ERR_MODULE_NOT_FOUND` before the tool existed. The current Node and Vitest suites both report 12/12.

The real scan discovers 46 listing pages in 1–5 seconds across recorded runs. It re-derives 22 pages with pagination and 24 without; 45 pages with an empty-state mechanism and one redirect stub without; the six primary filter patterns; `ListPageLayout` at 12/46; and `DataTable` at 35/46. The report states that this filename population is inherited from the original audit, not an independent whole-product population proof: 43/46 graphs remain file-local, the `ListView` and `IndexPage` suffixes match zero files, and a conservative reproducible cross-check finds 33 route-mounted non-detail `DataTable` pages outside the cohort. The route pass scans 271 registered records and emits 27 raw candidates: 15 views, four parameterized views, and eight action/forms. Manual call-flow review confirms five of those action/form rows are linked, leaving 22 candidates (15 views, four parameterized views, three action/forms). UI-34 remains seven top-level views, while the parameterized Ecommerce cluster is included rather than excluded.

M1 verification:

- `node --test tools/__tests__/audit-listing-census.test.mjs`: 12/12 pass.
- `pnpm exec vitest run tools/__tests__/audit-listing-census.test.mjs`: 12/12 pass.
- `node tools/audit-listing-census.mjs`: exit 0; 46 listing rows and 27 orphan-candidate rows agree with the committed report.
- First full `pnpm test -- --maxWorkers=1` run: the three reviewed exceptions plus one stock-transfer FEFO timing miss; that file then passed 7/7 in isolation.
- Clean full repeat: 4,234 passed, 5 failed, 1 skipped, 3 todo; the failing files are exactly the three M0b bridge-confirmed exceptions.
- Post-review-fix full run: 4,236 passed, 5 failed, 1 skipped, 3 todo; the two-test increase is the new M1 regression coverage and the failing files remain exactly the three M0b bridge-confirmed exceptions.
- `pnpm typecheck`: pass.
- `pnpm lint`: pass with 0 errors and the existing 6,530 warnings; key/design/quantity audits and custom rules remain clean.
- React Doctor changed-scope scan: 100/100, no issues.

M1 bridge round 1 (`docs/handoff/reviews/ui-wave0/M1-round1.md`) returned `CHANGES-REQUIRED`. Its two P2 and three substantive P3 findings are addressed by `5383a2467`: the report discloses the inherited population and graph limits; the extractor resolves conditional base paths and literal props supplied to shared navigation components; and the empty-state classifier now evaluates the relevant AST comparison rather than spanning arbitrary condition text.

M1 bridge round 2 (`docs/handoff/reviews/ui-wave0/M1-round2.md`) also returned `CHANGES-REQUIRED`. Commit `72c0207b41afdda1fa18cbec109a33ef7456bebb` (`Phase 0.1.5: Clarify M1 reachability evidence`) addresses its evidence findings: the report distinguishes the raw 27 scanner candidates from the 22 remaining after manual review; identifies the four shared-document Add targets and income edit link; corrects the inherited-rule source to `02-design-system-consistency.md:66`; documents the literal-prop union approximation; changes the secondary-surface claim to a conservative 33 with an explicit reproduction rule; and reconciles the timing and test-count evidence. Bridge round 3 is pending. M2 and later milestones have not started.
