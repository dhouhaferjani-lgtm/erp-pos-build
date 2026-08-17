# POS receipts reporting build — implementer report

- Base SHA: `7d85232cc54abd6a6b2135f476205ab434e71a66` (fresh `origin/dev` at dispatch)
- Branch: `codex/pos-receipts-2026-08-12`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/receipts-build`
- Final reviewed SHA: `80796c29a` (M2 round 4 reviewed `base_sha..HEAD` and returned ACCEPT)
- Delivered scope: waves 1+2. M2b is `BLOCKED` by the specified A0 owner gate; wave 3 was not dispatched.
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
- `54351b6a6` — Phase 1.1.18: Record third receipt review

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
- M1 review round 4: **ACCEPT**; register at `docs/handoff/reviews/receipts-build/M1-round4.md`. The reviewer independently re-ran the archived-terminal regression, the 11 changed backend classes (`50 tests, 248 assertions`), and the scoped frontend set (`48 tests`), and found no remaining M1 implementation blocker.
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
- OP-23 follow-up for OQ-10: the exact-gated fraud-settings page exposes Save and Reset to read-only accountants even though the backend correctly refuses both writes. This pre-existing read/write UI mismatch was widened to the accountant by the required re-gate; no unapproved UI behavior was changed in this lane.
- Merge integration: `dev` independently added `settings.fiscal.update` after this branch's base. The parent must regenerate `permissionsMap.generated.ts` after merging, then confirm it contains that admin grant plus the three accountant deltas (`deliveries.view`, `pos.view_receipts`, `pos.view_reports`). Taking either generated file wholesale would leave a stale hash or drop a grant.
- F-4: no unit label, event-version-5 preparation, or `apps/pos/**` change exists. Wave 2 will add only `quantity_decimals` as explicitly allowed.
- i18n deviation recorded from round 1: four unused legacy keys (`active`, `voided`, `noReceipts`, `noReceiptsDescription`) were not carried over because no `receiptSearch` consumer remains; EN/FR receipt keysets stay identical.

## Wave 2 / M2

### Commits

- `610a2d6f6` — Phase 1.2.1: Scope receipt detail and print reads
- `ccbeb1d83` — Phase 1.2.2: Add safe receipt reporting resources
- `cc70b3024` — Phase 1.2.3: Lock receipt reporting integrity
- `cebea3f16` — Phase 1.2.4: Publish receipt reporting contracts
- `6db7701de` — Phase 1.2.5: Build receipt detail and refund registers
- `99659b936` — Phase 1.2.6: Prove voucher receipt navigation
- `f9879ef7b` — Phase 1.2.7: Match receipt reporting acceptance fixtures
- `8749c490c` — Phase 1.2.8: Polish receipt reporting details
- `2f61d7b0d` — Phase 1.2.9: Stabilize policy alert rendering
- `6d8b84f0e` — Phase 1.2.10: Add receipt reporting browser flows
- `1a688283a` — Phase 1.2.11: Record receipt reporting evidence
- `be6cebe16` — Phase 1.2.12: Repair receipt reporting review findings
- `fe2035cb8` — Phase 1.2.13: Record first wave two review
- `e15d3e6a9` — Phase 1.2.14: Preserve legacy return document integrity
- `afb88618a` — Phase 1.2.15: Record second wave two review
- `59cfb57cb` — Phase 1.2.16: Complete receipt detail evidence
- `80796c29a` — Phase 1.2.17: Record third wave two review

### Spec-item status and evidence

- **S-4 — DONE.** `ReceiptDetailData.php:77-109` derives `quantity_decimals` only from the current product unit precision, falls back to 4 for a missing product, and serializes no unit label. `ReceiptDetailPage.tsx:68-106` renders the historical product-code/name snapshots, `formatQuantity` at that precision, TTC unit price, VAT, line net and returned quantity. The round-1 repair adds the decisive live-product fixture: a two-decimal unit plus a post-receipt SKU/name mutation still emits `quantity = 1.25`, `quantity_decimals = 2`, and the immutable line snapshot.
- **S-5 — DONE.** The detail root and every nested collection are explicit DTO allowlists (`ReceiptDetailData.php:20-67` and sibling `ReceiptDetail*Data` classes). `ReceiptController.php:571-626` scopes the root plus both lineage directions to company/location before serialization. `ReceiptResourceNoCanonicalBytesRecursiveTest` failed before the allowlist and now recursively rejects `canonical_bytes` and every forbidden model key at all depths. `ReceiptAggregateIntegrityTest` locks positive, negative and null aggregate fields plus VAT sums without recomputing line arithmetic; `ReceiptShowRefundLineageTest` locks bidirectional lineage and related-receipt scope suppression.
- **S-11 and BT-6/7/12/13/15 — DONE.** Direct show, stream and download reads apply the same allowed-location scope before lookup/audit (`ReceiptController.php:571-626,746-819`). Round-1 also caught the chain-verify selector: its same-company terminal lookup now applies the caller's effective location scope and returns 404 outside it, with an unrestricted-membership 200 regression. `ReceiptPdfPrintAuditTest` proves both PDF endpoints append `pos_receipt_prints` rows with copy 1/2, the authenticated user, receipt, and `PrintMethod::Pdf`; 403/404 paths append none.
- **S-12/BT-17 — DONE.** `RefundReportingEnricher.php:15-89` bulk-loads fiscal events once, reads each hydrated canonical event in memory, catches exactly `InvalidArgumentException|TypeError` per row, logs a warning and degrades that row to legacy/null fields. It enriches only refund-like rows. `RefundReportingFieldsTest` was red on the five absent fields; it now covers canonical v4, legacy, void, null payload, missing nested keys and scalar line items, all as HTTP 200 with the expected warning count.
- **Screen (b), FT-4/5/7 — DONE.** The exact-gated route is `routes/index.tsx:2944-2953`. `ReceiptDetailPage.tsx` renders header/summary, lines, VAT, all six payment fields, two-way dated lineage and collapsed fiscal provenance. Print and Download live only on detail and always open `ConfirmDialog`; render and cancel issue no PDF request, confirm issues exactly one audited request. The detail tests prove no eager PDF fetch, cancel/confirm cardinality, every payment field, and both lineage directions with dates.
- **Screen (c), FT-8/16 — DONE.** The exact-gated route is `routes/index.tsx:2933-2943`. `RefundReceiptListPage.tsx` sends one server-paginated REFUND+VOID request with no training axis, uses location-scoped option/list keys, and shows the three server-derived terminal capability states. Historical rows remain visible when current authoring is disabled; a truly empty dark state stays capability-only. Columns include the two receipt links, visibly distinguished canonical-vs-legacy reason provenance, cash destination, row-local magnitude in the row's own currency, and a policy-alert disclosure containing the recorded discount/fiscal-event context.
- **CL-3/CL-4 and FT-12/13 — DONE.** `LedgerHistoryTable.tsx:108` and `ProvenanceSection.tsx:28` link to `/pos/receipts/:id`. Their component tests use a `MemoryRouter`, click the links and assert destination rendering. Separately, `ReceiptPermissionParity.test.ts` reads the application route source and locks the exact detail route/component/gate; that source-parity assertion is the part that fails against the pre-wave routing table. The live end-to-end click remains environment-blocked by the unavailable API.
- **Routes/permissions — DONE.** All three receipt routes are sibling children of `/pos`, each exact-gated by `pos.view_receipts`, with the required NG-5 comment (`routes/index.tsx:2922-2953`). `ReceiptPermissionParity.test.ts` locks paths, components, gates and comments.
- **Wire contracts/i18n — DONE.** Spatie-generated declarations include the detail, lineage and refund-reporting DTOs in `packages/shared/types/generated.d.ts`; they were regenerated with array cache and never hand-edited. All new copy lives under matching EN/FR `receiptReporting` trees. AR remains the parallel lane; hand over `pos:receiptReporting.{tabs.*,reprint.*,detail.*,refunds.*}`.

### Addendum A and safety evidence

- Every new list/detail/refund money render passes the row/detail receipt's explicit currency. `ReceiptShowResourceTest` uses TND values and now also locks `cash_rounding_denomination` to the TND three-decimal scale; the corresponding detail fixture carries TND explicitly.
- `rg -n "SUM\\s*\\(|\\.sum\\s*\\(|reduce\\s*\\(" $(git diff --name-only 59b02a34c..HEAD)` returned no matches. Wave 2 adds no footer total, cross-receipt aggregate, VAT roll-up or export total.
- No `parseFloat`/`Number(...)` money or quantity conversion, unit label, `pos_receipt_lines.unit` read, event-version-5 preparation, or `apps/pos/**` change was added.
- M1's composite compliance key/OP-23 route evidence remains unchanged; no lane-separation grant was added in wave 2.

### Verification

- M2 adversarial review round 1: **CHANGES-REQUIRED**, register at `docs/handoff/reviews/receipts-build/M2-round1.md`. The red repair run observed verify-chain HTTP 200 outside scope, a legacy detail `SALE`/signed total mismatch, invisible reason provenance and hidden historical rows. `be6cebe16` closes all five blocking findings and five low-risk P3s. Its focused green rerun passes 13 backend tests/80 assertions and 11 frontend tests; touched PHPStan, Pint, changed-file ESLint, TypeScript, i18n parity, quantity audit and design-system delta checks pass.
- M2 adversarial review round 2: **CHANGES-REQUIRED**, register at `docs/handoff/reviews/receipts-build/M2-round2.md`. Its P1 exposed the half-projected legacy return shape. `e15d3e6a9` moves the exact pre-fiscal predicate fully to the server and sign-inverts the entire signed document coherently, including the reversal-discount sign, while preserving the aggregate identity. It also guards skipped lineage shapes, adds actor/terminal print assertions, removes the browser's blanket sign strip, and removes the literal money scale. Focused green: 26 backend tests/221 assertions and 12 frontend tests.
- M2 adversarial review round 3: **CHANGES-REQUIRED**, register at `docs/handoff/reviews/receipts-build/M2-round3.md`. `59cfb57cb` closes its payment-detail P2, adds dates to both lineage directions, removes live `rounding_method` dependence, removes unused eager loads, and keeps quantity formatting in `QuantityScale`. Focused green remains 26 backend tests/221 assertions and 12 frontend tests.
- M2 adversarial review round 4: **ACCEPT**, register at `docs/handoff/reviews/receipts-build/M2-round4.md`. The reviewer re-verified every round-3 closure against code, applied all five milestone lenses, recorded an empty blocking set, and independently confirmed that Lane A0 has not landed.

- Focused wave-2 backend slice: PASS (`14 tests, 158 assertions`) across show allowlist, aggregate integrity, lineage, PDF audit, recursive forbidden keys, refund reporting and location authorization. Touched-file Pint and level-8 PHPStan pass.
- Focused wave-2 frontend slice: PASS (`51 tests`) across receipt API/list/detail/refunds, route parity and both voucher consumers. Changed-file ESLint and `pnpm typecheck` pass.
- React Doctor changed-scope scan improved from `92/100` with one index-key warning to `98/100`, no issues, after replacing the alert list's array-index key with immutable fiscal provenance.
- Exact preflight invocation: **BLOCKED before scoped tests by repository-wide Pint drift** in unrelated files (including the previously recorded `tests/Feature/POS/ZReportListTest.php`); no unrelated file was reformatted.
- Exact scoped Vitest command: **PARTIAL** — `560 passed / 563`; the same three out-of-lane baseline failures remain in `reportPages.tenantScope.test.tsx`, `useAnalytics.tenantScope.test.tsx`, and `POSPage.test.tsx`. All wave-2 receipt/voucher targets pass.
- Exact scoped PHPUnit paths completed all 1,508 tests: **PARTIAL** — 1,487 completed without error, 114 skipped and 2 incomplete; the 21 errors are outside this lane (13 stale `FraudSettingsDTO` constructor calls in `CashCountValidationServiceTest`, 8 duplicate-shift fixtures in `ZReportHashServiceTest`). The focused M2 slice above is green.
- `pnpm lint && pnpm typecheck`: PASS. ESLint reports 6,520 repository warnings and zero errors; TanStack keys report 0 new, design-system audit 737 acknowledged/0 new/0 stale, quantity audit 0, custom rule tests pass, and TypeScript passes.
- Frozen four-file Playwright invocation: **ENVIRONMENT BLOCKED**. All four Chromium specs compile/list, Vite starts, but every login remains on `/login` because its `/api/v1` proxy receives `ECONNREFUSED` from the unavailable API at `127.0.0.1:8010`. This is not reported green.
- Required wave-2 screenshots (detail six sections, three refund capability states, reprint dialog): **BLOCKED by the same unavailable live API**. The specs capture populated refunds and the reprint dialog when the live prerequisites exist.

### Decisions, deviations and out-of-scope findings

- The only presentation judgment beyond frozen copy is rendering the already-defined policy alert as a native `<details>` disclosure. Known alert fields are type-guarded and translated; unknown alert shapes render a generic translated label without unsafe property access.
- Live E2E fixtures are required explicitly rather than skipped: the specs fail with a named prerequisite if the stack has no SALE, acknowledged-terminal REFUND/VOID, or refund/exchange voucher provenance. No mocked-auth substitute was introduced.
- Verification deviations are explicit above: global Pint and scoped legacy-suite baselines are not called green, and E2E/screenshots are environment-blocked.
- S-8/totals remains owner-blocked and was not implemented.
- **Declared legacy reporting projection:** pre-fiscal returns (`receipt_type=return`, no fiscal event, stored `invoice_type_code=SALE`) are presented as REFUND magnitudes at the server boundary on both list and detail. Detail sign-inverts every signed component together, including discount as a negative reversal adjustment, so the emitted receipt and VAT identities remain internally coherent. Current fiscal REFUND/VOID rows are not rewritten by this rule.
- Terminal-audit follow-up: `returned_quantity` on a location-scoped original is derived from the visible, scoped lineage collection. Current writers keep original and return at the same location, but a manually-created cross-location child could be hidden while the tally reads zero; changing that requires a separate scoped aggregate contract.
- Terminal-audit follow-up: a normalized pre-fiscal return renders its discount as a negative reversal adjustment without a dedicated explanatory caption.
- Terminal-audit follow-up: the normalized web detail shows a pre-fiscal return magnitude while its immutable fiscal PDF duplicate retains the stored negative sign; the two surfaces need explanatory follow-up, not a mutation of sealed output.
- Terminal-audit follow-up: the detail API rounds the wire quantity to the current unit `decimal_places`; it does not preserve raw sealed scale-4 digits alongside `quantity_decimals`.
- Terminal-audit follow-up: the VAT table keys rows by `tax_rate`, although the database does not enforce uniqueness for `(receipt_id, tax_rate)`; current projectors aggregate per rate, so this is defensive hardening.

## Wave 2b / M2b owner STOP

- **Screen (d) and FT-9 — BLOCKED.** The mandatory M2-close inspection found that `VerifyPosChainCommand.php:304,341` still applies `whereNull('fiscal_event_id')`, excluding fiscal-era projections from receipt verification. No verifier-path predicate for canonical `chain_context` and no `pos_receipts.fiscal_hash` to `fiscal_events.current_hash` mirror check exists on this branch. That is the pre-A0 shape, so Lane A0 has not landed.
- Per brief §5/F-2 and the progress-file owner gate, no chain-verification panel, copy, i18n keys, or tests were added speculatively. M2b is `blocked_owner`, waves 1+2 are the delivered scope, and this executor run ends before M3.
- This STOP is the specified conditional outcome, not an implementation failure. The parent session owns the terminal full-branch audit and integration after A0 coordination.
