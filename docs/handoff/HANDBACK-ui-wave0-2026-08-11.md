# UI Wave 0 implementer report — M0 through M8, per-finding status

> **Scope of this title.** This report covers milestones M0–M8 of the `ui-wave0`
> dispatch. It is **not** a completion claim: `UI-14` (T6) and `UI-43`'s
> `BarcodeHero` claim (T8-b) are blocked on owner gate F-1, and `UI-43`'s
> `touchOptimized` claim has no owner (F-2b). `UI-43` is reported **PARTIAL**
> with its four claims itemised. See "Completion language" at the end.

## Header

- Curated base SHA: `d682b38ec9761a917b9716428091a482745795f6`
- Branch: `codex/ui-wave0-2026-08-11`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0`
- Final SHA: `4706d437d` is the last commit before this report's own commit; the branch tip at handback time is the commit that carries this report and the M8 ledger row. Every §4 result below was executed at `4706d437d` (the report commit changes only markdown and the progress YAML).
- Archived pre-repin evidence branch: `codex/ui-wave0-2026-08-11-pre-repin` at `89d83c6c4a56ce7bc6e351867e90451f0f35c218`
- **The parent's verbatim answer on the commit-message format (§3 / F-6)**, quoted from `commit_series:` in `docs/handoff/progress/ui-wave0.progress.yaml`: `Phase 0.<task#>.<seq>`. M0 uses `Phase 0.0.<seq>` and M0b uses `Phase 0.0b.<seq>` under the same rule.
- M0b authority record: `docs/handoff/reviews/ui-wave0/OWNER-RULING-2026-08-18-M0b.md`
- Nothing was pushed. No merge was performed. `git stash` was never used.
- Wave status: M0b through M7 accepted by their bridge registers; M8 (whole-branch gate) is recorded below and is submitted for the parent's terminal audit.

### Commit list (base → tip, one line each) — 53 commits, plus this report's own commit

```text
3dcb31d72 Phase 0.0.1: Re-pin UI Wave 0 controls
261c6c4d9 Phase 0.0b.1: Record M0b production defect blocker
9c1f234f3 Phase 0.0b.2: Resume reviewed M0b exceptions
3e87358c5 Phase 0.0b.3: Align stale web tests
ceadc5ee6 Phase 0.0b.4: Record M0b exception evidence
62c75bf70 Phase 0.0b.5: Tighten M0b test evidence
61d9dce8a Phase 0.0b.6: Resolve M0b review findings
87cbda211 Phase 0.0b.7: Sync M0b resume state
04c96be26 Phase 0.0b.8: Accept M0b baseline remediation
751255324 Phase 0.1.1: Build graph-aware listing census
b59d67225 Phase 0.1.2: Record M1 census evidence
5383a2467 Phase 0.1.3: Resolve M1 review findings
f37eace25 Phase 0.1.4: Record M1 review fixes
72c0207b4 Phase 0.1.5: Clarify M1 reachability evidence
4c847a414 Phase 0.1.6: Record M1 round two findings
a6234a7b8 Phase 0.1.7: Accept M1 census milestone
daf0a8f50 Phase 0.2.1: Resolve keyed route manifest pages
57b3fe3e2 Phase 0.2.2: Record M2 manifest evidence
d3b0550ed Phase 0.2.3: Accept M2 manifest milestone
d7f3ea21d Phase 0.10.1: Delete retired web shift console
cdce8ab38 Phase 0.11.1: Delete duplicate marketing hub
1184a83ac Phase 0.12.1: Delete retired finance hub
85bf6b02d Phase 0.13.1: Delete duplicate settings account route
ba40f4f83 Phase 0.13.2: Record M3 deletion evidence
57f708d35 Phase 0.13.3: Record M3 review tool failure
9ac83f0eb Phase 0.13.4: Resolve M3 review findings
1da344919 Phase 0.13.5: Record M3 review fixes
0d10c7a05 Phase 0.13.6: Record M3 review tool failure
eb4ea4fe0 Phase 0.10.2: Remove orphaned shift locale keys
4446b7f62 Phase 0.13.7: Record M3 round-four fixes
198f198e7 Phase 0.13.8: Accept M3 deletion milestone
00366d151 Phase 0.3.1: Regenerate route manifests
6747d9042 Phase 0.3.2: Gate route manifest drift in CI
3fc0c52bc Phase 0.3.3: Record M4 manifest evidence
acf5dc6bd Phase 0.3.4: Accept M4 manifest milestone
77257bf88 Phase 0.4.1: Fail closed in canAccessModule and type the ModuleKey union (UI-01/UI-02)
3608a8b1d Phase 0.15.1: Gate the expenses nav item on expenses instead of treasury (UI-07)
a6dc879b8 Phase 0.3.5: Sync the route manifest with the /crm/companies gate repair (UI-02)
0937aabc5 Phase 0.4.2: Record M5 gating-pair evidence and the parent manifest ruling
901982d89 Phase 0.4.3: Record M5 round-one tenancy-authz review (ACCEPT)
9b46e8d52 Phase 0.4.3: Record M5 frontend-conventions review
d52d84688 Phase 0.6.0: Close M5 and open M6 in the wave progress ledger
83b46e752 Phase 0.5.1: Give the voucher source filter chips a real selected state (UI-12)
4fcf447bf Phase 0.7.1: Teach the C6 detector the StatusTone family and plan the colorClasses burn-down (UI-40)
ee87f153e Phase 0.8.1: Prune the four orphaned "coming soon" locale key groups (UI-43 T8-c)
4275794ee Phase 0.14.1: Make the app name a config value in privacy and support copy (OQ-1)
5caeb61a2 Phase 0.6.1: Record M6 completion and evidence in the wave progress ledger
2621abbff Phase 0.6.2: Record M6 bridge round one
6e51c4e48 Phase 0.6.3: Close M6 and open M7, applying the M6 register's two doc fixes
85d2f8a26 Phase 0.9.1: Write the listing-page canon and refresh the navigation doc (UI-44)
1dff4a00e Phase 0.9.2: Record M7/T9 completion and evidence in the wave progress ledger
e7cf6b0ca Phase 0.9.3: Record M7 bridge round one
4706d437d Phase 0.9.4: Close M7 and open M8, applying the M7 register's six corrections
```

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
eb4ea4fe0c6e6f3668e8343fa5401c1ee9f11c11 Phase 0.10.2: Remove orphaned shift locale keys
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

Round 4 found 15 additional leaf keys whose sole consumers were the deleted console components. Commit `eb4ea4fe0` removes them from en/fr (Arabic did not contain them) while preserving the seven `xReport` leaves used by `ZReportDetailPage`. The required per-key proof is:

