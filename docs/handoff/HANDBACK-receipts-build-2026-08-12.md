# POS receipts reporting build — implementer report

- Base SHA: `7d85232cc54abd6a6b2135f476205ab434e71a66` (fresh `origin/dev` at dispatch)
- Branch: `codex/pos-receipts-2026-08-12`
- Worktree: `.worktrees/receipts-build`
- Executor does not merge or push; the parent owns terminal audit and integration.

## Wave 1 / M1

### Commits

- `62f1cb04e` — Phase 1.1.1: Add scoped receipt register API
- `dcc02f781` — Phase 1.1.2: Add receipt filter options and indexes
- `234eef5a6` — Phase 1.1.3: Align receipt reporting permissions
- `295025b65` — Phase 1.1.4: Generate receipt reporting DTOs
- `42d2ff151` — Phase 1.1.5: Build read-only receipt register
- `be3d747e2` — Phase 1.1.6: Isolate receipt date helpers
- `8c74616f6` — Phase 1.1.7: Close positive refund consumer gate
- `d07f48a0c` — Phase 1.1.8: Complete receipt register contract coverage
- `69e158be0` — Phase 1.1.9: Complete receipt register states
- `d0938ce88` — Phase 1.1.10: Add receipt permission browser flow
- `f2bd463b8` — Phase 1.1.11: Satisfy receipt design-system gates

### Spec-item status and evidence

- **S-1/S-2/S-3/S-13 — DONE.** The company/location-scoped index, validated type/training and fiscal-status axes, receipt-number search, company-timezone half-open date bounds, capped pagination, receipt-currency formatting, and frozen double envelope are in `ReceiptController.php:69-183`. Red-first evidence: `ReceiptIndexEnvelopeTest`, `ReceiptIndexTrainingExclusionTest`, `ReceiptIndexTypeFilterTest`, `RefundRegisterPaginationTest`, `ReceiptIndexLocationScopeTest`, `ReceiptIndexFiscalStatusFilterTest`, and `ReceiptFilterDateBoundaryTest` initially failed against the legacy action. The completed focused backend run reported `32 tests, 162 assertions`; the expanded training matrix exposed a real failure (toggle-without-codes returned only SALE) before `ReceiptController.php:119` was corrected.
- **S-6 — DONE.** Both additive, unattended-safe reporting indexes are in `2026_08_17_100000_add_receipt_reporting_indexes.php:13-27`; `ReceiptReportingIndexesTest` locks names, columns and the production partial predicate.
- **S-7/S-11 index-options half — DONE.** `ReceiptFilterOptionsController.php:25-94` authorizes the exact read permission, intersects membership scope, includes inactive terminals and v4 authoring state, and uses in-scope receipt snapshots for cashiers. `ReceiptFilterOptionsTest` failed before the endpoint/route existed and now covers exact flags plus fail-closed empty scope.
- **S-9/A-2 — DONE.** The accountant block adds exactly `pos.view_receipts`, `pos.view_reports`, and `deliveries.view` at `RolesAndPermissionsSeeder.php:773-802`; `AccountantReceiptPermissionsTest` refuses `dashboard.owner` and POS operations/management. The generated frontend map was regenerated in the same change. `ReceiptAuthorizationTest` proves the resulting receipt/report surface while `/pos/terminals` stays 403.
- **Screen (a) — DONE.** The read-only route is exact-gated at `routes/index.tsx:2921-2928`; `ReceiptListPage.tsx:42-285` uses `ListPageLayout`, `DataTable`, `useTableState({syncToURL:true})`, company-timezone defaults, location-scoped query keys, explicit per-row currency, a training header state, muted training rows, three empty states and clear-filters recovery. Red-first evidence: the page/API/route tests first failed on missing modules and route; later FT-2/FT-10 assertions failed on the always-present false toggle field and missing clear action before the implementation was tightened. `ReceiptListPage.test.tsx` now covers FT-1/2/3/10/11 and the list half of FT-16, including a French render.
- **A-1/GATE-3/GATE-5/OP-23 — DONE.** The six exact POS child identities and composite compliance identity are in `usePermissions.ts:56-67`; the sidebar is re-keyed at `Sidebar.tsx:229-246`, with the compliance leaf immediately above Settings at `Sidebar.tsx:359-373`. Compliance export uses any-of raw panel permissions and each panel retains its own exact gate. Fraud settings/alerts routes use `fraud-settings.view` / `fraud-alerts.view` at `routes/index.tsx:2447-2463`, with no new fraud nav. `ReceiptPermissionParity.test.ts` and the 41-test Sidebar suite cover the mapping and route parity. OP-23 residual: the two fraud pages still have no decided IA home; OQ-10 owns that decision.
- **CL-1/CL-2/CL-5/CL-6 — DONE.** `ShiftReceiptsList.tsx` and its barrel export were deleted; `shiftApi.ts:146-158` points to the canonical register; `POSTransactions.tsx` makes no false web-return promise and links to `/pos/receipts`; `receiptApi.ts:8-21` records the read/write boundary.
- **CL-7 — DONE.** The positive-refund aggregate ticket is closed with current-source evidence in `docs/superpowers/tickets/2026-08-01-positive-refund-total-consumers.md:1-22`; the money campaign retains mixed-era regressions without listing an open pre-enable gate.
- **i18n rename — DONE.** The old `receiptSearch` block is now `receipts` in EN/FR, obsolete void-control copy is removed, and all M1 list/status/empty-state copy is translated. AR is the parallel lane; keys handed over: `pos:receipts.{title,description,receiptNumber,terminal,cashier,date,type,location,total,status,searchPlaceholder,includeTraining,trainingIncluded,tableLabel,resolvedWindow,types.*,fiscalStatuses.*,empty.*,filters.*}` plus `pos:transactions.disposition.receiptsLink`.
- **BT-8/9/11/12/16/18 — DONE.** Focused classes are `ReceiptFilterOptionsTest`, `ReceiptAuthorizationTest`, `AccountantReceiptPermissionsTest`, `ReceiptIndexLocationScopeTest`, `ReceiptReportingIndexesTest`, and `ReceiptFilterDateBoundaryTest`. BT-10 is the spec-marked optional planner assertion and was not added; BT-16 provides deterministic structural coverage instead.
- **FT-14/FT-15 — DONE.** Static exact mapping plus Sidebar behavior coverage proves the intended least-privilege re-key. Owner-visible result: accountants see Receipts/Z/Analytics/Vouchers, cashiers keep only enterable children, and Tables/Terminals/report links disappear when their exact route permission is absent.
- **FT-16 — PARTIAL by wave design.** The SALE register emits only SALE, or SALE+TRAINING with the switch; REFUND/VOID controls do not exist. The refunds-register half ships in wave 2.

