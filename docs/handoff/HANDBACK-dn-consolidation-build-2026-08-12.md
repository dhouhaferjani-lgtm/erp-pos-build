# HANDBACK — Delivery-Note Consolidation Billing Build

## Header

- Base SHA: `60df88a01b52828665caf33809486bdf0a699bbc` (owner-curated re-pin of 2026-08-19).
- Branch: `codex/dn-consolidation-2026-08-12`.
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/dn-consolidation`.
- Pre-re-pin blocker record: `codex/dn-consolidation-2026-08-12-pre-repin`.
- Current M1 implementation SHA: `c517635cb` (bridge round 3 accepted at `95ac2f22a`).
- Current M2 implementation SHA: `3b1af7fbc` (bridge round 2 accepted through `52aae14b7`).
- Current M3 implementation SHA: `2de9df339` (bridge round 2 accepted through `872e2a8e0`).
- Current M4 implementation SHA: `4d747ce41` (awaiting bridge review).
- Milestone being handed back: M4 implementation and amended preflight complete; bridge review is next.
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
- `668a4bc47 Phase 2.2.5: Record M2 differential preflight`
- `79f53e02d Phase 2.2.6: Record M2 bridge round one`
- `ed282949a Phase 2.2.7: Reproduce M2 bridge findings`
- `3b1af7fbc Phase 2.2.8: Close M2 bridge findings`
- `52aae14b7 Phase 2.2.9: Record M2 bridge fix round`

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

**Status: PASSED — bridge round 2 ACCEPT.**

Bridge round 1 reviewed `60df88a01..668a4bc47` through frontend-conventions, treasury, and general
lenses. It confirmed the module/permission riders, generated DTO flow, C9 tenant scoping, sidebar
permission-route parity, translations, precision guard, and the 66 focused tests. It found four
blocking gaps: the shipped list request omitted `status=confirmed`; off-page refused selections
were absent from the persistent attribution region; row totals used company rather than document
currency; and the handback lacked explicit RED-first/deviation evidence. Fix round 1 addresses
those blockers and the bounded P3 presentation issues in the same surface.

Fix round 1 has an auditable RED/GREEN split. Commit `ed282949a` contains tests only and fails seven
assertions. Commit `3b1af7fbc` supplies the implementation and makes all 17 targeted tests green:

- `deliveryNotes.test.ts` expected `status: confirmed`; before the fix the request omitted it.
- `PartnerDeliveryNotesTab.test.tsx` could not find off-page `DN-001`, its taking date/lane, or the
  `Open INV-001` link inside the persistent alert.
- The all-refused test lost `role=alert` after the operator removed the only refused selection.
- The EUR-row test received `TND 99.875` and could not find a EUR-formatted amount.
- Aggregate-scope tests received `(3 delivery notes)` rather than `(3 TND delivery notes)`.
- The coexistence test could not find either lane-guidance line.
- The balance-line test could find neither a `dt` nor a `dd` in the rendered definition list.

The fixes force confirmed status on every tab filter, render every refused document in the alert
regardless of pagination, preserve the guarantee when no remainder exists, format each row in its
own currency, identify the aggregate currency beside the count, restore all four coexistence lines,
localize row dates, use valid definition-list markup, remove the fake `invoiced_at='attributed'`
sentinel, and move the reusable billing-status component out of the partner-tab file. A negative
detail-page regression also proves an un-invoiced DN renders no billing line.

Bridge round 2 reviewed `60df88a01..52aae14b7` with the required frontend-conventions, treasury,
and general lenses. It reran the 72 focused tests, the exact 709-test §6.3 scope, typecheck, and
scoped lint; all M2 tests and touched-file checks passed, while the two declared finance residuals
were the only §6.3 failures. The register returned `VERDICT: ACCEPT`. Its close-before-merge P2 and
non-blocking P3 notes are recorded below and carried into M3/M5 where assigned.

### M2 failing-test-first register

The initial feature commits co-located their tests and implementations, so their transient terminal
RED output is not a separate commit. The test files and pre-implementation failures observed during
that TDD pass were:

- **View A and module/permission riders:**
  `PartnerDetailPage.deliveryNotes.gates.test.tsx` could not find the sixth tab or action under the
  allowed cases; `PartnerDeliveryNotesTab.test.tsx` could not find its filters, selectable rows,
  invoice attribution, or row-level refusal markers.
- **Generated DTO contract:** `deliveryNotes.test.ts`/TypeScript compilation failed when the new
  billing fields were read before the generated-DTO `Pick` was widened; the source guard prevents a
  hand-written object mirror.
- **A2:** `PartnerDeliveryNotesTab.test.tsx` could not find `Delivered, not yet invoiced`, the shared
  `300.750` aggregate, or a call to the currency formatter before the balance line existed.
- **422 mutation surface:** the exact-two-row test could not find the two refused row test IDs and
  observed no remainder-only second mutation before the persistent refusal state was implemented.
- **Detail attribution:** `DeliveryNoteDetailPage.billingStatus.test.tsx` could not find `Invoiced
  on`, `INV-001`, or the lane badge before the detail surface was added.
- **C9:** `deliveryNotesTenantScope.test.tsx` observed no partner aggregate key and no invalidation
  of the balance/to-bill/detail namespaces before the new hook behavior.
- **View C:** `Sidebar.test.tsx` could not find the lane-separation entry under
  `reports.financial`; the inverse permission assertion initially had no entry to hide.

The missing durable initial-RED artifact is recorded as a process deviation below. Fix round 1 does
not repeat it: `ed282949a` is the preserved failing checkpoint with the concrete output summarized
above.

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
  Both it and the tab consume the same aggregate query. Each surface reads `aggregates.count`
  directly and renders decimal-string `aggregates.total` through `useCurrency().format`; neither
  surface uses `parseFloat` or `Number`.
- Delivery-note detail and partner rows show `invoiced_via` plus a centralized entity link to the
  taking invoice. The partner table's delivery-note and post-success invoice navigation also use
  `entityRoutes.document`.
- Consolidation success invalidates the tenant/company-scoped delivery-note list/detail,
  document/invoice, partner balance, and to-bill namespaces required by C9.
- View C adds only the `/finance/lane-separation` sidebar entry. It shares the route's exact
  `reports.financial` permission and leaves the accounting-and-reports permission/route mapping
  one-to-one. No seeder or generated permissions-map file was edited.

### M2 verification evidence

- Focused M2 Vitest after fix round 1: 6 files, 72 tests passed. This includes five independent partner tab/action
  gate tests, the exact-two-row 422 test, aggregate-format parity, generated-type source guard,
  C9 tenant scoping, detail attribution, and the 45-test sidebar suite.
- Exact §6.3 path sweep after fix round 1: 89 files / 707 tests passed. Only the two owner-ledgered finance tests
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
- React Doctor against explicit base `60df88a01`: 88/100, 22 changed files scanned, no issues.
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

**PASSED at `3b1af7fbc`.** All M2-touched files are green. Across the exact repository scopes, the
failure set is byte-identical to the already captured `60df88a01` baseline: two locked PHPStan
precision findings, two default-SQLite composite-root fixture failures, two finance Vitest reds,
the normalized route-manifest patch, the SaleReceipt chokepoint, and the generated-types residual.
M2 introduces no new failure and does not modify any inherited-failure owner surface.

## M3 — Global to-bill work queue

**Status: PASSED — bridge round 2 ACCEPT.**

Bridge round 1 reviewed `60df88a01..87f4b75db` through frontend-conventions, tenancy-authz,
treasury, and general. It found two P1s: `apiGet` stripped the queue's top-level `meta`/`summary`
envelope, and View B suppressed attributed 422 toasts without supplying the required inline OI-8
recovery. Four P2s cover one-character search requests, partner-group counts mislabeled as delivery
notes, malformed PostgreSQL UUID input, and silent/unreachable location/currency scope. Fix round 1
is limited to those findings plus directly related P3 test hardening.

The fix round now preserves the complete transport envelope through a real Axios-to-page seam,
renders the ratified OI-8 attribution and explicit remainder retry, debounces and guards partner
search, labels summary values as customer groups, validates UUIDs before PostgreSQL, and exposes
server-described location/currency scope with an entitled all-locations toggle. An unresolved
company context renders a named empty state rather than a blank region. The register's P3
in-memory group pagination and unbounded remaining-page fan-out are recorded, not widened into a
queue redesign.

Bridge round 2 reviewed `60df88a01..872e2a8e0`, independently reran the focused M3 gates, and
returned `ACCEPT`. It confirmed all six blocking round-1 findings closed. Its eleven new notes are
P3 and recorded rather than expanded into M4: live-query page fan-out/offset races, stale
confirmation totals, cross-location invoice location attribution, stale refusal state after a
generic error, raw/UTC-sensitive dates, a narrow all-location recovery trap, sidebar test hygiene,
the no-active-location membership edge, untranslated location validation, and a missing-partner
read-path exception.

M3 commit sequence:

- `aed019e3b Phase 2.3.1: Specify delivery note to-bill queue`
- `2e767bbac Phase 2.3.2: Build delivery note to-bill queue API`
- `069912e62 Phase 2.3.3: Specify global delivery note work queue`
- `aa4332252 Phase 2.3.4: Build delivery note to-bill queue`
- `ce88e367a Phase 2.3.5: Clear to-bill static analysis gate`
- `c0c0c8273 Phase 2.3.6: Record to-bill route contract`
- `87f4b75db Phase 2.3.7: Record M3 differential preflight`
- `8c4bb6cdd Phase 2.3.8: Record M3 bridge round one`
- `19e0332bc Phase 2.3.9: Reproduce M3 bridge findings`
- `91468b98d Phase 2.3.10: Close M3 bridge findings`
- `47d982037 Phase 2.3.11: Reproduce remaining M3 bridge findings`
- `2de9df339 Phase 2.3.12: Close remaining M3 bridge findings`
- `2a983c621 Phase 2.3.13: Tighten to-bill transport assertion`
- `872e2a8e0 Phase 2.3.14: Record M3 bridge fix round`

### Failing-test-first evidence

The backend RED commit `aed019e3b` introduced `DeliveryNoteToBillQueueTest.php` before the queue
implementation. Against the inherited thin endpoint, the tests observed the old ungrouped payload,
no aging or whole-result summary, ignored location/partner/date/periodic filters, and no static lazy
partner endpoint. `2e767bbac` supplies the implementation and makes the four tests green.

The frontend RED commit `069912e62` introduced the page, location-scope, route-gate, sidebar, and
mixed-currency regressions before production code. The run failed because `useToBillQueue` did not
exist, `/sales/to-bill` and its sidebar item did not exist, the allowed route could not render the
page, and View A still allowed selection of a foreign-currency row. `aa4332252` supplies the page,
transport, hooks, route, navigation, translations, and selection guard. The initial pagination test
fixture claimed a two-row response while the request correctly retained the UI's 25-row page size;
its expectation was corrected to the actual request contract before the green commit.

### Delivered contract

- `GET /delivery-notes/uninvoiced` now returns an oldest-first, customer-only partner roll-up with
  partner/count/decimal-string total/company currency/oldest date/aging bucket/periodic flag,
  offset group pagination, four aging buckets, and a page-invariant grand summary.
- `GET /delivery-notes/uninvoiced/{partner}` is a static route declared before the UUID detail
  route. It lazily returns that partner's oldest-first delivery-note rows with independent offset
  pagination and a reconciliation summary. Both endpoints require `module:Sales` and
  `deliveries.view`.
- Both reads are tenant/company/location scoped. An explicit location is validated against the
  active membership; without one the server resolves its established company/default scope, and
  `all` is accepted only for an unrestricted membership. Partner search, date
  range, and billed-periodically filters apply identically to the roll-up and expanded rows and
  never silently widen on invalid input.
- The roll-up excludes supplier-only partners, draft or already-invoiced notes, foreign-company
  rows, and non-company-currency rows. Money remains decimal strings; no float enters the service or
  frontend.
- `/sales/to-bill` has independent `ModuleGuard module="Sales"` and
  `RequirePermission permission="deliveries.view"` route assertions. The Sales sidebar item uses
  the same permission and follows Delivery notes. The per-group Create invoice action additionally
  requires `invoices.create`; there is no global bill-everyone action.
- The page renders the 0–30 / 31–60 / 61–90 / 90+ strip, partner/date/periodic filters,
  periodic-billing chips, oldest-first groups, lazy rows, row/group pagination, reconciliation text,
  coexistence guidance, empty/error/loading states, and a partner/count/total confirmation.
  Confirmation exhausts every row page before calling the existing atomic consolidation mutation.
- Expanded rows use a `Pick<App.Modules.Document.Application.DTOs.DocumentData>`; no hand-written
  delivery-note transport mirror was added. Query keys include tenant, company, and active-location
  scope, so an active location change produces a distinct key and refetch.
- The M2 round-2 close-before-merge P2 is closed: View A leaves foreign-currency DNs visible but
  disables their selection, preventing a mixed-currency batch while preserving the server's
  company-currency aggregate disclosure.
- The factory route manifest records only `/sales/to-bill` with its exact `Sales` and
  `deliveries.view` contract. The reserved seeder and generated permission map were not edited.

### M3 verification evidence

- Focused frontend after the fix round: 7 files / 82 tests passed; typecheck passed. Scoped ESLint
  reports zero errors and only three inherited warnings in old `useDeliveryNotes.ts` lines outside
  the M3 additions.
- Focused backend: queue/access/lane-separation 18 tests / 127 assertions passed. Focused PHPStan
  reports no errors, and changed-file Pint passes.
- Stable single-worker exact §6.3 frontend scope: 95 files, 729 tests passed / 2 failed of 731. The failures are exactly
  the owner-ledgered `finance/api.test.ts` and `finance/hooks/__tests__/tenantScope.test.tsx` reds;
  every M3 test passes. Two default-parallel sweeps also exposed a load-sensitive PartnerForm race
  (and once a Sidebar timeout); both files pass together 55/55 in isolation and neither is touched
  by this fix round, so they are recorded as runner flake rather than added to the stable failure set.
- Exact backend path scope: 1,068 passed / 32 skipped / 2 failed. Both failures are the inherited
  default-SQLite `InventoryGlCompositeRootTest` fixtures (`tenants` table absent); the PostgreSQL
  control passes 2 tests / 19 assertions.
- Whole PHPStan reproduces only the locked `CopiesDocumentData.php:309-310`
  `precision.hardcodedBcmathScale` findings. Touched PHPStan is green, and NG-4 was respected.
- Whole-web ESLint has zero errors; TanStack-key audit is 0 new, design-system audit is 734
  acknowledged / 0 new / 0 stale, quantity audit is 0, web and POS custom ESLint-rule tests pass,
  and fiscal parity passes 29/29.
- React Doctor against explicit base `60df88a01` scores 88/100 across 27 changed files. Its only two findings are the
  already-reviewed M2 partner-tab component-size/chained-iteration warnings; M3 introduced no
  Doctor regression.
- Route-manifest regeneration contains no `/sales/to-bill` delta after `c0c0c8273`; only the
  inherited manifest residual remains. The SaleReceipt audit still reports only
  `InventoryCountingController.php:135`, while its six-entry receiver validator passes.
- `git diff --check`, base ancestry, translation parity, generated-DTO source guard, and the
  no-float scan pass. M3 does not touch `CopiesDocumentData.php`, the permission seeder, or the
  generated permission map.

### M3 amended differential verdict

**PASSED at `2a983c621` for bridge round 2.** Every M3-touched surface is green. The exact
repository failure set is byte-identical to or smaller than the pinned-base/M2 record: two locked
PHPStan findings, two SQLite-only composite-root fixtures, two finance Vitest reds, and the known
route-manifest/generated-types/SaleReceipt residuals. No new failure is present.

## M4 — Retirement and periodic-billing classification

**Status: REVIEW — implementation and amended preflight complete.**

M4 commit sequence:

- `b796c9b4f Phase 2.4.1: Specify consolidation retirement and periodic billing`
- `38fdd18f9 Phase 2.4.2: Retire legacy consolidation controls`
- `4d747ce41 Phase 2.4.3: Tighten retirement verification`

### Failing-test-first evidence

The RED commit `b796c9b4f` made the create request, periodic-billing copy, and retired route contract
fail against the inherited implementation: a periodically classified customer without a frequency
received 422, the form still described and exposed consolidation frequency, and the explicit
legacy route still rendered. The GREEN commits remove the explicit route and its owned UI,
relax create validation, remove the selector, and tighten the new terminology assertion. The
generic UUID detail route is intentionally left to handle an arbitrary literal path; no redirect or
compatibility alias was added.

### Delivered contract

- The explicit `/inventory/delivery-notes/consolidate` route, lazy page, component, route tests,
  component tests, and barrel export are deleted. The route manifest no longer records the path.
  The canonical backend `POST /delivery-notes/consolidate-to-invoice` remains because Views A/B
  use that atomic mutation.
- `PartnerForm` and `B2BFieldsSection` no longer own, default, watch, submit, or render
  `consolidation_frequency`. The database column, enum, backend DTO, generated transport, and update
  validation are preserved.
- `CreatePartnerRequest` accepts a nullable enum without conditionally requiring it. The new create
  regression proves `invoice_consolidation=true` with no frequency returns 201; the update
  regression independently proves the existing optional/nullable update contract remains intact.
- The classification is labelled **Billed periodically** / **Facturé périodiquement** with neutral
  explanatory copy and no scheduling or automation promise.
- No partner migration exists in `60df88a01..4d747ce41`. The permission seeder and generated map
  remain untouched.

### M4 verification evidence

- Focused `PartnerForm.test.tsx`: 11/11 passed. Full touched frontend paths
  `src/features/partners src/routes`: 17 files / 147 tests passed. Typecheck passed; scoped ESLint
  has zero errors and only six inherited warnings.
- Partner backend path: 221 passed / 4 skipped / 1,187 assertions. The two new B2B regressions pass;
  changed-file Pint and focused PHPStan are green.
- Stable single-worker exact §6.3 frontend scope: 92 files, 724 passed / 2 failed of 726. The two
  failures are byte-identical to the ledgered finance baseline and every M4 test passes.
- Query-key and quantity audits report zero; design-system audit reports 728 acknowledged / 0 new /
  0 stale after removing six entries owned by the deleted UI. React Doctor remains 88/100 with only
  the two accepted M2 partner-tab findings.
- Production scans find no `DeliveryNoteConsolidation` symbol or retired path, no form/locale
  frequency-selector ownership, and no partner migration. Translation keys are present in both
  English and French and the replacement copy contains no automation implication.
- Exact backend scope: 1,070 passed / 32 skipped / 2 failed (4,566 assertions). Both failures are
  the inherited default-SQLite `InventoryGlCompositeRootTest` fixtures; the PostgreSQL control from
  the prior milestone remains green.
- Whole PHPStan is green after explicitly clearing its result cache. The owner-expected two C-3
  findings no longer reproduce, so the failure set is smaller; `CopiesDocumentData.php` was not
  touched and NG-4 remains respected.

### M4 amended differential verdict

**PASSED at `4d747ce41` for bridge round 1.** Every M4-touched surface is green. The exact frontend
and backend failure sets are byte-identical to the pinned baseline, and whole PHPStan is smaller
after a cache-cleared run. No new failure is present.

## Standing findings and deploy obligations

- **F-1 resolved:** all three accountant grants and the merged frontend map are present at the pin.
- **F-3:** the existing DN list/detail route retains the owner-ruled `moduleKey="inventory"`
  residual. This lane did not change it.
- **M2 route residual:** the base `GET /delivery-notes` route used by the tab has
  `can:deliveries.view` but no backend `module:Sales`. The tab and action are independently gated as
  approved. Adding a backend revocation to that existing route was not authorized by M2 and is
  returned to the owner rather than silently widened.
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
- M2's original feature commits preserve tests and implementation together rather than a durable
  failing-test commit. The per-item observed failures are recorded in the M2 register above; bridge
  fix round 1 corrects the process with the explicit RED `ed282949a` / GREEN `3b1af7fbc` split.
- The four-line coexistence helper is listed under M3 in the milestone table but is necessary to
  explain M2's two billing lanes and was already present in the approved M2 design. Fix round 1
  restores the spec's exact four-line substance in en/fr instead of deleting it.
- `FilterTabs` gained `aria-pressed` in M2 although it is a shared molecule. This is a bounded,
  backward-compatible accessibility correction needed for the independently asserted tab state;
  it changes no selection behavior.
- The tab sends `status=confirmed` for every filter. This is required to keep every billing action
  and aggregate billable, but means the user-facing **All** tab is all confirmed DNs rather than all
  statuses, a deliberate narrowing from the request shape shown in spec §3.1.
- The A2 count now includes the aggregate currency (`3 TND delivery notes`) so its company-currency
  scope is explicit. This intentionally extends the exact example string in spec §3.1.

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
- **M3 closure:** View A now keeps foreign-currency DNs visible but non-selectable, so its selection
  cannot create a mixed-currency batch. Other non-race `CONSOLIDATION_VALIDATION_FAILED` causes
  (wrong partner/status or no lines) still use the generic untranslated error path and remain an
  out-of-scope presentation residual for M5 review.
- The A2 balance line returns `null` for loading, error, and absent-data states alike, so an aggregate
  transport failure is visually indistinguishable from zero un-billed exposure.
- Refusal-alert dates remain raw `Y-m-d` even though table dates now use the active locale.
- API contract coverage asserts the default un-invoiced filter only; explicit invoiced/all parameter
  branches do not yet have direct request-shape regressions.
- A `deliveries.view` user without `invoices.create` sees selection checkboxes but no action. The
  list is correctly visible and the action correctly hidden, but the selection UI is inert.
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