```text
xReport.generatedAt refs=[no output] locales=null,null,null
xReport.grossSales refs=[no output] locales=null,null,null
xReport.netSales refs=[no output] locales=null,null,null
xReport.paymentMethods refs=[no output] locales=null,null,null
xReport.print refs=[no output] locales=null,null,null
xReport.refundsCount refs=[no output] locales=null,null,null
xReport.salesCount refs=[no output] locales=null,null,null
xReport.salesSummary refs=[no output] locales=null,null,null
xReport.taxAmount refs=[no output] locales=null,null,null
xReport.title refs=[no output] locales=null,null,null
xReport.vatBreakdown refs=[no output] locales=null,null,null
transactions.errors.terminalCreation refs=[no output] locales=null,null,null
transactions.loading.terminal refs=[no output] locales=null,null,null
transactions.noLocation refs=[no output] locales=null,null,null
transactions.noLocationDescription refs=[no output] locales=null,null,null
$ jq -c '.xReport' apps/web/src/locales/{en,fr,ar}/pos.json
{"rate":"Rate","net":"Net","vat":"VAT","gross":"Gross","method":"Method","count":"Count","amount":"Amount"}
{"rate":"Taux","net":"HT","vat":"TVA","gross":"TTC","method":"Méthode","count":"Nombre","amount":"Montant"}
null
```

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
$ pnpm exec vitest run src/routes/routes.test.tsx  # captured before 1184a83ac
src/routes/routes.test.tsx (17 tests | 1 failed)
expected finance branch not to contain "<Route index"
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
$ pnpm exec vitest run src/routes/routes.test.tsx  # captured before 85bf6b02d
src/routes/routes.test.tsx (18 tests | 1 failed)
expected settings branch not to contain 'path="chart-of-accounts"'
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

Owner/terminal ruling remains required before merge for `/finance`: the delivered parent route has no index and no element, so React Router matches `/finance` and renders an empty outlet rather than reaching the catch-all redirect. This contradicts the accepted rationale in `OWNER-DECISIONS:58`, but the source implements the brief exactly (keep the parent, delete the index, add no redirect). No unruled production behavior was added. The next substantive bridge review must verify that this contradiction is explicitly disclosed; the parent must choose leave-as-ruled, add a redirect, or supply another route element before merge.

M3 bridge round 3 (`docs/handoff/reviews/ui-wave0/M3-round3.md`) ended in another blank review-tool error with no findings or parseable verdict. It is preserved and treated as `CHANGES-REQUIRED` fail-closed; no source change is indicated, and round 4 retries the read-only gate.

M3 bridge round 4 (`docs/handoff/reviews/ui-wave0/M3-round4.md`) returned `CHANGES-REQUIRED`. Its P1 identified the 15 orphan locale leaves now removed by `eb4ea4fe0`; the complete per-key proof is pasted above. Its T12/T13 P3 evidence gap is also closed above with the captured red output. The retained POS documents remain brief-compliant historical records with dated supersession notices, and the empty deletion directories contain no files and cannot enter Git. Post-fix verification: focused route/i18n/sidebar/POS tests 69/69; deterministic full suite 4,202 total / 4,193 passed / 5 failed / 1 skipped / 3 todo with failures only in the three M0b exceptions; typecheck and lint pass; design audit 736 acknowledged / 0 new / 0 stale. React Doctor found no changed React source files in this locale-only fix.

M3 bridge round 5 (`docs/handoff/reviews/ui-wave0/M3-round5.md`) returned `ACCEPT`. It independently re-derived all 57 translation keys used by the two deleted POS components, confirmed zero consumers for every removed key and live consumers for every retained key, and reproduced the focused static/test gates. No P1 remains. The accepted register carries the `/finance` blank-pane owner decision as a hard pre-merge gate plus ticketable P3 notes for orphan-key tooling, route-guard string-form robustness, historical-doc discoverability, and an untracked empty directory.

## M4 — T3 route-manifest regeneration and CI drift guard

The two authorized commits are strictly separated:

```text
00366d1510aba3543f1836b16a4dedf20762a96c Phase 0.3.1: Regenerate route manifests
6747d9042c252883426f1ebc0f44c2d5c5e54d13 Phase 0.3.2: Gate route manifest drift in CI
```

T3(a) ran `node scripts/factory/gen-route-manifest.mjs` once after T2 and all four deletion tasks. Its commit changes only `scripts/factory/manifests/routes-web.yaml`; `routes-pos.yaml` is byte-unchanged. The regenerated web manifest contains the six previously unrecorded live routes, the eight finance permission corrections, the settings setup module-gate correction, and the four owner-ruled removals. The invoice-detail component is preserved correctly:

```text
$ bash scripts/factory/check-manifest-drift.sh
$ echo $?
0
$ rg -n -A3 '^  - path: /sales/invoices/:id$' scripts/factory/manifests/routes-web.yaml
762:  - path: /sales/invoices/:id
763-    component: InvoiceDetailPage
764-    module_gate: sales
765-    permission: null
$ rg -n -F 'path: /pos/shifts' scripts/factory/manifests/routes-web.yaml
[no output]
$ rg -n -F 'path: /marketing' scripts/factory/manifests/routes-web.yaml
[no output]
$ rg -n '^  - path: /finance$' scripts/factory/manifests/routes-web.yaml
[no output]
$ rg -n -F 'path: /settings/chart-of-accounts' scripts/factory/manifests/routes-web.yaml
[no output]
```

T3(b) adds `route-manifest-drift` / “Route Manifest Drift Guard” to `.github/workflows/ci.yml`. It has no `if:` guard, installs the workspace dependencies required for root `js-yaml` and `apps/web` TypeScript, uses no database/Redis/environment setup, runs the existing shell check, and is included in `all-checks-pass.needs`. The adjacent comment records why this dependency cannot arrive skipped.

The required deliberate negative check used a temporary, uncommitted `__manifest-drift-probe` route and then reversed that edit:

```text
$ bash scripts/factory/check-manifest-drift.sh
negative drift check exit=1
8:+  - path: /__manifest-drift-probe
16:Route manifest drift — run: node scripts/factory/gen-route-manifest.mjs && commit the manifests
$ bash scripts/factory/check-manifest-drift.sh  # after reversing the probe
post-negative-revert drift check exit=0
```

The workflow YAML parses successfully and confirms the new job has five lean steps, no `if:` field, and membership in the aggregate gate. `actionlint` is not installed locally. Generator tests pass 10/10. Typecheck and lint pass with zero errors; the design-system audit remains 736 acknowledged / 0 new / 0 stale, query-key Gate C remains 0/0/0, and quantity audit remains zero. The deterministic full suite reports 4,202 total / 4,193 passed / 5 failed / 1 skipped / 3 todo, with failures only in the three M0b-reviewed exception files.

M4 bridge round 1 (`docs/handoff/reviews/ui-wave0/M4-round1.md`) returned `ACCEPT`. It independently confirmed the one-manifest-commit invariant, drift exit 0, manifest fidelity, correct invoice-detail resolution, lean unguarded CI job, aggregate dependency, dependency resolution, and 10/10 generator tests.

### M5 architecture blocker discovered by M4 review

M5/T4 is required to replace `/crm/companies`'s invalid `moduleKey="partners"` with `permission="partners.view"`. The generator will necessarily change that row from:

```yaml
module_gate: partners
permission: null
```

to:

```yaml
module_gate: null
permission: partners.view
```

Therefore M5 will make `check-manifest-drift.sh` fail, while M8 requires exit 0 and the brief declares T3(a) the only manifest commit. There is no compliant executor-side resolution. The recommended owner amendment is one additional manifests-only synchronization immediately after T4 and before M5 review, preserving T4's source-only commit and explicitly superseding the one-manifest-commit rule. Execution stops before M5 until that ruling is recorded.

The M4 reviewer also recorded three non-blocking P3 notes: the workflow trigger does not cover direct pushes to `dev`; the root workspace install may be broader than the generator needs; and the progress YAML's scalar `commit` field can hold only T3(b), though both T3 SHAs are recorded in this handback and the findings list.