Wave-1 expected limitation: voucher receipt links remain dead until CL-3/CL-4 in wave 2.

### Addendum A evidence

- Composite compliance key, A-1 nav/route and OP-23 exact fraud gates are locked by `ReceiptPermissionParity.test.ts` and `AccountantReceiptPermissionsTest.php`.
- No `reports.financial` grant or `/finance/lane-separation` route change was added.
- Differing-currency proof: `ReceiptIndexEnvelopeTest` sets company currency EUR, receipt currency TND, and asserts `TND` / `12.345`; the page test renders `12,345 TND` from the row despite company context.
- Cross-receipt aggregate grep: `git diff -U0 7d85232cc..HEAD -- apps/api apps/web | rg '^\+.*\bSUM\s*\('` returned no matches. No total strip, footer sum, VAT roll-up, export total, or aggregate endpoint was added.
- `git diff --name-only 7d85232cc..HEAD -- apps/pos` returned no paths.

### Verification

- Focused new backend set: PASS (`32 tests, 162 assertions` for the expanded training/options/date contract; `ReceiptAuthorizationTest` separately PASS, `2 tests, 9 assertions`; the earlier complete new M1 set PASS, `16 tests, 78 assertions`).
- Focused M1 frontend files: PASS (`receiptApi`, `ReceiptListPage`, route parity and Sidebar; typecheck PASS). React Doctor changed-scope scan: score `91/100`, no issues.
- Design-system audit after CL-1 baseline cleanup: PASS (`737 acknowledged, 0 new, 0 stale`). Query-key audit inside `pnpm lint`: PASS (`0 new`).
- Exact scoped preflight: **BLOCKED by baseline/unrelated Pint drift before reaching later stages**. Pint named files outside this lane plus pre-existing `tests/Feature/POS/ZReportListTest.php`; none were edited because the brief forbids unrelated cleanup.
- Exact scoped Vitest command: **PARTIAL** — `545 passed`, with three failures outside the receipt changes: stale expected location-scoped keys in `reportPages.tenantScope.test.tsx` and `useAnalytics.tenantScope.test.tsx`, plus the existing POSPage quantity assertion. The new receipt/API/route/Sidebar tests pass.
- `pnpm lint`: **PARTIAL** — ESLint reports repository-wide warning debt (0 errors), the query-key audit passes, and the feature's initial two raw-date-input violations plus deleted-file stale baseline were fixed. The receipt-focused ESLint invocation is clean.
- `pnpm typecheck`: PASS.
- Playwright flow file compiles (`--list`: 1 test). Live run: **ENVIRONMENT BLOCKED** — Vite started but its `/api/v1` proxy received `ECONNREFUSED` because the local API was not running at `127.0.0.1:8010`; login stayed on `/login`. This is not reported green.
- M1 screenshots (empty/populated/training): **BLOCKED by the same unavailable live API**. Wave-2 screenshots are not yet due.

### Owner-visible behavior and deployment

- OI-14: the POS sidebar now hides children whose exact route permission the role lacks; this intentionally removes bouncing links from existing roles.
- OI-16: Compliance export is a new top-level bottom-section link immediately above Settings for holders of any compliance panel permission.
- Accountant gains exactly `pos.view_receipts`, `pos.view_reports`, and `deliveries.view`.
- Before the first tenant reseed, snapshot tenant role→permission state: the seeder re-syncs all seven seeded roles and can revoke manual grants. Rerun `RolesAndPermissionsSeeder` per tenant, then run `php artisan permission:cache-reset` (mandatory tenant-blind Spatie cache reset). Deploy the GATE-5 re-key in the same release. Acceptance must load `/pos/receipts` and `/settings/compliance/export` as the accountant and confirm `/pos/terminals` remains denied.

### Decisions, deviations and out-of-scope findings

- No product decision outside the spec was made. Two generic presentation seams were extended without changing existing behavior: `DataTable.getRowClassName` for the required muted training row and `EmptyState.action` for the required clear-filters recovery.
- Verification deviations are explicit above: live E2E/screenshots unavailable; global preflight/lint/scoped-suite baselines are not silently called green.
- F-3 residual: fraud pages are exact-gated but deliberately have no new nav; OQ-10 owns their IA home.
- F-4: no unit label, event-version-5 preparation, or `apps/pos/**` change exists. Wave 2 will add only `quantity_decimals` as explicitly allowed.
