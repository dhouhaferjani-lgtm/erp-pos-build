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
- `24808cc63` — Phase 1.1.12: Record receipt wave evidence
- `cca51e7e2` — Phase 1.1.13: Repair receipt review findings
- `82ddc74b0` — Phase 1.1.14: Record receipt review repairs
- `6baf6adfc` — Phase 1.1.15: Close receipt route gate coverage
- `e72a1abb5` — Phase 1.1.16: Record second receipt review
- `af12e82fd` — Phase 1.1.17: Preserve archived receipt terminals

### Spec-item status and evidence

- **S-1/S-2/S-3/S-13 — DONE.** The company/location-scoped index, validated type/training and fiscal-status axes, literal-safe receipt-number search, company-timezone half-open date bounds, capped pagination, receipt-currency formatting, and frozen double envelope are in `ReceiptController.php`. Legacy `receipt_type` callers retain their response key and filtering semantics; pre-fiscal returns whose newer type column is still `SALE` are excluded from the sales register and projected as `REFUND` into the refunds axis. Archived terminals remain resolvable on historical receipt rows through the receipt relation's `withTrashed()` contract. Red-first evidence includes the pre-existing `ReceiptReturnFlowTest` regression plus legacy-projection, wildcard-escaping, legacy-training, and archived-terminal cases.
- **S-6 — DONE.** Both additive, unattended-safe reporting indexes are in `2026_08_17_100000_add_receipt_reporting_indexes.php`; `PosReceiptsIndexMigrationStructureTest` queries `pg_indexes`, asserts both definitions and predicate, reruns `up()` idempotently, exercises `down()`, and is explicitly included in the PostgreSQL CI filter. A local PostgreSQL run passed (`1 test, 6 assertions`).
- **S-7/S-11 index-options half — DONE.** `ReceiptFilterOptionsController` authorizes the exact read permission, intersects membership scope, includes inactive terminals and v4 authoring state, and derives the latest in-scope cashier snapshot with `ROW_NUMBER() OVER (PARTITION BY cashier_id …)` in SQL rather than materializing all receipts in PHP. `ReceiptFilterOptionsTest` locks the database-reduction query, fail-closed scope, and cross-company exclusion; it is included in the PostgreSQL CI filter.
- **S-9/A-2 — DONE.** The accountant block adds exactly `pos.view_receipts`, `pos.view_reports`, and `deliveries.view` at `RolesAndPermissionsSeeder.php:773-802`; `AccountantReceiptPermissionsTest` refuses `dashboard.owner` and POS operations/management. The generated frontend map was regenerated in the same change. `ReceiptAuthorizationTest` proves the resulting receipt/report surface while `/pos/terminals` stays 403.
- **Screen (a) — DONE.** The read-only route is exact-gated at `routes/index.tsx`; `ReceiptListPage` waits for an active company before deriving the business day, uses `ListPageLayout`, `DataTable`, `useTableState({syncToURL:true})`, location-scoped query keys, explicit per-row currency, a training header state, muted training rows, and the exact three states (default empty, filtered with clear action, and no tenant scope with no requests). Tests cover the company-timezone hydration boundary, EN/FR, FT-1/2/3/10/11, and the list half of FT-16.
- **A-1/GATE-3/GATE-5/OP-23 — DONE.** The six exact POS child identities and composite compliance identity are in `usePermissions.ts`; every child remains keyed to its route contract and the compliance leaf sits immediately above Settings. `ReceiptPermissionParity.test.ts` locks the source mapping, the 44-test Sidebar suite behaviorally renders exact accountant/cashier href sets and proves zero-child groups disappear, `ReceiptReportingRouteGateTest` exercises all five backend reads with the seeded accountant and a `settings.view`-only role, and `ComplianceRoutePermissions.test.tsx` mounts the real route tree and `RequirePermission` for all three frontend routes. Mutation proof confirmed that reverting those gates to `settings.view` fails both suites. OP-23 residual: the two exact-gated fraud pages still have no decided IA home; OQ-10 owns that decision.
- **CL-1/CL-2/CL-5/CL-6 — DONE.** `ShiftReceiptsList.tsx` and its barrel export were deleted; `shiftApi.ts:146-158` points to the canonical register; `POSTransactions.tsx` makes no false web-return promise and links to `/pos/receipts`; `receiptApi.ts:8-21` records the read/write boundary.
- **CL-7 — DONE.** The positive-refund aggregate ticket is closed with current-source evidence in `docs/superpowers/tickets/2026-08-01-positive-refund-total-consumers.md:1-22`; the money campaign retains mixed-era regressions without listing an open pre-enable gate.
- **i18n rename — DONE.** The old `receiptSearch` block is now `receipts` in EN/FR, obsolete void-control copy is removed, and all M1 list/status/empty-state copy is translated. AR is the parallel lane; keys handed over: `pos:receipts.{title,description,receiptNumber,terminal,cashier,date,type,location,total,status,searchPlaceholder,includeTraining,trainingIncluded,tableLabel,resolvedWindow,types.*,fiscalStatuses.*,empty.*,filters.*}` plus `pos:transactions.disposition.receiptsLink`.
- **BT-8/9/11/12/16/18 — DONE.** `AccountantReceiptPermissionsTest` and `ReceiptAuthorizationTest` run the real seeder and seeded accountant role, including explicit refusal of `pos.process_returns` and `/pos/terminals`; `ReceiptFilterOptionsTest` covers both cross-location and cross-company exclusion. The remaining focused classes are `ReceiptIndexLocationScopeTest`, `PosReceiptsIndexMigrationStructureTest`, and `ReceiptFilterDateBoundaryTest`. A truly empty `invoice_type_codes` array now exercises `min:1`, and explicit codes take precedence over the legacy type axis so `include_training` cannot widen the result independently.
- **FT-14/FT-15 — DONE.** Static mapping plus real Sidebar rendering proves the intended least-privilege re-key. Accountant visible POS hrefs are exactly Receipts/Z/Analytics/Vouchers plus Compliance export; cashier visible POS hrefs are exactly Orders/Kitchen/Shift History/Receipts/Vouchers, with Tables/Terminals/report routes hidden.
- **FT-16 — PARTIAL by wave design.** The SALE register emits only SALE, or SALE+TRAINING with the switch; REFUND/VOID controls do not exist. The refunds-register half ships in wave 2.

