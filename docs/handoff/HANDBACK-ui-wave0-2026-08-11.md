# UI Wave 0 implementer report — M2 accepted, M3 review fixes verified

## Header

- Curated base SHA: `d682b38ec9761a917b9716428091a482745795f6`
- Branch: `codex/ui-wave0-2026-08-11`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0`
- Archived pre-repin evidence branch: `codex/ui-wave0-2026-08-11-pre-repin` at `89d83c6c4a56ce7bc6e351867e90451f0f35c218`
- Commit series: M0 uses `Phase 0.0.<seq>`; M0b uses `Phase 0.0b.<seq>`; T1 uses `Phase 0.1.<seq>`.
- M0b authority record: `docs/handoff/reviews/ui-wave0/OWNER-RULING-2026-08-18-M0b.md`
- Wave status: M0b, M1, and M2 passed; M3 round-2 findings are repaired and verified, with round 3 pending.

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

M1 bridge round 2 (`docs/handoff/reviews/ui-wave0/M1-round2.md`) also returned `CHANGES-REQUIRED`. Commit `72c0207b41afdda1fa18cbec109a33ef7456bebb` (`Phase 0.1.5: Clarify M1 reachability evidence`) addresses its evidence findings: the report distinguishes the raw 27 scanner candidates from the 22 remaining after manual review; identifies the four shared-document Add targets and income edit link; corrects the inherited-rule source to `02-design-system-consistency.md:66`; documents the literal-prop union approximation; changes the secondary-surface claim to a conservative 33 with an explicit reproduction rule; and reconciles the timing and test-count evidence.

M1 bridge round 3 (`docs/handoff/reviews/ui-wave0/M1-round3.md`) returned `ACCEPT`. The reviewer independently reproduced 46 listings, 271 route records, the 27 raw-candidate split, every listing distribution, 12/12 tests under both runners, and zero production changes. Two terminal-audit items remain explicit: applying the report's stated secondary-surface rule literally produced 35 rather than the reported 33 (P2 close-before-merge), and the mandated `17-` report ordinal collides with an owner-untracked session file. The tool's known five-link manual correction remains disclosed rather than implemented in the analyzer. M2 is now in progress; later milestones have not started.

## M2 — T2 keyed route manifest resolution

Commit `daf0a8f5065184cae0bf8c60eb4422dd6ae387f9` (`Phase 0.2.1: Resolve keyed route manifest pages`) adds `KeyedByRouteId` to the manifest generator's explicit pure-wrapper allow-list with the required keying-only/no-visual-output comment. It adds two tests: the keyed wrapper resolves to its inner page, while an unknown local wrapper remains the recorded component.

Red-first evidence: `node --test scripts/factory/gen-route-manifest.test.mjs` reported 9 passed / 1 failed before the allow-list change. Node's assertion delta was exact (`+` is actual, `-` is expected):

```diff
+ component: 'KeyedByRouteId'
- component: 'InvoiceDetailPage'
```

After the fix, the suite passes 10/10. The scratch-generation evidence was:

```text
$ M2_TMP_DIR=$(mktemp -d /tmp/ui-wave0-m2.XXXXXX)
$ node scripts/factory/gen-route-manifest.mjs --out "$M2_TMP_DIR"
wrote /tmp/ui-wave0-m2.OcDtRm/routes-web.yaml (269 routes)
wrote /tmp/ui-wave0-m2.OcDtRm/routes-pos.yaml (9 routes, 9 phase screens)
$ rg -n -A3 -B1 'path: /sales/invoices/:id$' "$M2_TMP_DIR/routes-web.yaml"
773-    permission: null
774:  - path: /sales/invoices/:id
775-    component: InvoiceDetailPage
776-    module_gate: sales
777-    permission: null
```

The generated row is therefore:

```yaml
- path: /sales/invoices/:id
  component: InvoiceDetailPage
  module_gate: sales
  permission: null