---

## Execution handover (2026-08-19) — read before the M5–M8 sections

The Codex desktop executor exhausted its quota after M4 was accepted. **M5, M6,
M7 and M8 were executed by the parent orchestrator's implementation agent
(Claude)** in the same worktree (`.worktrees/ui-wave0`) and on the same branch
(`codex/ui-wave0-2026-08-11`), under the parent rulings recorded in
`docs/handoff/progress/ui-wave0.progress.yaml` (`blockers:`). The branch tip and
branch name were re-verified immediately before every commit; no foreign commit
was ever observed, nothing was pushed, and `git stash` was never used. The
commit series continued to follow the YAML's own `commit_series:` value,
`Phase 0.<task#>.<seq>`.

## M5 — T4 (UI-01/UI-02) + T15 (UI-07), the gating pair

Commits, in order:

```text
77257bf88 Phase 0.4.1: Fail closed in canAccessModule and type the ModuleKey union (UI-01/UI-02)
3608a8b1d Phase 0.15.1: Gate the expenses nav item on expenses instead of treasury (UI-07)
a6dc879b8 Phase 0.3.5: Sync the route manifest with the /crm/companies gate repair (UI-02)
```

The third commit is the **parent-authorised manifests-only synchronisation**
that resolves the M4-discovered contradiction above. The parent's ruling is
quoted verbatim in the progress YAML under `blockers:`; it supersedes the
one-manifest-commit restriction to exactly this extent, so the branch now
carries exactly **two** manifest commits — `00366d151` (T3(a)) and `a6dc879b8`.

### T4 — `canAccessModule` fails closed — status `DONE`

- `usePermissions.ts:94` — `MODULE_PERMISSIONS` is now
  `as const satisfies Record<string, readonly Permission[]>` with an exported
  `ModuleKey` union; `canAccessModule(moduleKey: ModuleKey)` returns `false` for
  an unrecognised key (`:152-160`, with the UI-01 comment).
- `hasAnyPermission` / `hasAllPermissions` were widened to
  `readonly Permission[]` **first**, as the brief's mandatory pre-step.
- All six call surfaces re-typed: `RequirePermission.tsx:11`, `Sidebar.tsx:111`
  (`NavChild`), `:121` (`NavModule`), `:425` (`isNavItemVisible`),
  `useCommandPalette.ts:42`, `PosHubPage.tsx:25` and `InventoryHubPage.tsx:28`
  (`permissionModule`), plus the two direct callers
  `PosRefundPoliciesPage.tsx:130` and `CashPositionWidget.tsx:128`.
- **Exactly the five enumerated diagnostics, no sixth.** With the union in place
  and the two new map rows temporarily removed — the exact pre-fix state the
  brief specifies — `pnpm typecheck` failed on `Sidebar.tsx:182`
  (`goods-receipt.create-standalone`), `Sidebar.tsx:215` (`inventory.view`),
  `routes/index.tsx:1428` and `:1440` (`parts_catalog`) and `routes/index.tsx:2747`
  (`partners`). The line numbers differ from the brief's 1431/1443/2777 only
  because the M3 deletions shifted `routes/index.tsx`; the keys, sites and count
  match. Four further diagnostics appeared inside the new red-first test file
  `usePermissions.moduleAccess.test.tsx` (`:58,64,69,73`) — TDD artefacts of the
  test author's own file, not a sixth production site.
- Resolutions as tabulated by the brief: rows 1–2 added as self-mapped
  `MODULE_PERMISSIONS` entries; row 3 deleted both `RequirePermission
  moduleKey="parts_catalog"` wrappers while **keeping**
  `<ModuleGuard module="PlatformIntegration">` on both routes; row 4 replaced
  `moduleKey="partners"` with `permission="partners.view"` at `/crm/companies`
  and left the route in place (dedup is `UI-35`, Wave 4).
- **Failing-test-first evidence:** the three new/extended test files failed
  6 tests / 46 passed before the implementation —
  `usePermissions.moduleAccess.test.tsx` (unknown key returned `true`; the two
  new map rows undefined; `goods-receipt.create-standalone` open to a purchases
  role), `RequirePermission.moduleKey.test.tsx` (children rendered under an
  unknown `moduleKey`), and `Sidebar.test.tsx` (`newGoodsReceipt` visible to a
  purchases role). All green after.
- **Test-harness note (a decision the brief did not specify):**
  `Sidebar.test.tsx` stubs `usePermissions` wholesale, which cannot express
  role-level gating. The mock now delegates to the REAL hook via
  `importOriginal` behind a `useRealModuleAccess` flag that only the new T4/T15
  describe block sets; the 41 pre-existing Sidebar tests keep the stub and are
  otherwise unmodified.

### T15 — `/expenses` nav parity — status `DONE`

- `Sidebar.tsx:280` — `permission: 'treasury'` → `'expenses'`. One line of
  production code plus tests. The payments/repositories/instruments treasury
  siblings, the `/expenses` route guard and `MODULE_PERMISSIONS` are untouched.
- Red-first: the cashier case failed before the change, while the negative case
  (a role holding neither permission) passed both before and after, as it must.