Wave-1 expected limitation: voucher receipt links remain dead until CL-3/CL-4 in wave 2.

### Addendum A evidence

- Composite compliance key, A-1 nav/route and OP-23 exact fraud gates are locked statically and behaviorally by `ReceiptPermissionParity.test.ts`, `ComplianceRoutePermissions.test.tsx`, `AccountantReceiptPermissionsTest.php`, and `ReceiptReportingRouteGateTest.php`.
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
- M1 review round 1: `CHANGES-REQUIRED`; register committed at `docs/handoff/reviews/receipts-build/M1-round1.md`. All six numbered P1/P2 findings were repaired in `cca51e7e2` with red-first regression evidence.
- M1 review round 2: `CHANGES-REQUIRED`; register at `docs/handoff/reviews/receipts-build/M1-round2.md`. Its sole P2 (missing behavioral route-gate coverage) is repaired in `6baf6adfc`; the new backend and frontend suites pass, and mutation checks fail on the exact `settings.view` regression. The low-risk P3s for NG-5 comments, PostgreSQL filter-options coverage, legacy/new-axis precedence, empty-array validation, seeded accountant authorization, and cross-company filter options were also closed.
- M1 review round 3: `CHANGES-REQUIRED`; register at `docs/handoff/reviews/receipts-build/M1-round3.md`. Its P1 reproduced an HTTP 500 when a receipt's terminal had been archived. `af12e82fd` fixes the relationship at its source with `withTrashed()` and adds a red-first historical-code regression. The remaining legacy-toggle and vacuous-fixture P3s were also closed. The expanded focused backend slice passes (`72 tests, 366 assertions, 1 PostgreSQL-only skip`), POS PHPStan level 8 passes, and focused Pint passes.
- Full level-8 PHPStan reaches only two existing precision errors in `CopiesDocumentData.php`; both receipt controllers pass level 8 with no errors.
- Exact backend paths completed in 574.77s. All receipt reporting and `ReceiptReturnFlowTest` cases passed; the command ended on 21 unrelated pre-existing failures in `CashCountValidationServiceTest` and `ZReportHashServiceTest` (`FraudSettingsDTO` fixture arity and duplicate shift fixtures). The new PostgreSQL migration contract separately passed on PostgreSQL (`1 test, 6 assertions`).
- Exact preflight was rerun after the repair commit and still stops at the same repository-wide Pint drift before later stages; no unrelated files were reformatted. Type generation, permission-map generation, focused Pint/PHPStan, typecheck, and focused receipt/Sidebar tests were run separately and pass.
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
- i18n deviation recorded from round 1: four unused legacy keys (`active`, `voided`, `noReceipts`, `noReceiptsDescription`) were not carried over because no `receiptSearch` consumer remains; EN/FR receipt keysets stay identical.