```

`<KeyedByRouteId>` has exactly one route-tree use, at the invoice-detail mount, so this wrapper addition cannot change another manifest entry. `bash scripts/factory/check-manifest-drift.sh` still exits 1 for the expected pre-T3 route/permission drift but no longer prints an invoice-detail component hunk. No manifest file is changed or committed in M2; M3 route deletions must land before M4 performs the single authorized regeneration.

M2 bridge round 1 (`docs/handoff/reviews/ui-wave0/M2-round1.md`) returned `ACCEPT`. The reviewer independently regenerated both manifests before and after reverting only the new allow-list member: exactly one web-manifest line changed (`KeyedByRouteId` to `InvoiceDetailPage`) and the POS manifest was byte-identical. It also reproduced the 9/1 red and 10/10 green test runs and confirmed no manifest file was committed. Its P2 handback-evidence finding is closed by the pasted command/output above; its line-anchor note remains a terminal-audit item after M3 shifted `KeyedByRouteId` from line 325 to line 322.

## M3 — T10–T13 owner-ruled route and page deletions

The four required source commits are:

```text
d7f3ea21d1107e8a1cc30fd06987fa5f0989bf48 Phase 0.10.1: Delete retired web shift console
cdce8ab3874ab8fad75827f4466a680e93d283d8 Phase 0.11.1: Delete duplicate marketing hub
1184a83ac3b74ea74ba3b17c026de6bd4ab22e8b Phase 0.12.1: Delete retired finance hub
85bf6b02d9c1a8bb5ed42113a3fdb194562f999f Phase 0.13.1: Delete duplicate settings account route
9ac83f0eb6804d817fe00daffac62515e5003809 Phase 0.13.4: Resolve M3 review findings
```

### T10 — retired web shift console

The route, lazy import, `POSShiftsDashboard`, the orphaned `ShiftDashboardPage` subtree and barrels, component tests, and sole-consumer `shiftDashboard` / `shifts` locale groups were deleted. The supported `/pos/shift-history` route and links remain. The two POS markdown files were deliberately retained as historical records with the required dated supersession line at each file's top. The owner caveat about a browser sales-demo console was considered and is superseded by the explicit delete ruling; the parent should re-check that ruling at terminal audit.

The design-baseline edit was manual and exactly two lines:

```diff
-    "C2|src/features/pos/pages/ShiftDashboardPage/ShiftDashboardPage.tsx|..."
-    "C3|src/pages/POS/POSShiftsDashboard.tsx|..."
```

The full `git show d7f3ea21d -- apps/web/tools/audit-design-system-baseline.json` hunk contains only those two deletions. Current source/baseline greps are zero; the documentation grep returns only the two annotated historical files:

```text
$ grep -rnE "pos/shifts\"|'/pos/shifts'|POSShiftsDashboard|ShiftDashboardPage" apps/web/src --include='*.ts' --include='*.tsx'
[no output]
$ grep -n "ShiftDashboardPage\|POSShiftsDashboard" apps/web/tools/audit-design-system-baseline.json
[no output]
$ jq '{shiftDashboard, shifts}' apps/web/src/locales/{en,fr,ar}/pos.json
{"shiftDashboard":null,"shifts":null}
{"shiftDashboard":null,"shifts":null}
{"shiftDashboard":null,"shifts":null}
$ grep -rn "ShiftDashboardPage" apps/web/src --include='*.md'
apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:3:> Superseded 2026-08-11: `ShiftDashboardPage` was deleted; use `/pos/shift-history` for the supported web-admin surface.
apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:109:9. **ShiftDashboardPage** - 17 tests
apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:306:    ├── ShiftDashboardPage/
apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:307:    │   ├── ShiftDashboardPage.tsx
apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:308:    │   ├── ShiftDashboardPage.test.tsx
apps/web/src/features/pos/IMPLEMENTATION_SUMMARY.md:367:  element: <ShiftDashboardPage {...props} />,
apps/web/src/features/pos/README.md:3:> Superseded 2026-08-11: `ShiftDashboardPage` was deleted; use `/pos/shift-history` for the supported web-admin surface.
apps/web/src/features/pos/README.md:22:└── ShiftDashboardPage (shift management)
apps/web/src/features/pos/README.md:99:import { ShiftDashboardPage } from '@/features/pos'
apps/web/src/features/pos/README.md:149:    <ShiftDashboardPage
apps/web/src/features/pos/README.md:270:### ShiftDashboardPage
apps/web/src/features/pos/README.md:520:- ShiftDashboardPage manages shift state
```

Red-first route evidence was reconstructed against the pre-delete commit and proves both prohibited literals existed:

```text
$ git show d7f3ea21d^:apps/web/src/routes/index.tsx | grep -nE 'path="/pos/shifts"|POSShiftsPage'
284:const POSShiftsPage = lazy(() => import('@/pages/POS/POSShiftsDashboard'))
3181:          path="/pos/shifts"
3186:              <POSShiftsPage />
```

The new test was red before deletion and passed after it. The task-close full suite reported 4,209 passed / 5 failed / 1 skipped / 3 todo; failures were confined to the three M0b-reviewed exception files. Typecheck passed; lint exited 0 with 6,516 warnings and design audit 736 acknowledged / 0 new / 0 stale. React Doctor reported 98/100 with no issues.

### T11 — duplicate marketing hub

The `/marketing` route/import, complete `features/marketing` subtree, en/fr marketing locale files, and the en/fr/ar i18n namespace registrations were deleted. The six Sidebar destinations represented by the hub remain at `Sidebar.tsx:262-267`.

```text
$ git show cdce8ab38^:apps/web/src/routes/index.tsx | grep -nE 'path="marketing"|MarketingHubPage'
98:const MarketingHubPage = lazy(() => import('../features/marketing').then((m) => ({ default: m.MarketingHubPage })))
1981:          path="marketing"
1984:              <MarketingHubPage />
$ grep -rnE "/marketing\b|MarketingHubPage|marketing:" apps/web/src
[no output]
$ pnpm exec vitest run src/routes/routes.test.tsx
Test Files 1 passed (1); Tests 16 passed (16)
$ pnpm exec vitest run src/lib/i18nRawKeyCoverage.test.tsx src/__tests__/i18n/arLocaleCoverage.test.ts
i18nRawKeyCoverage: 5 passed; arLocaleCoverage: the already-reviewed Arabic exception only
```

Round 2 found the initial route-literal assertion had constructed `/marketing` even though the nested route literal was `marketing`; the companion page-name assertion supplied the red result but the route guard was vacuous. Commit `9ac83f0eb` fixes the literal construction. The focused route/POS regression set passes 26/26. The task-close full suite reported 4,206 passed / 5 failed / 1 skipped / 3 todo, with exactly the three reviewed exception files. Typecheck and lint passed; design audit remained 736 acknowledged / 0 new / 0 stale. React Doctor reported 98/100 with no issues.

### T12 — finance hub deleted outright

Only the finance index element was removed; the `finance` parent and every child route remain. `FinanceHubPage` and its tests were deleted. A loop enumerated all 35 scalar `finance:hub.*` keys and grepped each outside locales and the retiring page/test; only `finance:hub.cards.treasuryOverview.title` had surviving consumers. The other 34 keys were removed from en/fr/ar, while the preserved title still resolves exactly to `Treasury`, `Trésorerie`, and `الخزينة`.

```text
$ git show 1184a83ac^:apps/web/src/routes/index.tsx | sed -n '1977,1987p'
<Route path="finance">
  <Route index element={
    <RequirePermission moduleKey="finance">
      <SuspenseWrapper>
        <FinanceHubPage />
$ pnpm exec vitest run src/routes/routes.test.tsx src/lib/i18nRawKeyCoverage.test.tsx src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx
Test Files 3 passed (3); Tests 63 passed (63)
$ jq -r '.hub' src/locales/{en,fr,ar}/finance.json
{"cards":{"treasuryOverview":{"title":"Treasury"}}}
{"cards":{"treasuryOverview":{"title":"Trésorerie"}}}
{"cards":{"treasuryOverview":{"title":"الخزينة"}}}
$ grep -rn "FinanceHubPage" apps/web/src
[no output]
$ grep -rnE "to=\"/finance\"|to=\{'/finance'\}|navigate\('/finance'\)|href=\"/finance\"" apps/web/src --include='*.ts' --include='*.tsx' | grep -vE "\.test\.tsx?:|__tests__/|/test/"
[no output]
$ grep -rn "Navigate" apps/web/src/routes/index.tsx | grep -i finance
[no output]
```

The required per-key grep used each scalar path extracted from the pre-delete English locale. `hub.title` and `hub.description` only hit the unrelated `pos` and `inventory` namespaces. The complete finance-hub result was:

```text
hub.title => only PosHubPage/InventoryHubPage and their tests (non-finance namespaces)
hub.description => only PosHubPage/InventoryHubPage (non-finance namespaces)
hub.sections.bankingAndPayments => [no output]
hub.sections.accounting => [no output]
hub.sections.reports => [no output]
hub.cards.payments.title => [no output]
hub.cards.payments.description => [no output]
hub.cards.repositories.title => [no output]
hub.cards.repositories.description => [no output]
hub.cards.instruments.title => [no output]
hub.cards.instruments.description => [no output]
hub.cards.bankReconciliation.title => [no output]
hub.cards.bankReconciliation.description => [no output]
hub.cards.expenses.title => [no output]
hub.cards.expenses.description => [no output]
hub.cards.withholdingCertificates.title => [no output]
hub.cards.withholdingCertificates.description => [no output]
hub.cards.treasuryOverview.title => i18nRawKeyCoverage.test.tsx + Sidebar.tsx
hub.cards.treasuryOverview.description => [no output]
hub.cards.chartOfAccounts.title => [no output]
hub.cards.chartOfAccounts.description => [no output]
hub.cards.generalLedger.title => [no output]
hub.cards.generalLedger.description => [no output]
hub.cards.journalEntries.title => [no output]
hub.cards.journalEntries.description => [no output]
hub.cards.trialBalance.title => [no output]
hub.cards.trialBalance.description => [no output]
hub.cards.profitLoss.title => [no output]
hub.cards.profitLoss.description => [no output]
hub.cards.balanceSheet.title => [no output]
hub.cards.balanceSheet.description => [no output]
hub.cards.agedReceivables.title => [no output]
hub.cards.agedReceivables.description => [no output]
hub.cards.agedPayables.title => [no output]
hub.cards.agedPayables.description => [no output]
```

The seven intentional generic `HubCard.test.tsx` fixture literals remain byte-identical at lines 12, 22, 35, 54, 63, 87, and 103; SHA-256 remains `ccf2be6a90c78ee95ed09427a7a8a8858c0752a2ca5301c22fa63b310d744c9f`. Per `OWNER-DECISIONS:58`, obsolete E2E block `MTP-GL-28` was deleted rather than repointed, and only `['24-finance', '/finance']` was removed from `ui-audit-shots.mjs`; neighbouring shot ordinals were not renumbered. This E2E-test removal is the task's explicit authorized deviation.

The task-close full suite reported 4,199 passed / 5 failed / 1 skipped / 3 todo, with exactly the three reviewed exception files. Typecheck and lint passed; design audit remained 736 acknowledged / 0 new / 0 stale. React Doctor reported 98/100 with no issues.

### T13 — duplicate settings chart-of-accounts mount

Only the settings child block was deleted. The canonical finance child remains guarded and linked:

```text
$ git show 85bf6b02d^:apps/web/src/routes/index.tsx | sed -n '2302,2315p'
          <Route
            path="chart-of-accounts"
            element={
              <RequirePermission permission="accounts.view">
                <SuspenseWrapper>
                  <ChartOfAccountsPage />
$ grep -rn "settings/chart-of-accounts" apps/web/src
[no output]
$ rg -n 'path="chart-of-accounts"|permission="accounts.view"' apps/web/src/routes/index.tsx
2010:            path="chart-of-accounts"
2012:              <RequirePermission permission="accounts.view">
$ rg -n "href: '/finance/chart-of-accounts'" apps/web/src/components/organisms/{Sidebar/Sidebar.tsx,CommandPalette/useCommandPalette.ts}
Sidebar.tsx:299: ... href: '/finance/chart-of-accounts' ...
useCommandPalette.ts:80: ... href: '/finance/chart-of-accounts' ...
```

The route test failed red on the settings branch and then passed 18/18. The task-close full suite reported 4,200 passed / 5 failed / 1 skipped / 3 todo, with exactly the three reviewed exception files. Typecheck and lint passed; design audit remained 736 acknowledged / 0 new / 0 stale. React Doctor reported 98/100 with no issues.

### M3 intermediate manifest state

No manifest was regenerated or committed:

```text
$ git diff --stat d3b0550ed..HEAD -- scripts/factory/manifests/
[no output]
$ bash scripts/factory/check-manifest-drift.sh
[exit 1]
... removes /marketing, /pos/shifts, /settings/chart-of-accounts, and the /finance index entry ...
Route manifest drift — run: node scripts/factory/gen-route-manifest.mjs && commit the manifests
```

That exit 1 is the explicitly permitted M3 intermediate state. M4 owns the single regeneration and drift-CI work.

M3 bridge round 1 (`docs/handoff/reviews/ui-wave0/M3-round1.md`) ended in a blank review-tool error with no findings and no parseable verdict. The harness therefore records it as `CHANGES-REQUIRED`; no code fix was possible, and round 2 retries the read-only gate.

M3 bridge round 2 (`docs/handoff/reviews/ui-wave0/M3-round2.md`) returned `CHANGES-REQUIRED`. Commit `9ac83f0eb` fixes its actionable source/test findings: the `/marketing` guard now checks the actual relative route literal, the T10-orphaned `_invalidation.ts` production helper and its helper-only tests are deleted, and stale current-tense POS references are removed. Post-fix verification is 26/26 focused tests; deterministic full suite 4,202 total / 4,193 passed / 5 failed / 1 skipped / 3 todo, with failures only in the three M0b-reviewed exception files; typecheck and lint pass; design audit is 736 acknowledged / 0 new / 0 stale; React Doctor is 100/100.

Round 2 also found four newly zero-consumer API exports after T10: `getOrCreateWebTerminal`, `closeShift`, `XReportResponse`, and `XReportData`. API-client deletion is explicitly outside T10 scope, so they remain unchanged and are carried as terminal-audit findings.

Owner/terminal ruling remains required before merge for `/finance`: the delivered parent route has no index and no element, so React Router matches `/finance` and renders an empty outlet rather than reaching the catch-all redirect. This contradicts the accepted rationale in `OWNER-DECISIONS:58`, but the source implements the brief exactly (keep the parent, delete the index, add no redirect). No unruled production behavior was added. M3 round 3 must verify that this contradiction is explicitly disclosed; the parent must choose leave-as-ruled, add a redirect, or supply another route element before merge.