- **REPORT-ONLY constraint carried forward (F-5 / the brief's own note):** the
  `bankingAndPayments` group carries `module: 'Treasury'` (`Sidebar.tsx:272`), so
  the item stays hidden for tenants without the Treasury backend module
  regardless of role. Not fixed here; it is a module-model question, not a
  permission bug.

### M5 manifest sync — the parent-authorised commit

`node scripts/factory/gen-route-manifest.mjs` changed **exactly one** entry —
`/crm/companies`, `module_gate: partners → null`, `permission: null →
partners.view` — and nothing else. The parts-catalog routes produce no delta
because `module_gate` records the `ModuleGuard`, which wins over `moduleKey`
(`gen-route-manifest.mjs:12-13`); T15 produced zero manifest delta;
`routes-pos.yaml` is unchanged. `bash scripts/factory/check-manifest-drift.sh`
exits 0 after the commit.

### M5 user-visible navigation changes (F-3 and F-5), stated plainly

T4 **removes** the `newGoodsReceipt` entry from every role except admin/manager
and makes `stockByLocation` a real `inventory.view` check. T15 **adds** the
`/expenses` entry for `cashier`, `operator` and `viewer`. Both are intended
repairs, not refactors, and both are live navigation changes.

### M5 review

Run as **two lens-scoped registers**, both round 1, both `ACCEPT`, zero fix
rounds: `docs/handoff/reviews/ui-wave0/M5-round1-tenancy-authz.md` and
`docs/handoff/reviews/ui-wave0/M5-round1-frontend-conventions.md`. The
frontend-conventions register's only actionable item was a P3 YAML wording
correction (the pre-existing Sidebar test count is 41, not 43), applied at the
M6 opening commit.

## M6 — T5, T7, T8-c, T14

Commits, one per task, in brief order:

```text
83b46e752 Phase 0.5.1: Give the voucher source filter chips a real selected state (UI-12)
4fcf447bf Phase 0.7.1: Teach the C6 detector the StatusTone family and plan the colorClasses burn-down (UI-40)
ee87f153e Phase 0.8.1: Prune the four orphaned "coming soon" locale key groups (UI-43 T8-c)
4275794ee Phase 0.14.1: Make the app name a config value in privacy and support copy (OQ-1)
```

`git diff --name-only 9b46e8d52..4275794ee -- scripts/factory/` is **empty**
across all four commits and `check-manifest-drift.sh` exits 0 at the M6 tip.

### T5 — `UI-12` voucher filter chips — status `DONE`

`VoucherListPage.tsx:104-112`'s ternary had **both** branches as the empty
template literal, so the active chip was byte-identical to the inactive ones
with no accessible selection state. Matched to the house filter-chip pattern at
`features/import/pages/ImportHistoryPage.tsx:90-104`.
**DEVIATION, disclosed:** implemented through the canonical `Button` variants
(selected `variant='primary'`, unselected stays `variant='secondary'`) rather
than `className` overrides — `Button` already composes those exact semantic
tokens, and a `className` override would have to win a stylesheet-order fight
against them to render at all. `aria-pressed` now carries selection so the state
is not colour-only; geometry classes and the `data-testid` contract are
unchanged. Red-first 3/3 failed, then 78/78 green across `src/features/vouchers`.

### T7 — `UI-40` C6 detector + burn-down plan — status `DONE` (partial by design)

- Per-category whole-repo scan before → after: C1 12→12, C2 250→250, C3 458→458,
  C4 14→14, C5 2→2, **C6 0→82**. Only C6 moved, satisfying the commit-local
  C1–C5 rule; the sole non-C6 line in the baseline diff is a trailing-comma
  reflow on the last pre-existing C5 entry. Baseline regenerated in the same
  commit, 736 → 818 entries, audit reports 818 acknowledged / 0 new / 0 stale.
- **SCOPE JUDGEMENT, flagged:** the brief's acceptance text says the regex family
  must match `Tone`/`Tones` **suffixes**, but a suffix-only fix would have left
  most of F-8's population undetected — the live maps are identified by their
  **value type**, and `directionTone` / `matchTone` / `invoiceTone` /
  `matchPreviewTone` carry no `status|state` token. Implemented **both**:
  `Tones?` added to alternations 1 and 3, plus a fourth alternation keyed on
  `Record<…, StatusTone>` directly.
- **HONEST NUMBER:** 82 is an **entry** count, not a defect count. Distinct
  source sites = 46 across 41 files, which is the real burn-down target. The
  over-counting predates this fix; de-duplicating would change baseline-key
  semantics shared with C1–C5, so it is documented rather than changed.
- T7(b) plan at
  `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/18-colorclasses-migration-plan.md`:
  script-derived inventory of 52 importers / 1,901 occurrences / 40 documents +
  12 admin / 0 outside the carve-out. `eslint.config.js` is byte-identical — the
  carve-outs were **not** removed and no importer was migrated, as the
  DO-NOT-TOUCH set requires.
- **Ownership boundary with the parallel enforcement-P2 lane**, recorded so P2
  can reconcile: Wave 0 T7 owns only `apps/web/tools/audit-design-system.mjs`'s
  `STATUS_RE` family, the regenerated baseline, the C6 fixtures in
  `tools/__tests__/audit-design-system.test.mjs`, and the plan document. It has
  authored no tamper test, nothing in the enforcement worktree, and no change to
  `apps/web/eslint.config.js`.

### T8-c — `UI-43` orphaned "coming soon" keys — status `DONE`

Removed 13 key instances across five bundles —
`advancedPayments.discountComingSoon` (en/fr `pos`),
`products.messages.stockComingSoon` (en/fr `inventory`),
`counting.detailsComingSoon` and `counting.create.categorySelectionComingSoon(+Hint)`
(en/fr/ar `inventory`). `ar/inventory.json` packed four keys inline on one line,
so the two `categorySelection` entries were removed by verbatim string match; a
first attempt left a double comma, caught by JSON re-validation and repaired
before commit. Red-first 14/26 failed, 26/26 green after; the test is two-sided
(it also pins the three LIVE coming-soon keys and asserts EN/FR alignment)
because the risk here is over-deletion.

### T14 — `OQ-1` app name as a config value — status `DONE`

Copy now interpolates `{{appName}}` from `useProductConfig().productName` at
`PrivacyPolicyPage.tsx:11/40/78` and `TenantSupportAccessPage.tsx:18/33`. All ten
verified `AutoERP` locale hits handled; `grep -rn "AutoERP" apps/web/src/locales/`
returns **zero**. The dead `appName` key was deleted from en/fr/ar `common.json`
(grep proved zero code consumers). No new config mechanism and no new env var —
`PRODUCT_INFO` stays the single source.
**Harness repair, as the brief warned:** `TenantSupportAccessPage.test.tsx`
rendered through a bare `QueryClientProvider` + `MemoryRouter` with no
`ProductConfigProvider`, and `useProductConfig` **throws** without one
(`ProductConfigContext.tsx:128-135`). Chose an explicit
`<ProductConfigProvider initialProduct={…}>` wrap over switching to
`renderWithProviders`, because it adds exactly the missing provider without also
pulling in `CompanyConfigProvider` and a seeded company-config cache entry none
of these tests asked for.
**Brief arithmetic corrections, disclosed rather than absorbed:** (1) T14's site
table is introduced as "exactly the eight sites" but its rows expand to **ten**
verified locale hits, all handled; (2) T14 says `TermsOfServicePage` hardcodes
the brand "six times" — the actual count at `:37-38`, `:49-50`, `:107-108`,
`:131-132` is **ten** occurrences across eight lines. `TermsOfServicePage`
remains out of scope and unfixed (see Discovered findings).

### M6 review

One register, round 1, `ACCEPT`, zero fix rounds:
`docs/handoff/reviews/ui-wave0/M6-round1.md`. No P1. Its two actionable
directives were documentation-only and were applied at the M7 opening commit
`6e51c4e48` (P2-1: a false "ar/inventory PASSES in arLocaleCoverage" evidence
clause in the ledger, rewritten with the real evidence and an honest statement
of the coverage gap; P3-1: the "Tailwind class-order fight" mechanism in the
`VoucherListPage.tsx` comment, reworded to stylesheet-order precedence).

## M7 — T9 (UI-44) documentation debt

```text
6e51c4e48 Phase 0.6.3: Close M6 and open M7, applying the M6 register's two doc fixes
85d2f8a26 Phase 0.9.1: Write the listing-page canon and refresh the navigation doc (UI-44)
```

### Deliverable 1 — `docs/architecture/design-system.md` gains a Listing-Page Composition section

Acceptance evidence, with the exact command and scope (restated per the M7
register's P3-1):

```text
$ grep -wnE "list page|ListPageLayout|FilterPanel|canonical" docs/architecture/design-system.md | wc -l
8            # 0 before T9; this is the file+word-boundary scope 02 F-10 is measured against
$ grep -rnE "listing|ListPageLayout|FilterPanel" docs/architecture/ | wc -l
13           # RECURSIVE over docs/architecture/, not the single file
```

Both counts move by exactly **+1** (9 and 14) at the M8 opening commit, which
adds the `ActiveFilters` row the M7 register's P3-2 required.

The section carries the mandated banner *"Status: canon PROPOSED, Wave 3 gated
on the `CX-4` re-census"*. `FilterBar` and `DataTableColumn.sortable` are
documented as **PROPOSED** and verified absent from source (`FilterBar`: zero
hits in `apps/web/src`; `DataTableColumn` exposes
`key/header/align/numeric/render/accessor/headerClassName/cellClassName/width`
and no `sortable`). Exactly **one** figure is stated —`ListPageLayout` 12 of 46,
the single number the audit marks decision-grade — pinned to the base SHA; every
other distribution is delegated to the re-census with its reproduction command.

### Deliverable 2 — `docs/architecture/frontend-navigation.md` 1.0 → 2.0

Not a header touch-up: the doc's **central mechanism no longer exists**. 1.0
described filtering through a `MODULE_NAME_MAP` lookup;
`grep -rn MODULE_NAME_MAP apps/web/src` returns **zero**. The real mechanism is
per-item `module` / `permission` declarations evaluated by `isNavItemVisible`,
fail-closed on both axes. Claims that could not be verified were **deleted
rather than carried forward**: the microbenchmark "Performance Metrics", the
2026-01-02 9/10 "Quality Metrics" scorecard, the pasted 16-test expected-output
block, and the three per-vertical "Visible Sidebar Items" checklists. Added, all
source-derived: a table of all 15 top-level nav groups with their real gates, the
vertical-adaptive mechanism, the three-step label resolution order, the
fail-closed loading/error behaviour, and a route-reconciliation section built
from T3's regenerated manifest (265 web / 9 POS records; the four Wave 0
deletions confirmed absent by grep; the remaining orphan candidates confirmed
still present and labelled **candidates, not rulings**).

### M7 review and the corrections applied at M8

One register, round 1, `ACCEPT`, zero fix rounds:
`docs/handoff/reviews/ui-wave0/M7-round1.md` (lens: general, range
`2621abbff..1dff4a00e`). **No P1.** Six corrections were applied at the M8
opening commit `4706d437d`, all documentation-only:

| Finding | Correction |
|---|---|
| P2-1 | The nav doc's orphan list was under-inclusive by four routes. It now states the re-census's own **15 views / 4 parameterized views / 3 action-forms** split, notes that four of the 15 views are the Wave 0 deletions, and enumerates all **18** remaining candidates under three headed sub-lists, so 22 − 4 = 18 closes on the page. |
| P3-1 | Both acceptance grep counts restated in the ledger with the exact command and scope that reproduces each (one is `-w` over a single file, the other `-r` over `docs/architecture/`), plus the +1 both move by at M8. |
| P3-2 | `ActiveFilters` added to the "components that exist today" table with its five real consumers; the PROPOSED `FilterBar` bullet now tells readers not to hand-roll a chip row; `FilterPanel`'s "the one purpose-built filter container" line now discloses its **single** feature consumer (`features/inventory/ProductListPage.tsx`). |
| P3-3 | Every markdown **link** to the untracked `00-EXECUTIVE-REPORT.md` (three sites in `design-system.md`, one in `frontend-navigation.md`) replaced by a plain-text reference naming the file, its session location `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/`, and the fact that it is untracked (`.gitignore:58`). **Nothing was force-added**, per the parent's instruction. |
| P3-4 | The Overview's two-layer enforcement sentence is now explicitly **the rule**, qualified with the manifest's own numbers: **34 of 265** web routes carry neither `module_gate` nor `permission`, and **138 of 265** carry no `module_gate` at all. |
| P3-5 | The vertical label path corrected — `catalog:vertical.<vertical>.<VERTICAL_NAV_KEYS[key]>`, the map's **value**, so `modifierGroups` resolves to `…modifierGroup` (singular) — in both the doc and the ledger. |

## M8 — whole-branch verification (§4), at final state

Every item below was executed at the M8 tip `4706d437d` in
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0` on branch
`codex/ui-wave0-2026-08-11`. Vitest was run **single-worker only**
(`--maxWorkers=1`), per the wave's deterministic-evidence rule.

### §4 item 1 — `./scripts/preflight.sh` — **ENVIRONMENT-BLOCKED, reported as blocked**

The script cannot run in this worktree. It halts on its **first** stage, Pint,
because `apps/api/vendor/` does not exist here (Composer dependencies were never
installed in the worktree; the main checkout has them, the worktree does not):

```text
$ ./scripts/preflight.sh
🔍 Running preflight checks...

📦 Backend Checks
==================================

Running Pint (code style)...
./scripts/preflight.sh: line 58: ./vendor/bin/pint: No such file or directory
$ echo $?
1
$ ls -d apps/api/vendor
ls: apps/api/vendor: No such file or directory
```

**This is reported as BLOCKED, not as green and not as "PHPUnit skipped"** — the
run never reaches the PHPUnit stage, so the red `SKIPPING PHPUnit … this
preflight is INCOMPLETE` banner the brief expects was never printed. Naming the
blocked stages precisely, as §3 requires: **Pint, PHPStan, PHPUnit, the
`typescript:transform` drift guard and the `permissions:export-frontend-map`
drift guard** are all blocked on the missing backend environment. Per §3 and §4
item 1, the **frontend commands in §4 item 5 are the load-bearing evidence** for
this no-backend lane.

Two facts bound the risk of the blocked backend stages:

```text
$ git diff --name-only d682b38ec9761a917b9716428091a482745795f6..HEAD -- apps/api | wc -l
0
$ git diff --name-only d682b38ec9761a917b9716428091a482745795f6..HEAD \
    -- packages/shared/types/generated.d.ts apps/web/src/hooks/permissionsMap.generated.ts | wc -l
0
```

The branch changes **no** `apps/api/**` file (the wave's DO-NOT-TOUCH set forbids
it) and **no** generated artefact, so Pint/PHPStan/PHPUnit have nothing of this
branch's to judge and neither drift guard can fire on this diff. That is an
argument about the diff, **not** a substitute for running them; the parent's
terminal audit should run `./scripts/preflight.sh` from a checkout with
`apps/api/vendor/` present if it wants the banner itself.

**Every preflight stage that does not need the backend was run individually and
passes:**

| Preflight stage | Command | Result |
|---|---|---|
| TypeScript | `pnpm typecheck` (apps/web) | **exit 0** |
| ESLint | `pnpm lint:eslint` (chained in `pnpm lint`) | **exit 0 — 6520 problems, 0 errors** |
| TanStack key audit | `pnpm audit:keys` | Gate C **0 acknowledged / 0 new / 0 stale** |
| Design-system audit | `pnpm audit:design-system` | **818 acknowledged / 0 new / 0 stale** |
| Quantity-display audit | `pnpm audit:quantity` | **0 total / 0 new / 0 stale** |
| POS ESLint rule tests | `cd apps/pos && pnpm test:eslint-rules` | **exit 0** — `no-hardcoded-step` 6 valid/3 invalid, `no-raw-quantity-input` 6 valid/1 invalid |
| Route manifest drift | `bash scripts/factory/check-manifest-drift.sh` | **exit 0** |
| Vitest | `pnpm test -- --maxWorkers=1` (apps/web) | see item 5 |
| Fiscal v3 fixture parity | `bash apps/pos/scripts/check-fiscal-fixture-parity.sh` | **exit 0 — 2 files / 29 tests passed** |

### §4 item 2 — manifest drift green post-regeneration — **PASS**

```text
$ bash scripts/factory/check-manifest-drift.sh
$ echo $?
0
$ grep -n -A2 "path: /sales/invoices/:id$" scripts/factory/manifests/routes-web.yaml
762:  - path: /sales/invoices/:id
763-    component: InvoiceDetailPage
764-    module_gate: sales
$ grep -nE "^  - path: (/pos/shifts|/marketing|/finance|/settings/chart-of-accounts)$" scripts/factory/manifests/routes-web.yaml
$ echo $?
1        # no hits — all four owner-ruled deletions absent
```

`/sales/invoices/:id → InvoiceDetailPage` proves T2 landed before T3(a). The
branch carries exactly **two** manifest commits — `00366d151` (T3(a)) and
`a6dc879b8` (the parent-authorised M5 sync).

### §4 item 3 — design-system audit clean, with the categorised delta — **PASS**

```text
$ cd apps/web && node tools/audit-design-system.mjs
[sweep-progress] Design-system audit C1-C6 violations: 818
[gate-summary] Design-system baseline: 818 acknowledged, 0 new, 0 stale baseline entries
$ echo $?
0
```

Per-category baseline census, base `d682b38ec` → tip `4706d437d`, counted by
splitting each baseline key on `|`:

| Category | Base | Tip | Δ | Cause |
|---|---|---|---|---|
| C1 | 12 | 12 | **0** | frozen, as required |
| C2 | 251 | 250 | **−1** | T10's hand-removed line for the deleted `src/features/pos/pages/ShiftDashboardPage/ShiftDashboardPage.tsx` |
| C3 | 459 | 458 | **−1** | T10's hand-removed line for the deleted `src/pages/POS/POSShiftsDashboard.tsx` |
| C4 | 14 | 14 | **0** | frozen, as required |
| C5 | 2 | 2 | **0** | frozen. The diff shows one C5 line removed **and re-added byte-identically** (`RepositoryMovementsTab.tsx`) — a trailing-comma reflow, because that entry stopped being the file's last line once C6 entries were appended. Net movement zero. |
| C6 | 0 | 82 | **+82** | T7's detector fix (expected and authorised) |
| **total** | **738** | **818** | **+80** | |

That is exactly the movement §4 item 3 permits: C6 grows, C2 −1 and C3 −1 both
traceable to the two deleted files, C1/C4/C5 do not move. The full list of
non-C6 removals in the branch's baseline diff is the three lines above and
nothing else. **`--write-baseline` was not used at T10** (the two lines were
deleted by hand); T7 regenerated the baseline in its own commit, which is the
authorised C6 growth.

**F-1 constraint verified still held at final state:** the three `BarcodeHero`
baseline entries are **present** — `:223` and `:224` (C2) and `:664` (C3) — and
`apps/web/src/features/products/editor/components/BarcodeHero.tsx` and
`ProductEditHero.tsx` both still exist, with the unconditional `successDot` still
at `ProductEditHero.tsx:136`. T6 and T8-b produced no commit, as ruled.

### §4 item 4 — a grep per deleted route proving zero remaining references — **PASS**

```text
$ cd apps/web
$ grep -rnE "/pos/shifts" src --include='*.ts' --include='*.tsx'
src/features/pos/api/shiftHistoryApi.ts:39: * Backend: GET /api/v1/pos/shifts
src/features/pos/api/shiftHistoryApi.ts:52:  return apiGet<PaginatedShifts>(`/pos/shifts${query ? `?${query}` : ''}`)
src/features/pos/api/shiftApi.ts:77:    return await apiGet<CurrentShift>(`/pos/shifts/current/${terminalId}`)
src/features/pos/api/shiftApi.ts:88:  return apiPost<CurrentShift>('/pos/shifts/open', data)
src/features/pos/api/shiftApi.ts:95:  return apiPost<CurrentShift>(`/pos/shifts/${shiftId}/close`, { actual_cash: actualCash })
src/features/pos/api/shiftApi.ts:156:  return apiGet<ShiftReceipt[]>(`/pos/shifts/${shiftId}/receipts?page=${String(page)}`)
```

**Called out explicitly:** all six hits are the **permitted** API path strings in
`features/pos/api/shiftApi.ts` and `shiftHistoryApi.ts` (five call sites plus one
doc comment naming the backend endpoint). These are HTTP paths, not routes, and
the brief explicitly keeps them. The route-shaped grep is zero:

```text
$ grep -rnE "pos/shifts\"|'/pos/shifts'|POSShiftsDashboard|ShiftDashboardPage" src --include='*.ts' --include='*.tsx'
$ echo $?
1        # zero hits

$ grep -rn "ShiftDashboardPage" src --include='*.md'
src/features/pos/README.md:3:> Superseded 2026-08-11: `ShiftDashboardPage` was deleted; use `/pos/shift-history` …
src/features/pos/README.md:22, :99, :149, :270, :520
src/features/pos/IMPLEMENTATION_SUMMARY.md:3:> Superseded 2026-08-11: `ShiftDashboardPage` was deleted; use `/pos/shift-history` …
src/features/pos/IMPLEMENTATION_SUMMARY.md:109, :306, :307, :308, :367
```

T10 took the brief's **"retain as historical"** disposition: both files carry the
dated superseded note at `:3`, and every remaining hit is inside those two
annotated files. This is stated rather than claimed as a zero.

```text
$ grep -n "ShiftDashboardPage\|POSShiftsDashboard" tools/audit-design-system-baseline.json
$ echo $?
1        # zero

$ grep -rnE "/marketing\b|MarketingHubPage|marketing:" src
$ echo $?
1        # zero

$ grep -rn "FinanceHubPage" src
$ echo $?
1        # zero

$ grep -rnE "to=\"/finance\"|to=\{'/finance'\}|navigate\('/finance'\)|href=\"/finance\"" src \
    --include='*.ts' --include='*.tsx' | grep -vE "\.test\.tsx?:|__tests__/|/test/"
$ echo $?
1        # zero — production-only

$ grep -rn "Navigate" src/routes/index.tsx | grep -i finance
$ echo $?
1        # zero — the superseded redirect was NOT introduced

$ grep -rn "settings/chart-of-accounts" src
$ echo $?
1        # zero
```

**The filtered `/finance` zero, made honest — the seven intentional non-production
literals the filter excludes**, all generic `HubCard` fixture data in
`src/components/molecules/HubCard/HubCard.test.tsx`, which the brief orders left
untouched:

```text
12:        <HubCard to="/finance" icon={CreditCard} title="Finance" />
22:          to="/finance"
35:        <HubCard to="/finance" icon={CreditCard} title="Finance" />
54:        <HubCard to="/finance" icon={CreditCard} title="Finance" />
63:        <HubCard to="/finance" icon={CreditCard} title="Finance" />
87:          to="/finance"
103:          <HubCard to="/finance" icon={CreditCard} title="Finance" />
```

`git diff --stat d682b38ec..HEAD -- apps/web/src/components/molecules/HubCard/HubCard.test.tsx`
is **empty** — the file is byte-identical to base. The other two T12 consumers
moved as ruled:

```text
$ git diff --stat d682b38ec..HEAD -- apps/web/e2e/money-campaign/finance-permissions.spec.ts apps/web/tools/ui-audit-shots.mjs
 .../e2e/money-campaign/finance-permissions.spec.ts | 62 +---------------------
 apps/web/tools/ui-audit-shots.mjs                  |  1 -
 2 files changed, 2 insertions(+), 61 deletions(-)
$ grep -rn "MTP-GL-28" apps/web/e2e ; echo $?
1        # removed
$ grep -n "finance" apps/web/tools/ui-audit-shots.mjs ; echo $?
1        # the ['24-finance', '/finance'] entry is gone, neighbours not renumbered
```

The one i18n key that had to survive does, with the exact ruled values in all
three locales, and every other `finance:hub.*` key is pruned:

```text
en hub keys: ['cards']  cards: {"treasuryOverview": {"title": "Treasury"}}
fr hub keys: ['cards']  cards: {"treasuryOverview": {"title": "Trésorerie"}}
ar hub keys: ['cards']  cards: {"treasuryOverview": {"title": "الخزينة"}}
```

### §4 item 5 — `pnpm typecheck`, `pnpm lint`, `pnpm test` in `apps/web` — **PASS, subject to the reviewed M0b exceptions**

```text
$ pnpm typecheck
> tsc --noEmit
$ echo $?
0

$ pnpm lint      # chains lint:eslint + audit:keys + audit:design-system + audit:quantity + test:eslint-rules
✖ 6520 problems (0 errors, 6520 warnings)
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[gate-summary] Design-system baseline: 818 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
no-dead-tailwind-token-interpolation: all RuleTester cases passed (5 valid, 5 invalid)
no-hardcoded-step: all RuleTester cases passed (6 valid, 3 invalid)
no-literal-decimal-places: all RuleTester cases passed (6 valid, 3 invalid)
$ echo $?
0
```

**0 errors**; the 6520 warnings are the wave's pre-existing count, unchanged
since M6.

The deterministic full suite, single worker:

```text
$ pnpm test -- --maxWorkers=1
 Test Files  3 failed | 665 passed (668)
      Tests  5 failed | 4259 passed | 1 skipped | 3 todo (4268)
   Duration  707.45s
```

**The full red list, enumerated against the YAML's exception list — every red is
a reviewed M0b exception and there is no other:**

| # | Failing file | Failing tests | On the exception list? |
|---|---|---|---|
| 1 | `src/__tests__/i18n/arLocaleCoverage.test.ts` | 3 — `ar/common.json` (missing **122** keys), `ar/vehicles.json` (**19**), `ar/workshop-technicians.json` (**1**) | **Yes** — owner-confirmed 2026-08-18, owned by `CODEX-DISPATCH-arabic-i18n-backfill-2026-08-10.md`. The three counts are **unchanged** from M6, on namespaces this wave never touched. |
| 2 | `tools/__tests__/offset-pagination-meta-consolidation.test.mjs` | 1 — `OffsetPaginationMeta > is reused by every production offset-pagination response type` | **Yes** — M0b bridge-confirmed real defect (inline pagination meta at `src/features/treasury/statements/api.ts:112`). Not accepted as a standing gap: the parent must route it to a live production-fix lane before merge. |
| 3 | `src/components/__tests__/SharedSingletons.tenantScope.test.tsx` | 1 — `shared singleton tenant scope > scopes modal invalidations (.001-.003)` | **Yes** — M0b bridge-confirmed real defect (`AddQuickProductModal.tsx:209` invalidates the foreign-tenant products cache through a bare `['products']` prefix). Same forward-owner requirement. |

**Zero reds outside the exception list**, and none of the load-induced timeout
flakes seen in earlier concurrent runs recurred under `--maxWorkers=1`. No orphan
vitest worker pools survived the run (`ps aux | grep 'node (vitest'` → 0).

### §4 item 6 — UI E2E — **ENVIRONMENT-BLOCKED, with the blocker named**

`e2e/money-campaign/*` runs against a **live local stack** — "web :5173 → api
:8010, tenant `demo-pharmacy-tn`, real login through the real form, real
backend, no mocks" (`finance-permissions.spec.ts:1-19`). No such stack exists
for this worktree: nothing is listening on `:8010`, and `apps/api/vendor/` is
absent here so the API cannot be started from this worktree either.

The run was attempted rather than assumed. Playwright's own `webServer` started
Vite successfully on `:5173`, and every API call was refused:

```text
$ cd apps/web && pnpm exec playwright test --max-failures=1 --reporter=line --workers=1
[WebServer] [vite] http proxy error: /api/v1/onboarding/status
AggregateError [ECONNREFUSED]
[WebServer] [vite] http proxy error: /api/v1/documents?limit=5&sort=-created_at
AggregateError [ECONNREFUSED]
  1) [chromium] › e2e/add-to-inventory.spec.ts:38:3 › … Test timeout of 30000ms exceeded.
Testing stopped early after 1 maximum allowed failures.
  1 failed
  459 did not run
$ curl -s -o /dev/null -w '%{http_code}' http://localhost:8010 ; echo
000
```

The single failure is the first spec alphabetically dying on a refused backend
call — an **environment** failure, not a wave regression. `--max-failures=1`
bounded the run deliberately: with no backend, all 460 specs fail identically and
a full run produces no additional information.

**Consequence for the brief's specific E2E requirement:** the assertion that
`e2e/money-campaign/finance-permissions.spec.ts` **passes** with `MTP-GL-28`
removed and every other test untouched **could not be executed here**. What is
verified statically is that `MTP-GL-28` is gone (`grep -rn "MTP-GL-28"
apps/web/e2e` → zero) and that the file's only other change is that removal
(`62 +---------------------`, 2 insertions / 61 deletions, i.e. the deleted block
plus its two-line header amendment). **No spec was intentionally skipped**, so
the screenshot-per-skip rule does not apply; the whole suite is blocked, which is
recorded here instead. This item is handed to the parent's terminal audit, which
owns the visual/E2E pass against a live stack.

## Blocked items (§5 item 4)

| Item | Status | Evidence | Decision needed |
|---|---|---|---|
| **T6 — `UI-14`**, unconditional green `successDot` in the product hero | **BLOCKED. No commit.** | Target is `features/products/editor/components/ProductEditHero.tsx:136`, inside the `features/products/editor/` directory that owner ruling **OQ-4 (PARKED)** puts off-limits pending the unmerged Rafiq round-2 worktree. The dot is still unconditional at final state. | Owner gate **F-1**: confirm the block and reschedule behind the Rafiq decision, **or** explicitly authorise the change as merge-conflict-tolerable — and, if so, name who reconciles `audit-design-system-baseline.json` against the Rafiq branch. The gate-r1 reviewer's merge-risk assessment lowers the estimated risk; it is **not** authorisation and was not acted on. |
| **T8-b — `UI-43`'s `BarcodeHero` claim** | **BLOCKED. No commit.** | Same parked directory. `BarcodeHero.tsx` still exists; its three baseline entries (`:223`, `:224` C2, `:664` C3) are still present, as F-1 requires at final state. | Same gate. |
| **`UI-43`'s `touchOptimized` claim** | **NOT IMPLEMENTED, and unassigned.** | Still present across the POS component set (27 files match, of which 15 are the production TSX files the audit counts). Nothing in this wave touched it. | Owner gate **F-2b**: the parent must dispatch it, obtain a recorded reassignment to a named later wave, or accept it as a standing Wave-0 gap. Until then `UI-43` cannot be reported closed. |

## Discovered findings not in scope (§5 item 5) — reported, not fixed

1. **`TermsOfServicePage.tsx:37-38, :49-50, :107-108, :131-132`** — hardcoded brand copy, **ten** occurrences across eight lines (the brief said six), each an inline non-`t()` string doing its own FR/EN branching in JSX. That second part is also a rule-11 violation. Out of T14's scope.
2. **`touchOptimized` ownership gap (F-2b)** — 15 production TSX files under `apps/web/src/features/pos/**` carry the prop; no owner ruling moves the claim out of Wave 0. This is an **ownership** finding, not a code finding.
3. **`bankingAndPayments` Treasury module gate (T15 / F-5)** — `Sidebar.tsx:272` gates the whole group on `module: 'Treasury'`, so the `/expenses` item T15 repaired stays hidden for tenants without the Treasury backend module regardless of role. Report-only by the brief's instruction.
4. **Stray tracked nested duplicate tree `apps/web/apps/web/src/`** — 5 files, introduced by `9a159d09c`. Unreachable (`tsconfig.json:54` includes only `src`, nothing in `src` imports into it), but `apps/web/apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx:83` now references a locale key T8-c deleted. Parent ticket to delete the five files.
5. **`Sidebar.tsx:143`** — the docblock still says the Automotive group "is hidden downstream by the `isModuleEnabledForVertical('automotive')` filter". No such function exists in `apps/web/src`; the real gate is `isNavItemVisible` on `module: ['Vehicle','Workshop','PlatformIntegration']`. The described **behaviour** is correct; only the named function is stale. T9 is docs-only, so the source comment was left alone.
6. **Four newly zero-consumer POS API exports** left behind by T10's deletion (recorded at M3 round 2) — kept because deleting API clients is out of T10's scope.
7. **M1's close-before-merge P2 (still open)** — applying the re-census's stated conservative secondary-surface rule literally yields **35**, not the 33 the report adopts; the report-path ordinal collision is also a parent merge-time decision.
8. **M3's `/finance` blank-pane contradiction (still open, parent/owner pre-merge gate)** — keeping the `finance` parent while deleting its index and adding no redirect makes `/finance` match the element-less parent. The implementation follows the brief exactly; the owner must confirm the resulting behaviour before merge.
9. **M6 register carry-overs**: P3-2 the selected-chip primary accent competing with the page CTA (route to the UI audit as a canonical-chip-treatment question — explicitly do-not-change in this wave); P3-3 **at merge time, re-run `node apps/web/tools/audit-design-system.mjs` on the MERGED tree and require 0 new / 0 stale — do NOT `--write-baseline` to absorb anything** (the 818-entry C6 baseline was regenerated against this branch); P3-4 the stray tree in item 4 above.
10. **M4 register P3 carry-overs**: the CI workflow does not trigger on direct pushes to `dev`; the drift job's workspace install may be broader than the generator needs; the scalar YAML `commit:` field records T3(b) while T3(a)'s SHA lives in this handback.
11. **The two M0b real-defect exceptions are production bugs, not test debt** — `src/features/treasury/statements/api.ts:112` (inline pagination meta) and `AddQuickProductModal.tsx:209` (bare `['products']` invalidation crossing tenants). Both must be routed to a live production-fix lane before merge; neither is accepted as a standing gap.

## Deviations from the brief (§5 item 6)

1. **The `MTP-GL-28` E2E block was deleted** from `e2e/money-campaign/finance-permissions.spec.ts` (T12). Authorised by this brief and by `OWNER-DECISIONS:58`, and recorded here because deleting an E2E test is deviation-grade regardless of authorisation. Every other test in that file is untouched.
2. **A second manifest commit exists** (`a6dc879b8`), against the brief's "T3(a) is the only manifest commit" rule. Authorised by the parent's verbatim M5 ruling, quoted in the progress YAML under `blockers:`; the commit contains manifests only and its regen changed exactly one entry.
3. **T5 was implemented through the canonical `Button` variants** rather than `className` overrides (see M6/T5). The brief permits changing the variant when matching the house pattern requires it, and it does here.
4. **T7's detector fix went beyond the brief's stated `Tone`/`Tones` suffix rule**, adding a fourth alternation keyed on `Record<…, StatusTone>`, because a suffix-only fix would have missed most of F-8's population. Rationale recorded in the source docblock, the commit message and the plan doc.
5. **T10 took the "retain as historical" documentation disposition** (both POS markdown files annotated with a dated superseded note) rather than the preferred "edit out the current-tense sections", and the T10 docs grep is reported accordingly rather than as a bare zero.
6. **M5–M8 were executed by the parent orchestrator's implementation agent (Claude), not the Codex desktop executor**, after that executor exhausted its quota post-M4. Same worktree, same branch, same commit series, same self-gating harness. Recorded in the progress YAML.
7. **`./scripts/preflight.sh` was not completed** — it is environment-blocked at Pint (§4 item 1). Its frontend stages were run individually instead. Reported as blocked, never as green.
8. **The E2E suite was not run to completion** — environment-blocked, no backend (§4 item 6). The attempt was bounded with `--max-failures=1` deliberately.
9. **Vitest was run only at `--maxWorkers=1`**, including where `preflight.sh` would have run it at the default worker count. This is the wave's own determinism rule (M0b) and the laptop-safety rule; multi-worker evidence was already shown to produce load-induced flakes.

## Completion language (§5 item 7)

**`UI-43` is `PARTIAL`.** Its four split claims:

| `UI-43` claim | Status | Where |
|---|---|---|
| `canAccessModule` fail-closed (T8-a) | **DONE** | delivered inside T4, M5, commit `77257bf88` |
| orphaned "coming soon" locale keys (T8-c) | **DONE** | M6, commit `ee87f153e` — 13 key instances across five bundles |
| `BarcodeHero` (T8-b) | **BLOCKED** | owner gate F-1, parked directory; no commit |
| `touchOptimized` | **NOT IMPLEMENTED, UNASSIGNED** | owner gate F-2b; no owner ruling moves it out of Wave 0 |

**The standing constraint records, restated rather than softened:**

- **F-1** — `T6` and `T8-b` remain non-executable blocked records. The gate-r1 merge-risk assessment lowers the estimated risk and is **not** authorisation; it was not acted on. The three `BarcodeHero` baseline lines are present at final state, as required.
- **F-2b** — `touchOptimized` has no recorded owner. Neither this wave nor `UI-43` may be reported complete until the parent dispatches it, records a reassignment, or accepts it as a standing gap.
- **F-3** — T4 tightens live navigation for real roles (`goods-receipt.create-standalone` becomes admin/manager-only; `inventory.view` becomes a real check). User-visible, acknowledged.
- **F-4** — the `/finance` deletion produces a catch-all fallthrough, with no redirect, by owner ruling. The `11-research-finance-hub.md` `OQ-1` viewer-role regression is moot under "delete outright". M3's blank-pane contradiction (Discovered findings item 8) is the live question here.
- **F-5** — T15 changes live navigation in the opposite direction, adding `/expenses` for `cashier`, `operator` and `viewer`, subject to the `bankingAndPayments` Treasury module gate.
- **F-6** — resolved before M0: `commit_series: "Phase 0.<task#>.<seq>"`, quoted verbatim in the Header.
- **DO-NOT-TOUCH set** — respected throughout: no change under `features/products/editor/**`, none to `LaneSeparationReportPage` or `/finance/lane-separation`, none to the events/shift-variance dossiers, no Wave-3 listing migration, no removal of the `colorClasses` ESLint carve-outs and no importer migrated, and **zero** `apps/api/**` files changed.

Per-finding status is the report; there is no closure claim here. The branch is
handed to the parent Claude session, which owns the terminal audit, the visual
E2E pass and the merge into `dev`. Nothing was pushed.
