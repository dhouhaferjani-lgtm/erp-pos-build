# HANDBACK — Delivery-Note Consolidation Billing Build

## Header

- Base SHA: `60df88a01b52828665caf33809486bdf0a699bbc` (owner-curated re-pin of 2026-08-19).
- Branch: `codex/dn-consolidation-2026-08-12`.
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation`.
- Pre-re-pin blocker record: `codex/dn-consolidation-2026-08-12-pre-repin`.
- Current M1 implementation SHA: `c517635cb` (bridge round 3 accepted at `95ac2f22a`).
- Current M2 implementation SHA: `483457a41` (awaiting the M2 bridge gate).
- Milestone being handed back: M2.
- No push, merge, or deployment was performed.

M1 commit list:

- `b842b577a Phase 2.1.1: Add delivery note billing projections and filters`
- `f0e3a3da2 Phase 2.1.2: Fix delivery note billing projection review findings`
- `2640b4e4a Phase 2.1.3: Align delivery note permissions and module gates`
- `6f71d2631 Phase 2.1.4: Fix delivery note module gate review findings`
- `799bbcaf7 Phase 2.1.5: Add delivery note billing marker backfill`
- `5be0a95a4 Phase 2.1.6: Fix delivery note billing marker rerun audit`
- `a9fa9c73d Phase 2.1.7: Enforce atomic delivery note billing claims`
- `20f8864b0 Phase 2.1.8: Harden delivery note claim set issuance`
- `5765c7d44 Phase 2.1.9: Integrate delivery note billing claims`
- `9588de5c7 Phase 2.1.10: Fix delivery note claim integration review finding`
- `129bdcb59 Phase 2.1.11: Refactor sales order billing onto atomic delivery note claims`
- `7288b5b45 Phase 2.1.12: Fix sales order billing claim review findings`
- `290b4518a Phase 2.1.13: Prove delivery note billing claims under concurrency`
- `1b24b5fc4 Phase 2.1.14: Fix delivery note concurrency review findings`
- `070da89bb Phase 2.1.15: Make billing retry attribution attempt-local`
- `f06319db6 Phase 2.1.16: Fix delivery note integration preflight regressions`
- `29dbbc073 Phase 2.1.17: Restore delivery note raw SQL billing guard`
- `49fb07dd6 Phase 2.0.2: Record amended DN consolidation re-pin`
- `1437c34d7 Phase 2.1.18: Keep billing fixture out of runtime autoload`
- `be7deae8d Phase 2.1.19: Align M1 UI and route manifest gates`
- `e81ac612e Phase 2.1.20: Record amended M1 differential preflight`
- `e0eb2a2b8 Phase 2.1.21: Chunk delivery note marker backfill`
- `34101009f Phase 2.1.22: Preserve foreign-currency delivery note rows`
- `97484c442 Phase 2.1.23: Preserve DN retry exhaustion failures`
- `055b6bd45 Phase 2.1.24: Attribute consolidation billing refusals`
- `37c4b3a00 Phase 2.1.25: Record M1 bridge fix round`
- `c517635cb Phase 2.1.26: Suppress attributed refusal toast and retry remainder`
- `95ac2f22a Phase 2.1.27: Record M1 bridge round two`
- `8e4d21b58 Phase 2.1.28: Accept M1 bridge gate`

M2 commit list:

- `49f7aa451 Phase 2.2.1: Add partner delivery-note billing views`
- `f74282ddd Phase 2.2.2: Show delivery-note billing attribution`
- `ff4648ef6 Phase 2.2.3: Link lane-separation report`
- `483457a41 Phase 2.2.4: Centralize delivery-note billing links`

## Owner-amended gate

The 2026-08-19 ruling replaces whole-repository-green with both of these conditions for every
remaining milestone:

1. Pint, PHPStan, and tests are green for touched files.
2. The whole-repository failure set is byte-identical to or smaller than pinned base `60df88a01`.

Any new failure still blocks. The base and branch were therefore measured independently with the
same commands; inherited failures are recorded below and were not repaired by this lane.

## M0 — amended re-pin

**Status: DONE**

- Renamed the retired branch to `codex/dn-consolidation-2026-08-12-pre-repin` and recreated the
  dedicated worktree and active branch from the exact owner pin. No fetch-and-repin was performed.
- `git merge-base --is-ancestor 60df88a01b52828665caf33809486bdf0a699bbc HEAD` passed.
- Confirmed the accountant grant on the pin contains `deliveries.view`, `pos.view_receipts`, and
  `pos.view_reports`. The generated permission map contains the receipts-wave grants.
- This lane did not edit `RolesAndPermissionsSeeder.php` or `permissionsMap.generated.ts`.
- Read 3C merge `1e8c0fa03` before resolving the invoice-posting integration.

## M1 — the three P0s

**Status: PASSED — bridge round 3 ACCEPT.**

### Replay and integration

The reviewed M1 series was replayed from the prior curated branch as `Phase 2.1.1` through
`Phase 2.1.17`. `git range-diff` reports 15 of 17 commits patch-identical. The two intentional
integration differences preserve work already present on the new pin:

- M1.9 retains the 3C `InventoryGlPostingBuffer` constructor/root-flush seams while adding the DN
  billing-claim service. The claim call remains additive to the outer posting root.
- M1.13 retains the receipts-expanded CI test filter while adding the named DN concurrency test.

A clean Composer install then exposed a test fixture declaring the production billing service's
FQCN and taking precedence in the optimized autoloader. A RED-first autoload-integrity regression
was added; the colliding fixture was removed and its rule test now analyzes the real production
service. This is commit `1437c34d7` (`Phase 2.1.18`).

The differential sweep then found one branch-owned design-system violation in the M1 recovery
picker and one unrecorded route-manifest delta. Commit `be7deae8d` (`Phase 2.1.19`) replaces the raw
checkbox with the repository atom and records only this lane's `Sales` / `invoices.create` route
metadata. The remaining route-manifest drift patch is SHA-256-identical to the pin's drift patch.

Bridge round 1 reviewed `60df88a01..e81ac612e` and returned `CHANGES-REQUIRED`. Fix round 1 is:

- `e0eb2a2b8`: bounded migration backfill (`chunkById`) with a RED multi-chunk regression.
- `34101009f`: foreign-currency rows remain visible while aggregates stay company-currency-only.
- `97484c442`: DN commit exhaustion becomes an attributed refusal only with a durable winner.
- `055b6bd45`: consolidation-lane attributed refusal and selective human recovery.
- This expanded §7 evidence, lock, OI-8, OI-14, decision, finding, deviation, and deploy report.

Bridge round 2 reviewed `60df88a01..37c4b3a00` and confirmed every round-1 blocker closed. It
returned `CHANGES-REQUIRED` because the consolidation hook still emitted a transient toast before
the attributed inline refusal. Fix round 2 is `c517635cb`: both the component and hook now use the
same strict refusal parser, the hook suppresses only the attributed 422 toast, generic failures
still toast, and the operator's explicit remove-and-retry click resubmits only the remaining IDs.

Bridge round 3 reviewed `60df88a01..95ac2f22a`, independently reran the focused frontend evidence,
and returned `ACCEPT`. It confirmed the round-2 P2 and recovery gap closed. The four remaining P3
notes are recorded below; none weakens the M1 claim-before-invoice, exact-N, gating, migration, or
ratified OI-8 invariants.

### Implementation state

- P0-1: typed billing projections, shared uninvoiced scope, real server-side filtering, exhaustible
  offset pagination, aggregates, and the self-guarding partial index.
- P0-2: independent Sales-module and permission gates on the list/consolidation surfaces, with
  per-layer regressions. The reserved seeder and permission map remain untouched.
- P0-3: migration-only `legacy_unknown` backfill, one public atomic claim entry point, exact-N
  marker and projection finalisation, bounded retry, durable winner attribution, and both DN and SO
  converter integrations.
- Thin `GET /delivery-notes/uninvoiced`: static-route ordering, UUID constraint, authorization, and
  Sales-module gate.
- C5/C8 and OI-8 conditions 1–4: tenant/company/type-scoped lookups, batch-atomic loss, persistent
  attributed refusal, no-artifact copy, and explicit human-confirmed remainder flow.

### Per-spec-item acceptance register

- **P0-1(a) — DONE.** `DocumentData.php:62-65,256-259` exposes the four billing projections and
  `DeliveryNoteBillingState` resolves them from the marker/payload state. RED-first evidence:
  `DeliveryNoteBillingProjectionTest.php` initially received no `invoiced_at`, `invoice_id`,
  `invoice_number`, or `invoiced_via` fields for an invoiced DN.
- **P0-1(b) — DONE.** The shared predicates are `Document.php:645-664`; the list applies them in
  `DeliveryNoteController.php:171-183`. RED-first evidence: the initial filter test returned both
  invoiced and uninvoiced fixtures. Bridge round 1 then proved the row query wrongly excluded a EUR
  DN; `DeliveryNoteBillingProjectionTest.php:323-342` failed with `meta.total = 1`, expected `2`.
  `DeliveryNoteController.php:105-116,193-207` now returns both currencies while limiting only the
  aggregate to company currency.
- **P0-1(c) — DONE.** `DeliveryNoteController.php:118-143` provides exhaustible offset pagination
  whenever `page` is present, while preserving cursor mode for existing callers. The RED test could
  not reach the fixture on page 2 and had no `last_page` before this branch.
- **P0-1(d) — DONE.** `DeliveryNoteController.php:114-116,193-207` returns page-invariant
  `aggregates.count`, decimal-string `total`, and `currency`; `DeliveryNoteBillingProjectionTest.php`
  proves opt-in behavior, page invariance, and company-currency-only money totals.
- **P0-1 index — DONE.** `2026_08_18_000001_add_delivery_note_uninvoiced_index.php:14-25` is
  self-guarding, concurrent, partial, and outside the migration transaction. Its source-contract
  regression failed before the migration existed.
- **P0-2 — DONE.** The static GET and existing POST have independent `module:Sales` and permission
  middleware at `routes.php:274-294`; the frontend route has independent `ModuleGuard` and
  `RequirePermission` at `routes/index.tsx:1248-1254`. RED-first evidence: the four gate regressions
  in `DeliveryNoteConsolidationAccessControlTest.php`, `DeliveryNoteConsolidation.gates.test.tsx`, and
  `DeliveryNoteConsolidationRoute.gates.test.tsx` initially observed an allowed response/render with
  Sales disabled. The accountant seeder and generated permission map were not edited.
- **P0-3 Layer 0 — DONE.** `SalesOrderToInvoiceConverter.php:165-307` locks the SO header before
  scenario detection, keeps auto-created DN effects inside the outer retry transaction, locks the
  complete DN set, and enters invoice creation only through the claim closure. The real two-process
  tests initially exposed the sequence/DN inversion and rollback artefacts.
- **P0-3 Layer 1 — DONE.** `DeliveryNoteBillingConcurrencyRetrier.php:34-94` owns one fresh outer
  transaction per bounded attempt; `DeliveryNoteToInvoiceConverter.php:371-386` and
  `SalesOrderToInvoiceConverter.php:636-650` lock tenant/company/type-scoped DNs in ascending ID
  order. The transaction/ordering tests failed before the outer boundary and sorted lock set.
- **P0-3 Layer 2 — DONE.** `DeliveryNoteBillingClaimService.php:40-146` exposes only `claim()`;
  `reserve()`/`finalise()` are protected and both marker and payload finalisation require exactly N
  rows. RED-first service tests observed public half-steps, partial marker state, and a payload count
  mismatch before the guard. Bridge round 1 additionally reproduced a phantom 422 on third-attempt
  DN commit exhaustion; the new PG regression expected 500 but received 422. The durable-winner
  check in `DeliveryNoteToInvoiceConverter.php:130-222` now preserves the infrastructure exception
  unless marker, invoice, and payload agree.
- **P0-3 Layer 3 — DONE.** `2026_08_18_000002_create_delivery_note_billing_marks_table.php` creates
  the durable marker and writes `legacy_unknown` only in this migration. The survey test originally
  had no marker rows or counters. Bridge round 1 then showed the migration read the whole history in
  one query; `DeliveryNoteBillingMarkerMigrationTest.php:204-240` failed with one document read,
  expected at least two. The migration now uses bounded `chunkById(100)` and retains per-tenant
  counters.
- **P0-3 Layer 4 — DONE.** `DocumentConversionController.php:303-349,460-570` returns every losing
  row with its durable taker invoice and lane, and refuses the atomic batch. The original test
  returned a batch-level string and omitted the second losing row.
- **P0-3 Layer 5 — DONE.** Conversion events remain inside the successful outer transaction and
  after exact-N finalisation (`DeliveryNoteToInvoiceConverter.php:347-360`); production-entry tests
  prove a failed claim stores neither event nor audit record.
- **Thin GET endpoint — DONE.** `routes.php:274-280` declares `/delivery-notes/uninvoiced` before the
  UUID-constrained parameter route with `module:Sales` and `deliveries.view`. Its RED route test was
  swallowed by `{deliveryNote}` before the ordering/constraint fix.
- **C5 — DONE.** Direct converter loads are tenant/company/type scoped in
  `DeliveryNoteToInvoiceConverter.php:371-386`; foreign IDs are not visible in the active company.
- **C8 — DEFERRED.** The normative spec names C8 only in its milestone table and defines no C8
  behavior or acceptance criterion. No independent implementation claim is made; this ambiguity is
  returned to the owner. The branch does close the attributed all-or-nothing losing path that the
  surrounding milestone text appears to intend.

### Lock inventory as built

1. Both billing lanes enter `DeliveryNoteBillingConcurrencyRetrier`, which owns the level-1
   transaction and permits only `40P01`/`40001`, initial attempt plus two bounded retries.
2. SO conversion first locks the tenant/company/type-scoped SO header
   (`SalesOrderToInvoiceConverter.php:171-178`). In the auto-create branch, the shared factory then
   takes `document_sequences[delivery_note]`, creates the draft DN and lines, stamps source-line
   `quantity_delivered`, and appends the DN ID to the locked source header—all within that outer
   transaction.
3. The SO lane locks all applicable existing DNs in ascending ID order
   (`SalesOrderToInvoiceConverter.php:636-650`). Consolidation enters the chain at the same sorted DN
   lock (`DeliveryNoteToInvoiceConverter.php:371-386`).
4. Every required DN claim is reserved before either lane enters the callback that creates the
   invoice. Invoice creation then takes `document_sequences[invoice]` through
   `DocumentNumberingService.php:49-65`.
5. The SO lane's later prepayment path may lock the partner at
   `GeneralLedgerService.php:1662-1690`, followed by GL advisory sequencing; no holder of those locks
   has a reverse edge to SO/DN/numbering locks. Guided invoice-to-DN creates a fresh DN and does not
   contend for an existing claimable DN. DN confirmation takes no invoice-sequence lock.

The acquisition chain is acyclic: SO header → optional DN sequence/source lines → sorted DN claims
→ invoice sequence → optional partner → GL sequence. Consolidation begins at sorted DN claims. **No
writer reaches invoice creation before all required existing-DN claims succeed.**

### OI-8 UI conditions 1–4

1. **Persistent inline refusal — DONE.** The SO refusal is stored/rendered at
   `SalesOrderDetailPage.tsx:152,434-490`; the consolidation refusal is stored/rendered at
   `DeliveryNoteConsolidation.tsx:60,260-340`. Tests rerender or interact after failure and keep the
   `role=alert` region visible. `useDeliveryNotes.ts:155-159` suppresses only a parsed attributed
   refusal while retaining the generic error toast; the hook regression asserts both branches.
2. **Per-DN taker attribution — DONE.** Both regions show DN number, taking invoice/date, human lane,
   and invoice link. Evidence: `SalesOrderDetailPage.tenantScope.test.tsx:448-486` and
   `DeliveryNoteConsolidation.billingRefusal.test.tsx:118-146`.
3. **No-artifact sentence — DONE.** Exact en/fr strings are asserted in
   `SalesOrderDetailPage.tenantScope.test.tsx:515-517` and
   `DeliveryNoteConsolidation.billingRefusal.test.tsx:169-178`.
4. **Human recovery — DONE.** SO offers `Open INV-XXXX` and the explicit `Invoice remaining lines…`
   confirmation only when complete `billed_order_line_ids` provenance exists
   (`SalesOrderDetailPage.tsx:450-490,707-739`). Consolidation offers `Remove these N and retry`,
   whose operator click removes only server-named IDs and explicitly resubmits every remaining ID
   (`DeliveryNoteConsolidation.tsx:174-185,328-340`). The regression proves the second mutation
   contains only the surviving selection and reaches the success callback.

No browser screenshot is claimed for M1: the local workspace has no authenticated seeded browser
fixture for these race-only 422 states. The required behavior is covered at the rendered-component
level; UI E2E and visual evidence remain mandatory before the final UI merge handoff.

#### Research 17 proposals 5–7 — UNRATIFIED

- **5:** only the independently specified C9 invalidation will ship in M2 (spec §3.4); nothing in M1
  is attributed to this proposal.
- **6:** no durable losing-claim trace was designed or built; its mechanism remains unfrozen.
- **7:** no client automatic retry exists. Both recovery paths require a human action; the
  consolidation button click itself is the explicit operator resubmission required by condition 4.

### OI-14 — vanishing artefacts

`SalesOrderBillingClaimTest.php:184-253` drives the production failed auto-created-DN path. It proves
there is no draft DN, no consumed DN or invoice sequence number, no new `delivery_note_ids` payload
entry, no `quantity_delivered` stamp, no stored conversion/audit event, and no false fully-delivered
status. Re-entry through the real factory succeeds and leaves agreeing marker/payload state.

### Fresh focused verification

- Standalone guided delivery + consolidation + invoice-confirmation: 38 tests, 294 assertions.
- 3C inventory GL composite-root regression under PostgreSQL config: 2 tests, 19 assertions.
- PostgreSQL concurrency suite after bridge remediation: 12 tests, 148 assertions.
- Billing projection suite: 8 tests, 68 assertions; marker migration: 5 tests, 47 assertions.
- Billing-write PHPStan rule: 6 tests, 8 assertions.
- All PHP files changed from the pin: Pint green and PHPStan level 8 green.
- Changed frontend TypeScript: typecheck and scoped ESLint green.
- Design-system audit: 734 acknowledged, 0 new, 0 stale; focused consolidation refusal/gate UI:
  7 tests green; SO recovery UI: 9 tests green.
- Bridge round-3 independent frontend rerun: 5 files/12 tests green; SO recovery UI: 9 tests green.
- Exact scoped Vitest after fix round: 86 files/683 tests passed; only the two inherited finance
  tests failed.
- React Doctor against explicit base `60df88a01`: 89/100, 11 changed files scanned, no issues.
- `git diff --check`: green.

### Amended whole-repository comparison

**Status: PASSED at `be7deae8d`.** Independent captures used the exact same commands against the pin
and branch. No branch failure is new or larger:

- Pint, web typecheck, ESLint, TanStack-key audit, quantity audit, POS ESLint-rule tests: green on
  both. The design-system audit is also green on both after the scoped checkbox fix.
- PHPStan: the same two `CopiesDocumentData.php:309-310`
  `precision.hardcodedBcmathScale` findings (C-3/NG-4) on both.
- Backend path sweep: the same two `InventoryGlCompositeRootTest` fixture failures on both under
  default SQLite (`tenants` table absent). The tests pass with `phpunit-pgsql.xml`. The owner-listed
  `CompleteSalesCycleWithReturnTest` red did not reproduce on either side and was not changed.
- Scoped Vitest: branch has only the two owner-listed finance failures. The base capture had three;
  a focused base rerun confirms the two finance failures and the candidate partner test is green.
  The branch set is therefore identical on the named inherited reds and smaller overall.
- Factory route manifest: after recording only M1's route gate and permission, normalized base and
  branch drift patches have the same SHA-256
  `92a1554a51c2090f0550bb41b08ee723d3d9a1981eba3fd837338f650dc78f70`.
- SaleReceipt chokepoint audit: identical inherited `InventoryCountingController.php:135` finding;
  validator passes with six entries on both.

The pinned base also reproduces an uncommitted generated-types mismatch introduced by the merged 3C
lane: `typescript:transform` adds two `SystemAccountPurpose` cases and `MovementGlKind` to
`packages/shared/types/generated.d.ts`, and the branch reproduces that same residual byte-for-byte.
Separately, M1's initial generated-artifact commit includes the four billing DTO fields and
`DeliveryNoteBillingLane` together with three additive current-source enum outputs:
`DeliveryComplianceCode`, `PostingContext`, and `PreDeliveryInvoicingPolicy`. That shared-file lane
overlap is disclosed for owner integration; it is additive and typecheck remains green.

## M2 — View A, A2, and View C

**Status: BRIDGE ROUND 1 CHANGES-REQUIRED — fix round 1 in progress.**

Bridge round 1 reviewed `60df88a01..668a4bc47` through frontend-conventions, treasury, and general
lenses. It confirmed the module/permission riders, generated DTO flow, C9 tenant scoping, sidebar
permission-route parity, translations, precision guard, and the 66 focused tests. It found four
blocking gaps: the shipped list request omitted `status=confirmed`; off-page refused selections
were absent from the persistent attribution region; row totals used company rather than document
currency; and the handback lacked explicit RED-first/deviation evidence. Fix round 1 addresses
those blockers and the bounded P3 presentation issues in the same surface.

### Delivered surfaces and binding riders

- The customer partner detail page has a URL-driven `delivery-notes` tab. Its visibility requires
  customer capability, `hasModule('Sales')`, and `deliveries.view`. The module-off assertion is
  independent from the permission assertions. The create-invoice action independently requires
  `hasModule('Sales')` and `invoices.create`.
- The tab defaults to un-invoiced notes and supports Un-invoiced / Invoiced / All server filters,
  offset pagination, typed row selection, invoiced-row disabling, billing-lane badges, and invoice
  links. `legacy_unknown` uses the neutral badge in both English and French.
- A named 422 is persistent and row-visible. A response naming exactly two documents marks exactly
  those two rows, preserves the remaining selection, and exposes the explicit operator action that
  removes the refused rows and resubmits only the remainder. There is no automatic retry.
- The tab type is a `Pick` of generated `App.Modules.Document.Application.DTOs.DocumentData`;
  `deliveryNotes.ts` contains no hand-written `DeliveryNote` object mirror.
- The partner balance card renders one bounded un-billed line directly below total receivable.
  Both it and the tab consume the same aggregate query. `aggregates.count` and decimal-string
  `aggregates.total` are rendered through `useCurrency().format`; neither surface uses
  `parseFloat` or `Number`.
- Delivery-note detail and partner rows show `invoiced_via` plus a centralized entity link to the
  taking invoice. The partner table's delivery-note and post-success invoice navigation also use
  `entityRoutes.document`.
- Consolidation success invalidates the tenant/company-scoped delivery-note list/detail,
  document/invoice, partner balance, and to-bill namespaces required by C9.
- View C adds only the `/finance/lane-separation` sidebar entry. It shares the route's exact
  `reports.financial` permission and leaves the accounting-and-reports permission/route mapping
  one-to-one. No seeder or generated permissions-map file was edited.

### M2 verification evidence

- Focused M2 Vitest: 6 files, 66 tests passed. This includes five independent partner tab/action
  gate tests, the exact-two-row 422 test, aggregate-format parity, generated-type source guard,
  C9 tenant scoping, detail attribution, and the 45-test sidebar suite.
- Exact §6.3 path sweep: 89 files / 701 tests passed. Only the two owner-ledgered finance tests
  failed (`src/features/finance/api.test.ts` and
  `src/features/finance/hooks/__tests__/tenantScope.test.tsx`); no M2 test failed.
- Exact backend path sweep at M2 HEAD: 1,064 tests passed, 32 skipped. Only the same two
  default-SQLite `InventoryGlCompositeRootTest` fixtures failed (`tenants` table absent), matching
  the pinned-base capture. M2 contains no backend change.
- The exact preflight invocation was attempted and stopped at the same two locked
  `CopiesDocumentData.php:309-310` PHPStan findings as the pin. The remaining stages were then run
  independently: Pint green; typecheck green; whole-web ESLint 0 errors; TanStack key audit 0 new;
  design-system audit 734 acknowledged / 0 new / 0 stale; quantity audit 0; POS ESLint-rule tests
  green; fiscal parity 29/29 green.
- The factory route-manifest drift and SaleReceipt chokepoint outputs are unchanged from the M1
  differential capture: normalized route patch SHA-256
  `92a1554a51c2090f0550bb41b08ee723d3d9a1981eba3fd837338f650dc78f70`, and the single inherited
  `InventoryCountingController.php:135` chokepoint finding with its six-entry validator passing.
- React Doctor against explicit base `60df88a01`: 88/100, 21 changed files scanned, no issues.
  Scoped ESLint on the M2 files has zero errors, and the two initially exposed hardcoded entity
  route warnings were closed in `483457a41`.
- Precision/type guards: no `parseFloat`/`Number` in the new aggregate surface; no hand-written
  `DeliveryNote` object type; `git diff --check` and the base ancestry check pass.
- UI E2E was attempted with `pnpm --filter @autoerp/web test:e2e`. The 465-test harness was stopped
  after the Vite proxy repeatedly failed to reach the API (`ECONNREFUSED`) and unrelated baseline
  tests began failing (for example the company-switcher strict locator). Playwright captured
  `apps/web/test-results/company-Company-Switcher-s-d3c63-down-when-clicking-selector-chromium/test-failed-1.png`.
  This is an environment/baseline blocker, not a green E2E claim.

### M2 amended differential verdict

**PASSED at `483457a41`.** All M2-touched files are green. Across the exact repository scopes, the
failure set is byte-identical to the already captured `60df88a01` baseline: two locked PHPStan
precision findings, two default-SQLite composite-root fixture failures, two finance Vitest reds,
the normalized route-manifest patch, the SaleReceipt chokepoint, and the generated-types residual.
M2 introduces no new failure and does not modify any inherited-failure owner surface.

## Standing findings and deploy obligations

- **F-1 resolved:** all three accountant grants and the merged frontend map are present at the pin.
- **F-3:** the existing DN list/detail route retains the owner-ruled `moduleKey="inventory"`
  residual. This lane did not change it.
- **OI-9:** `legacy_unknown` is a migration-only historical value. M2 owns its neutral en/fr badge.
- **OI-12:** staging legacy-survey counts remain parent-owned; promotion must capture the migration
  log and must not silently reconcile dirty fiscal-adjacent data.
- **OI-13:** the live-tenant Sales-module assignment remains a parent promotion gate.
- **D-6:** M1 migrations, merged grants, reseed, and permission-cache reset must precede dependent
  frontend promotion.
- Research 17 conditions 5–7 remain proposed and unratified. Only independently specified C9 is in
  scope; no durable losing-claim trace or automatic client retry may be added.

## Decisions, deviations, and discovered out-of-scope findings

### Decisions not specified by the spec

- Retry exhaustion is translated to an already-invoiced domain refusal only after a durable,
  tenant/company-scoped marker + invoice + payload agreement is re-read after unwind. With no such
  winner, the original infrastructure exception is preserved.
- Consolidation recovery removes exactly the server-attributed DNs. The operator's explicit
  `Remove these N and retry` click resubmits the remaining set; no retry occurs without that action.

### Deviations

- The owner-curated `60df88a01` re-pin supersedes the brief's fresh `origin/dev` instruction.
- The owner-amended differential preflight supersedes whole-repository-green. The inherited set and
  exact comparisons are recorded above.
- `copyOrderLinesWithProvenance()` now persists `source_line_id` on SO→Invoice lines so the server can
  safely produce `billed_order_line_ids` for OI-8 condition 4. This is an intentional, disclosed NG-2
  data-shape expansion; downstream discount-strip event provenance improves rather than breaks.

### Discovered findings not in scope

- `SalesOrderDetailPage.tsx:70-123` retains a second billing-refusal parser with additional SO-only
  provenance fields. M4 retires the consolidation screen, but until then a server-contract change
  must be kept in sync across both parsers.
- `packages/shared/types/generated.d.ts` includes the three additive non-M1 enum outputs named above
  in the same generated commit as the required DN billing fields. The separately inherited 3C enum
  drift remains uncommitted and byte-identical to the pin.
- `DeliveryNoteConsolidation.tsx:180-190` resubmits a subset without re-running
  `selectionValidation` and the recovery button has no pending guard. Subset partner/currency
  validity is stable and duplicate submissions lose the atomic claim, so this is ergonomic rather
  than a fiscal-integrity issue.
- Non-race consolidation refusals such as `PARTIAL_DELIVERY_NOTE_SELECTION_INCOMPLETE` fall back to
  the generic untranslated error path. OI-8 condition 1 governs the attributed race refusal, which
  is correctly inline and translated.
- `DocumentData.php:187-193`: resolving `invoice_number` performs a `Document::find()` per invoiced
  document and may become an N+1 on M2's invoiced tab.
- `DeliveryNoteBillingConcurrencyRetrier.php:83-92`: a future nested caller could let the PDO cleanup
  roll back an outer transaction; no production nested caller exists today.
- `DeliveryNoteBillingConcurrencyRetrier.php:42`: `55P03` lock timeout is not in the bounded retry
  set and currently reaches the generic 500 path.
- `DeliveryNoteConsolidation.tsx:219`: inherited `parseFloat` money formatting remains in the
  retiring screen; this fix round did not widen into M4 retirement work.
- `SalesOrderDetailPage.tsx:456`: the taking invoice date is rendered as raw `Y-m-d`.
- `DeliveryNoteBillingClaimService.php:120-140`: hand-edited payload state with `invoice_id` but no
  `invoiced_at` becomes an exact-N finalisation refusal; no writer creates that shape.
- `2026_08_18_000002_create_delivery_note_billing_marks_table.php`: a malformed non-null legacy
  `invoiced_at` can still fail the cast; the named survey contract covers invoice-ID dirt, not this
  additional case.
- Base and branch share unrelated factory-route-manifest, SaleReceipt chokepoint, generated-type,
  PHPStan precision, default-SQLite fixture, and finance-test residuals recorded above.

## Deploy notes owed

- Both tenant migrations are additive and self-guarding. The marker backfill is chunked, logs every
  tenant's survey counters, and must be observed during `tenants:migrate`; non-zero dirty counts are
  an owner decision and must not be silently reconciled.
- The receipts-wave seeder/map delivery is already present at the pin. Promotion still requires the
  appropriate reseed and `permission:cache-reset`.
- `packages/shared/types/generated.d.ts` contains M1's billing DTO fields plus the disclosed three
  additive current-source enum outputs. The separately identified uncommitted 3C enum drift remains
  owner-lane work and is identical at base and branch.
- D-6 remains binding: M1 migrations, grants/reseed, and permission cache reset must be live before
  dependent frontend milestones promote. No deployment was performed here.
