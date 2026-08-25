# C-0a0 handback — exhaustive default-REFUSE payment applicability

**Branch** `feat/sc-0a0-payment-applicability` · **worktree** `.worktrees/sc-0a0-payment-applicability`
**Base** local `dev` `faf822f8a` (N-6 Phase 1 merged at `084a2b062`)
**Commits** `f06d2ab29` (implementation) · `57b4dd940` (close-with-tolerance guard order) · handback commit
**Migration: NONE.** No schema change, no DDL, no backfill, no data touched. Provenance is read from columns that
already exist (`documents.is_historical`, `documents.reference`); the persisted provenance columns are lane C-PROV0.
**Gate:** treasury-reviewer. NOT merged.

---

## 1. What shipped

> **REWRITTEN AT GATE r2 (condition 1 / G-1), FROM THE CODE AT `4ab709d04`.** The r1 text of this section
> and of §6 was left untouched by the r1 fix commit and therefore described a NARROWER policy and a SMALLER
> scope than what ships. The gate was right that this is the section a parent reads to sign off, so it is
> restated here from `DocumentAllocationClassifier` and from the closed-set assertion that pins it
> (`PaymentApplicabilityMatrixTest::test_the_admitted_set_is_closed_and_exactly_this()`), not from memory.

`DocumentAllocationClassifier` (Treasury Domain) is EXHAUSTIVE and DEFAULT-REFUSE over
`(DocumentType, DocumentStatus, provenance)`, and **every** production writer of `payment_allocations` passes
it before any write.

**Admitted — and nothing else: SIX `(type, status[, provenance])` cells.** The closed-set test enumerates
them as 18 literal strings because the sales-order and supplier-invoice rows repeat across all five
provenance families (rules 2–5 are scoped to Invoice and CreditNote, so a provenance marker on any other
type is meaningless and must not change its verdict):

| # | Type | Status | Provenance | Treatment |
|---|---|---|---|---|
| 1 | Invoice | `posted` | native | `ReceivableClearing` (Cr 411) |
| 2 | Invoice | `confirmed` | native | `Prepayment` (Cr 419) |
| 3 | Invoice | `posted` | **historical AR** | `ReceivableClearing` (Cr 411) — admitted by the r1 fix round (F-2); see the cutover-ordering note below |
| 4 | SalesOrder | `confirmed` | any (provenance-invariant) | `Prepayment` (Cr 419) |
| 5 | SalesOrder | `posted` | any (provenance-invariant) | `Prepayment` (Cr 419) — see the OPEN RULING below |
| 6 | SupplierInvoice | `posted` | any (provenance-invariant) | `PayableSettlement` (Dr 401) |

Everything else is `Refused` with a typed 422 `DOCUMENT_NOT_ALLOCATABLE` carrying an
`AllocationRefusalReason` (8 cases) and an i18n message (en/fr/ar).

**⚠ OPEN RULING carried into merge — `SalesOrder + posted` (gate r2 / G-2, condition 2).** Row 5 restores
the N-6 gate's explicit ruling (`Posted` is reachable via `DocumentPostingService::cancelSalesOrder()`;
narrowing below the pre-N-6 pure-type rule is a regression) and therefore contradicts spec r11 §2.1 rule 7,
which says "SalesOrder + confirmed". Money-safe either way — both live statuses take an ADVANCE, because a
sales order never carries a 411. The spec must be amended or the code narrowed and the N-6 ruling formally
withdrawn. **Owned by the orchestrator, not by this lane.**

**⚠ OPEN RULING carried into merge — OQ-74 (condition 3).** Row 3 (historical AR openings collectable on
the AR path before C-0a1) is recorded as the implemented DEFAULT; it is not yet ruled on the owner sheet.
**Owned by the orchestrator.**

**⚠ CUTOVER ORDERING for row 3 (gate r2 / R-R2-1).** An AR opening DOCUMENT creates no journal entry of its
own — `ArApOpeningService` states it three times ("No GL entry created (GL was handled by accounting
opening)", `:37`, `:257`, `:427`); it writes `is_historical`, `balance_due` and a `posted` status and nothing
else. The 411 that row 3's collection CREDITS therefore exists only if the tenant ALSO posted an
`AccountingOpeningService` opening batch. **Runbook: post the accounting opening batch BEFORE collecting
against AR open items**, or the credit lands against a debit that was never made and the partner's
receivable goes negative. Inherited, not introduced (base admitted the same collections), but C-0a0 is what
re-enables the path — so the ordering is stated here and in the classifier docblock beside the admission
itself (`DocumentAllocationClassifier::refusalForHistoricalOpening()`).

### Changes of behaviour vs N-6 (as shipped at `4ab709d04`)

| Cell | N-6 | C-0a0 as shipped | Why |
|---|---|---|---|
| `Invoice + paid` | `ReceivableClearing` | Refused `document_not_live` | `paid` is a RETIRED lifecycle value (F-88); a settled document admits no new money |
| `SalesOrder` at any live status | `Prepayment` | `Prepayment` at `confirmed` AND `posted` — **unchanged from N-6** | r1 narrowed this to `confirmed`; gate r1 F-9 restored it. Spec conflict recorded above |
| `PurchaseOrder` (any live status) | `ReceivableClearing` | Refused `purchase_order_wrong_direction` | F-153 / LEDGER OQ-3. N-6's own docblock called this row "EXPLICITLY WRONG" and named it residual R-1. It booked a NEGATIVE **customer** receivable against a **supplier** partner |
| `Invoice` historical **AP** opening, or an opening whose side is UNPROVABLE | admitted as an ordinary invoice | Refused `historical_opening_provenance` | The Dr bank / Cr 411-for-a-PAYABLE defect this lane exists to kill. Fail closed where the evidence is absent |
| `Invoice` historical **AR** opening | admitted | **admitted** (`ReceivableClearing`) | r1 refused it; gate r1 F-2 proved the AR/AP discriminator exists on base (`opening_balance_import_rows.row_type` + `mapped_entity_id`) and that blanket refusal cost a cutover its whole open-receivables ledger |
| `Invoice`/`CreditNote` with the POS account-charge marker | admitted as ordinary invoices | Refused `pos_derived_provenance` | F-89 / F-107. The 411 already exists (sealed POS fiscal event), so it is never a 419 advance — but nothing here can yet prove the adopted receivable, so it waits for C-0a1 |
| `CreditNote` historical opening, either side | admitted | Refused | spec rule 4 / OQ-37 |
| `SupplierInvoice + posted` | refused HERE, handled by a `? null :` bypass in `PaymentController::store()` | `PayableSettlement` | The one path capable of paying a supplier was the one path the policy object never saw. Runtime behaviour UNCHANGED (the posted-ness + Cr-401-evidence guard still runs; `PayableSettlement` is not a prepayment) |

Three seams keep this safe:
- `classifyReceivableSide()` — the AR-only writers refuse `PayableSettlement` explicitly (with
  `payable_not_settleable_here`) instead of refusing the TYPE by hand.
- `rejectUnallocatable()` / `allocatableTreatmentOrSkip()` — the AUTO sweep SKIPS a refused target and logs
  the reason; only MANUAL and direct (operator-named) targets throw.
- `assertReversalAdmitted()` — the refund/reversal writers. **Seam present, predicate deferred to C-0a1.**


## 2. Red → green, per test file, by path

RED was captured on BASE production code: the branch's own test files were run against `git checkout dev --` of every
production path (with the new enum moved aside to the scratchpad), then restored with `git checkout HEAD -- apps/api`.
The repo-global WIP-shelf was never touched at any point — `git stash list` is unchanged (two pre-existing entries
belonging to other sessions).

### `tests/Unit/Treasury/PaymentApplicabilityMatrixTest.php` — 13 types x 6 statuses x 3 provenance families
RED (base, sqlite):
```
Tests:    238 failed (1 assertions)
FAILED  ... > every…  Error  Class "App\Modules\Treasury\Domain\Enums\AllocationRefusalReason" not found
```
GREEN (sqlite): `Tests: 238 passed (953 assertions)` · GREEN (PG 5433): included in `Tests: 240 passed (4043 assertions)`

### `tests/Unit/Treasury/PaymentAllocationWriterCensusTest.php` — executable grep census
RED (base, sqlite) — the four writers that never consulted the classifier at all:
```
These payment_allocations writers reach a write without a DocumentAllocationClassifier call earlier in the same method:
  app/Modules/Treasury/Domain/Services/PaymentRefundService.php:209
  app/Modules/Treasury/Domain/Services/PaymentRefundService.php:1299
  app/Modules/Treasury/Domain/Services/PaymentRefundService.php:1948
  app/Modules/Treasury/Domain/Services/VendorRefundService.php:140
Tests:    1 failed, 1 passed (3086 assertions)
```
GREEN (sqlite): `Tests: 2 passed (3088 assertions)` · GREEN (PG 5433): `Tests: 240 passed (4043 assertions)` (with the matrix)

### `tests/Feature/Treasury/PurchaseOrderAllocationRefusedTest.php` — 7 entry points
RED (base, sqlite) — note the **201 Created**: paying a purchase order SUCCEEDED:
```
⨯ the direct payment endpoint refuses a purchase order        Expected 422, received 201
⨯ the manual allocation preview refuses a purchase order      Expected 422, received 200
⨯ the manual allocation execute refuses a purchase order      Expected 422, received 200
✓ the auto allocation preview never offers a purchase order      (already true: SQL mirror)
✓ the auto allocation execute writes nothing for a purchase order (already true: SQL mirror)
⨯ the split payment path refuses a purchase order             Expected 422, received 201
⨯ the apply deposit path refuses a purchase order             Expected 422, received 200
Tests:    5 failed, 2 passed (12 assertions)
```
GREEN (sqlite): `Tests: 7 passed (32 assertions)` · GREEN (PG 5433): `Tests: 7 passed (32 assertions)` (94.38s)

### `tests/Feature/Treasury/HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php` — 5 entry points x 4 provenance families
Documents minted through the REAL services (`OpeningBalanceBatchService` + `ArApOpeningService::postBatch()`,
`POSAccountChargeDraftService::createDraft()`), not hand-built, so the markers under test are the ones those
services actually write (asserted inline).
RED (base, sqlite) — all 20 fail; the AP opening returns **201 Created**:
```
⨯ the direct payment endpoint refuses it (historical AP / historical AR / POS confirmed)  Expected 422, received 201
⨯ the direct payment endpoint refuses it (POS posted)                                     Expected 422, received 500
⨯ the manual allocation preview / execute / apply-deposit refuses it (x12)                Expected 422, received 200
⨯ the auto allocation execute writes nothing for it (x4)                                  allocation rows written
Tests:    20 failed
```
GREEN (sqlite): `Tests: 20 passed (118 assertions)` · GREEN (PG 5433): `Tests: 20 passed (118 assertions)` (64.64s)

### Regressions run by path (all GREEN, sqlite)
| Batch | Result |
|---|---|
| `N6PaymentOnUnpostedInvoiceTest` · `DocumentPaymentStatusTransitionTest` · `PaymentAllocationDocumentStateTest` · `Document/CreditNoteAllocationTest` · `VendorPrepaymentRefundTest` | `51 passed (173 assertions)` |
| `Unit/Document/DocumentStatusMachineTest` · `PaymentAllocationServiceTest` · `…ToleranceContractTest` · `…TolerancePersistenceTest` · `SmartPaymentIntegrationTest` · `MultiPaymentSpineTest` · `PaymentControllerSpineTest` | `54 passed (265 assertions)` |
| `PaymentRefundTest` · `PaymentRefundProrationTest` · `PaymentReversalNetLineageTest` · `PaymentReversalDocumentTest` · `ProRataResidualRedistributionTest` · `VendorRefundScalingTest` · `RefundSpineTest` (the reversal writers) | `81 passed (357 assertions)` |
| `AdvanceReversalRefusalsAndCeilingTest` · `PaymentAllocationPrecisionTest` · `PaymentTest` · `PaymentRegistrationFlowTest` · `PaymentReversalApiAndEventTest` · `PaymentReversalRefusalTest` · `TreasuryEventsTest` | `1 skipped, 62 passed (265 assertions)` |
| `CloseInvoiceWithToleranceServiceTest` + census | `13 passed (3132 assertions)` |

PG 5433 (`autoerp_test_sc0a0`, created and dropped by this lane) regression batch:
`N6PaymentOnUnpostedInvoiceTest` · `DocumentPaymentStatusTransitionTest` · `PaymentAllocationDocumentStateTest` ·
`CloseInvoiceWithToleranceServiceTest` → `Tests: 41 passed (154 assertions)`.

**The full PHPUnit suite was never run** (LANE-PROTOCOL); one test process at a time throughout.

### One regression found and fixed inside the lane
`CloseInvoiceWithToleranceServiceTest > rejects an already paid invoice` went red on the first pass: rule 1 refuses
`paid` as `document_not_live`, which shadowed that endpoint's specific `INVOICE_ALREADY_PAID`. Fixed in `57b4dd940`
by moving the already-settled check AHEAD of the classifier (order, not policy — everything not already settled still
clears the classifier before any row is written). Verified green on sqlite and PG.

---

## 3. Production changes — file:line

**New files**
- `apps/api/app/Modules/Treasury/Domain/Enums/AllocationRefusalReason.php` (new, 7 cases + `translationKey()`)
- `apps/api/tests/Feature/Treasury/Concerns/PaymentApplicabilityScaffold.php` (test fixture trait)

**Deleted**
- `apps/api/tests/Unit/Treasury/DocumentAllocationClassifierMatrixTest.php` — SUPERSEDED, not dropped.
  It asserted the same policy table over 13x6 pairs with no provenance axis and no reasons; two matrix tests over one
  table would have to disagree, since C-0a0 narrows three of N-6's admitted cells. Its three NAMED cells (gate finding
  I-5's `SalesOrder + Posted`, the N-6 edge `Invoice + Confirmed`, `Invoice + Draft`) are carried into
  `PaymentApplicabilityMatrixTest::test_the_cells_this_lane_closes()` with each verdict change stated. The supersession
  is recorded in the new file's class docblock.

**Modified**
| File:line | Change |
|---|---|
| `Treasury/Domain/Services/DocumentAllocationClassifier.php` (rewritten) | `refusalReasonFor()` :175-232 (rule 1 :178; provenance :185-194; exhaustive per-type `match` :199-231, no `default` arm); `classify()` :117-131; `classifyReceivableSide()` :142-157; `classifyOrNull()` :164-169; `isAllocatable()` :171; `assertReversalAdmitted()` :255-258; `treatmentFor()` :267 (second exhaustive `match`); `isHistoricalOpening()` :288; `isPosAccountCharge()` :303; marker constants :96, :103 |
| `Treasury/Domain/Enums/AllocationTreatment.php:44` | NEW case `PayableSettlement`; `isReceivableSide()` :55-61 (exhaustive `match`) |
| `Treasury/Domain/Exceptions/DocumentNotAllocatableException.php:41` | NEW readonly `AllocationRefusalReason $reason`; message now names the reason |
| `bootstrap/app.php:963,968,977` | render arm now emits `__($e->reason->translationKey())` as `message` and `reason` in `details` |
| `Treasury/Presentation/Controllers/PaymentController.php:1045` | supplier bypass (`type === SupplierInvoice ? null : classify(...)`) REMOVED — unconditional `classify()` |
| `Treasury/Presentation/Controllers/PaymentController.php:603` | NEW pre-transaction fail-fast `classifyReceivableSide()` in the non-supplier branch, before the cap-and-advance math |
| `Treasury/Presentation/Controllers/PaymentController.php:1485, 1812, 1895` | `classify()` → `classifyReceivableSide()` (multi-line primary, manual excess, auto excess) |
| `Treasury/Presentation/Controllers/MultiPaymentController.php:215, 412` | NEW `catch (DocumentNotAllocatableException) { throw $e; }` ahead of the generic `catch (\Exception)` in `createSplitPayment()` and `applyDeposit()`; import :15 |
| `Treasury/Application/Services/PaymentAllocationService.php:214, 734` | `classify()` → `classifyReceivableSide()` (execute loop, manual preview) |
| `Treasury/Application/Services/CloseInvoiceWithToleranceService.php:100` | `classify()` → `classifyReceivableSide()`; guard order: already-settled check now precedes it (:95-98) |
| `Treasury/Domain/Services/MultiPaymentService.php:89, 306` | `classify()` → `classifyReceivableSide()` (split payment, deposit application) |
| `Treasury/Domain/Services/PaymentRefundService.php:75` | NEW ctor dep `DocumentAllocationClassifier` |
| `Treasury/Domain/Services/PaymentRefundService.php:226, 1330, 1988` | NEW `assertReversalAdmitted()` before each of the three `PaymentAllocation::create()` sites |
| `Treasury/Domain/Services/VendorRefundService.php:34` | NEW ctor dep `DocumentAllocationClassifier` |
| `Treasury/Domain/Services/VendorRefundService.php:149` | NEW `assertReversalAdmitted($lockedPo)` before the write |
| `lang/en/treasury.php`, `lang/fr/treasury.php`, `lang/ar/treasury.php` | NEW `allocation_refused.*` block, one key per reason |
| `tests/Feature/Treasury/DocumentPaymentStatusTransitionTest.php:165-200` | `test_fully_paid_purchase_order_retains_confirmed_status` → `test_paying_a_purchase_order_is_refused` (the old assertion PINNED the F-153 defect) |

All dependencies are constructor-injected `private readonly`; no `app()` in production code. No floats on money —
the only arithmetic touched is existing `bccomp`/`bcadd` with a resolved scale.

---

## 4. Entry-point census (executable: `PaymentAllocationWriterCensusTest`)

The test walks every `.php` under `app/`, finds each `PaymentAllocation::(create|insert|updateOrCreate|firstOrCreate)`,
walks BACKWARDS to the start of the enclosing method, and asserts a `allocationClassifier->…` call in between. It also
pins the writer file set (so a new writer FILE cannot appear silently) and asserts no raw-builder writes to the table.

| # | Writer (file:line of the write) | Classifier call | Method |
|---|---|---|---|
| 1 | `Treasury/Application/Services/CloseInvoiceWithToleranceService.php:162` | `:100` | `classifyReceivableSide` |
| 2 | `Treasury/Application/Services/PaymentAllocationService.php:218` | `:214` (locked row) | `classifyReceivableSide` |
| 3 | `Treasury/Domain/Services/MultiPaymentService.php:130` (split payment) | `:89` | `classifyReceivableSide` |
| 4 | `Treasury/Domain/Services/MultiPaymentService.php:308` (deposit application) | `:306` | `classifyReceivableSide` |
| 5 | `Treasury/Domain/Services/PaymentRefundService.php:229` (full refund) | `:226` | `assertReversalAdmitted` |
| 6 | `Treasury/Domain/Services/PaymentRefundService.php:1333` (payment reversal) | `:1330` | `assertReversalAdmitted` |
| 7 | `Treasury/Domain/Services/PaymentRefundService.php:1991` (pro-rata unwind) | `:1988` | `assertReversalAdmitted` |
| 8 | `Treasury/Domain/Services/VendorRefundService.php:151` (PO prepayment refund) | `:149` | `assertReversalAdmitted` |
| 9 | `Treasury/Presentation/Controllers/PaymentController.php:1048` (direct store) | `:1045` (locked row) + `:603` (pre-transaction fail-fast) | `classify` / `classifyReceivableSide` |
| 10 | `Treasury/Presentation/Controllers/PaymentController.php:1639` (multi-line) | `:1485` | `classifyReceivableSide` |
| 11 | `Treasury/Presentation/Controllers/PaymentController.php:1818` (manual excess) | `:1812` (locked row) | `classifyReceivableSide` |
| 12 | `Treasury/Presentation/Controllers/PaymentController.php:1900` (auto excess) | `:1895` (locked row) | `classifyReceivableSide` |

**Non-writers verified by reading, not assumed:**
- `POS/…/TreasuryReceiptBridge` and the treasury bridges create `payments` only — they reach allocations exclusively
  through `PaymentAllocationService::applyAllocationFromCommand()` (row 2), which is classified. No separate site.
- The AUTO (FIFO / due-date) path has a SECOND guard that is not the classifier: the SQL mirror in
  `PaymentAllocationService::getOpenInvoices():517-547` restricts the offered set to
  `Invoice+posted | Invoice+confirmed | SalesOrder+confirmed`. That mirror is CORRECT under the new rules by
  coincidence of scope (it never listed purchase orders), but it does NOT know about provenance — a historical opening
  is `Invoice + posted` and open by construction, so the FIFO sweep still SELECTS it and the classifier refuses it on
  the locked row inside the execute loop. Covered by
  `HistoricalAndPosInvoicesRefusedBeforeProvenanceTest::test_the_auto_allocation_execute_writes_nothing_for_it`.
  **Residual R-C0a0-2** below.

---

## 5. Residuals and adjacent defects NOT touched

**R-C0a0-1 — `assertReversalAdmitted()` is total (admits everything).** Deliberate and documented in the method's
docblock, not an oversight. This lane narrows what money may come IN; narrowing what may come back OUT would strand
cash on documents it has just stopped admitting — `VendorRefundService` is the live proof: C-0a0 refuses NEW money on
a purchase order, and the prepayments already sitting on POs must stay refundable. The call exists so (a) the census
can prove every writer passes the classifier and (b) a reversal rule has exactly one place to land. A rule with teeth
(e.g. "the target must already carry a live allocation") needs a DB read the pure Domain policy object does not do
today. **Owner: C-0a1**, once provenance distinguishes the families.

**R-C0a0-2 — `getOpenInvoices()` is a hand-maintained SQL mirror of the classifier.** Two halves of one rule that can
drift: the mirror has no provenance predicate and no `SupplierInvoice` awareness, so today it OFFERS historical
openings that the execute loop then refuses. Nothing is mis-booked (the locked-row verdict is authoritative and the
FIFO sweep silently writes nothing), but an operator sees a document listed as payable and then a refusal. A generated
predicate — or a `whereNotAllocatable()` scope derived from the classifier — is the fix. **Not in scope** (readers are
explicitly out of this brief).

**R-C0a0-3 — the auto (FIFO) execute path returns 200 with zero allocations when the only open documents are refused.**
It does not report WHY nothing was allocated. Adjacent UX defect, no money consequence, untouched.

**R-C0a0-4 — `MultiPaymentController` has more `catch (\Exception $e) { return ['error' => $e->getMessage()] }`
arms** (`:324`, `:451`, `:506`, `:551`, `:585` plus the two now-fixed ones). Each flattens a typed domain refusal into
a bare string, losing the code the frontend routes on. Only the two on the allocation paths were fixed (in scope);
the rest are the same latent shape on other endpoints. Not touched.

**R-C0a0-5 — `PaymentAllocationService::rejectSupplierInvoiceAllocation()` (`:684-701`) is now redundant** with the
classifier's `PayableSettlement` + `classifyReceivableSide()` refusal, but it fires FIRST and returns a different code
(`SUPPLIER_INVOICE_NOT_PAYABLE_HERE`) that existing tests and the frontend pin. Left in place deliberately — collapsing
two refusal codes into one is an API change, not a policy fix. Same for
`PaymentController::rejectSupplierInvoiceInMultiline()` and `MultiPaymentController::rejectSupplierInvoice()`.

**R-C0a0-6 — `AdvanceReversalGlShapeTest` is 11-failed INHERITED RED on base `dev`.** Verified by running it against
`git checkout dev --` production code: `Tests: 11 failed, 1 passed (28 assertions)` — identical failures with and
without this branch. NOT caused by this lane, NOT fixed by it (out of scope). Flagged for the parent's red-gate ledger.

**R-C0a0-7 — inherited PHPStan red in Treasury tests** (5 files, 18 errors), all untouched by this lane:
`tests/Unit/Treasury/{DiscountToleranceBoundaryTest, PaymentMethodEntityTest, PaymentToleranceCheckerContractTest,
PaymentToleranceServiceTest, PaymentTypeReversalTest}.php` (`method.resultUnused`) and
`tests/Feature/Treasury/DocumentPaymentStatusTransitionTest.php:335-336` (`bcmul` `argument.type`, inside the
pre-existing `createDocument()` helper — outside my diff hunk). **Every file this lane wrote or changed the logic of
is PHPStan-clean** (`[OK] No errors` on the 11 production paths and on the 5 new/rewritten test paths).

---

## 6. Scope note — six paths outside the brief's declared file boundary

> **REWRITTEN AT GATE r2 (condition 1 / G-1), FROM THE DIFF AT `4ab709d04`.** The r1 text claimed "one file
> outside the boundary" and "nothing else … readers are untouched"; the r1 fix round then added four more
> paths, one of which IS a reader. Declared here in full.

The brief scopes changes to `app/Modules/Treasury/**` + its tests + `lang/*/treasury.php`. Six paths fall
outside that, each with its justification:

| # | Path | Why it is outside, and why it was unavoidable |
|---|---|---|
| 1 | `apps/api/bootstrap/app.php` (`:964`, `:970`) | The brief REQUIRES "typed 422 `DOCUMENT_NOT_ALLOCATABLE` with an i18n reason key", and this render arm is the ONLY boundary that turns the Treasury exception into an HTTP body. The hunk is confined to the existing `DocumentNotAllocatableException` arm: `message` becomes `__($e->reason->translationKey())` and `reason` joins `details`. No other handler, no ordering change. |
| 2 | `apps/api/app/Shared/Contracts/Accounting/HistoricalOpeningSide.php` (NEW) | Gate r1 F-2 required narrowing the fail-closed refusal to the AP side. The discriminator is an Accounting concept, and rule 6 forbids Treasury Domain importing an Accounting model — so it crosses as a Shared contract. This is the value type. |
| 3 | `apps/api/app/Shared/Contracts/Accounting/HistoricalOpeningSideReaderInterface.php` (NEW) | The port itself. Treasury depends on THIS, never on `OpeningBalanceImportRow` / `OpeningBalanceBatch`. |
| 4 | `apps/api/app/Modules/Accounting/Application/Services/HistoricalOpeningSideReader.php` (NEW) | The implementation, in the module that OWNS the opening-import tables. It is a READER — the brief puts readers out of scope, and this one exists only because F-2 made an AR/AP verdict a precondition of the classifier's own decision. Follows the `PaymentLedgerPartitionReader` precedent exactly. |
| 5 | `apps/api/app/Providers/AppServiceProvider.php` (`:123`, imports `:10`, `:50`) | One `bind()` line for #3→#4. Constructor injection is the only wiring rule; the binding has to live where the other Shared-contract bindings do. |
| 6 | `apps/api/tests/Feature/Treasury/DocumentPaymentStatusTransitionTest.php` (`:179-202`) | Inside "its tests", but flagged because the change INVERTS an existing assertion: `test_fully_paid_purchase_order_retains_confirmed_status` → `test_paying_a_purchase_order_is_refused`. The old assertion PINNED the F-153 defect (it asserted that paying a purchase order returns 201). |

Everything else in the diff is `app/Modules/Treasury/**`, its tests, or `lang/*/treasury.php`.
`apps/web`, `apps/pos`, `database/`, the status machine, the fiscal chain and every GL service are
untouched — verified by `git diff --name-only faf822f8a...HEAD`, which lists 29 files under `apps/api/`
and nothing else.


## 7. Deviation from the brief's test plan (declared)

The brief asks for `PaymentApplicabilityMatrixTest` as "every entry point x all 13 `DocumentType` cases x
{draft, confirmed, posted, cancelled}". Driven through HTTP for all 12 writers that is ~600 `RefreshDatabase` feature
cases, which this machine cannot run under the one-process rule. What shipped instead, and why it is equivalent:

- the **full matrix** (13 x 6 x 3 provenance = 234 cells) is asserted as a pure unit test against the policy object,
  with the expected verdicts restated FROM THE SPEC rather than read off the implementation;
- the **census test** proves every one of the 12 writers reaches the policy object before writing — which is what
  transfers the unit matrix to every entry point;
- the **feature suites** then pin, per entry point, the parts a unit test cannot see: the 422 envelope, the
  machine-readable `reason`, and that no allocation row and no journal entry survive a refusal.

---

## 8. Verification commands run (by path, one process at a time)

```
php artisan test tests/Unit/Treasury/PaymentApplicabilityMatrixTest.php
php artisan test tests/Unit/Treasury/PaymentAllocationWriterCensusTest.php
php artisan test tests/Feature/Treasury/PurchaseOrderAllocationRefusedTest.php
php artisan test tests/Feature/Treasury/HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php
# + the four regression batches in §2, sqlite
# PG: DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_sc0a0 \
#     DB_CENTRAL_DATABASE=autoerp_test_sc0a0 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret php artisan test <path>
./vendor/bin/pint --test app/Modules/Treasury tests/Unit/Treasury tests/Feature/Treasury tests/Feature/Document lang bootstrap/app.php   # {"result":"pass"}
./vendor/bin/phpstan analyse <11 production paths + 5 new/rewritten test paths>                                                          # [OK] No errors
./vendor/bin/deptrac analyse                                                                                                             # 183 violations — IDENTICAL to dev (verified in the main checkout); none name this lane's classes
```

Throwaway PG database `autoerp_test_sc0a0` was created on port 5433 by this lane and dropped at the end.

---
---

# Fix round r1 — response to `2026-08-25-sc-0a0-gate-r1-treasury.md`

**Gate verdict addressed** spec ❌ + quality CHANGES-REQUESTED, merge-blocking, 9 findings.
**Fix commit** `4ee16617c` on `feat/sc-0a0-payment-applicability` (r1 tree was `ffcdd8555`).
**Migration: still NONE.** No `database/` path in the r1 diff either.
**Not merged. Not pushed.**

The gate was right on the thing that matters: r1 fixed a wrong-money path and opened an availability
one in the same commit, and its own residual R-C0a0-2 described that break as benign. It is not benign —
`classifyReceivableSide()` THROWS, and two of the three consumers are queue workers. Everything below
follows from that.

## Per finding

### F-1 [CRITICAL] — auto sweep threw instead of skipping · FIXED, both halves

**(b) the AUTO branch skips; MANUAL and direct keep throwing.**
`PaymentAllocationService.php:240` — the execute loop now branches on WHO CHOSE THE DOCUMENT:
`$command->allocationMethod === MANUAL ? classifyReceivableSide(...) : allocatableTreatmentOrSkip(...)`,
and a `null` verdict `continue`s past the row (`:245-247`). The non-throwing locked-row helper is
`allocatableTreatmentOrSkip()` (`:642-665`), the skip is logged with its reason
(`logAutoAllocationSkip()`, `:667-677`).

**(a) preview and execute agree.** Two changes, because one was not enough:
- `getOpenInvoices()` (`:585-594`) gains the POS-derived exclusion in SQL —
  `reference NULL OR reference NOT LIKE 'POS-ACCOUNT-CHARGE:%'`, built from
  `DocumentAllocationClassifier::POS_ACCOUNT_CHARGE_REFERENCE_PREFIX` so the two cannot drift.
- `previewAllocationForContext()` (`:119-128`) filters the AUTO set through the classifier itself
  (`rejectUnallocatable()`, `:623-636`). This is what makes preview and execute agree BY CONSTRUCTION
  rather than by two hand-kept predicates staying in step — the failure mode the gate found.

**DECLARED DEVIATION from the literal wording of F-1(a).** The disposition says the SQL predicate should
also carry `is_historical = false`. It does not, and must not, now that F-2 lands in the same round:
blanket-excluding historical rows would hide every *collectable* AR opening from the sweep — the identical
availability break, from the other direction. The AR/AP discriminator is not a `documents` column (it lives
in `opening_balance_import_rows`), so SQL cannot decide it; the classifier filter in `rejectUnallocatable()`
does, on the same verdict the execute loop uses. POS-derived IS excluded in SQL because its marker *is* a
`documents` column.

*Proof — `tests/Feature/Treasury/AutoAllocationSkipsRefusedDocumentsTest.php` (new), the gate's leg 9 made
permanent, including the queued-projection entry point called with the exact
`ApplyPaymentAllocationCommand` shape `TreasuryAccountPaymentBridge:216` / `TreasuryDepositBridge:200` use.*

RED (on `ffcdd8555`, sqlite):
```
⨯ the http auto path skips a refused opening and still collects the native invoice   Expected 200, received 422
⨯ the http auto path skips a pos derived invoice and still collects the native ...   Expected 200, received 422
⨯ the auto preview offers exactly what the execute allocates    Failed asserting that an array does not contain '01a0367a-…'
⨯ the queued projection entry point does not throw on a refused opening              (DocumentNotAllocatableException)
✓ the manual path still throws for the same document
⨯ an auto sweep whose whole set is refused returns cleanly                           Expected 200, received 422
Tests:    5 failed, 1 passed (9 assertions)
```
GREEN sqlite: `Tests: 6 passed (21 assertions)` · GREEN PG 5433: 6 passed (inside `Tests: 147 passed (3990 assertions)`).

### F-2 [IMPORTANT] — historical AR openings had no settlement path · FIXED (option (a))

The gate is correct that the discriminator exists on base and that r1's docblock claim was not accurate.
`OpeningBalanceBatchService::addImportRows():200-218` stamps `opening_balance_import_rows.row_type` from
`OpeningBatchType::rowType()` (`AR`/`AP`) at insert, and `markRowsPosted():636-658` claims the row to the
document it minted via `mapped_entity_id`. Neither is rewritten afterwards.

Reached through a NEW Shared contract, never an Accounting model (rule 6):
- `app/Shared/Contracts/Accounting/HistoricalOpeningSide.php` (new enum: `ar` / `ap`)
- `app/Shared/Contracts/Accounting/HistoricalOpeningSideReaderInterface.php` (new)
- `app/Modules/Accounting/Application/Services/HistoricalOpeningSideReader.php` (new impl)
- bound at `app/Providers/AppServiceProvider.php:123`
- consumed at `DocumentAllocationClassifier::refusalForHistoricalOpening()` (`:349-365`), constructor
  dep at `:95-102`.

Rule as implemented (**owner-sheet OQ-74, this is the recorded default**):
| Case | Verdict |
|---|---|
| historical **AR** + `posted` | ADMITTED ⇒ `ReceivableClearing`. The opening JE created the 411; booking it as a 419 advance would invent a liability the company does not owe. |
| historical **AR**, any other live status | Refused `status_not_allocatable_for_type` (an opening is minted `posted`; anything else is unruled) |
| historical **AP** | Refused `historical_opening_provenance` — Dr 401 / Cr bank is C-0a1's route |
| side **unprovable** | Refused `historical_opening_provenance` — fail closed belongs where the evidence is absent, not where nobody looked |
| historical **CreditNote**, either side | Refused (spec rule 4 / OQ-37), unchanged |

`PartnerType` was rejected as the discriminator and the reasoning is in the reader's docblock: `Both` exists
and both `Partner::scopeCustomers()` and `scopeSuppliers()` admit it (`Partner.php:257-271`), so a `both`
partner can hold an opening on either side and the partner row cannot say which.

*Proof — `tests/Feature/Treasury/HistoricalOpeningSideSettlementTest.php` (new).*
RED (on `ffcdd8555`, sqlite):
```
⨯ a historical ar opening can be collected on the direct payment endpoint   Expected 201, received 422
⨯ a historical ar opening is offered and collected by the auto sweep        Expected 200, received 422
✓ a historical ap opening is still refused
✓ a historical opening whose side cannot be resolved is refused
Tests:    2 failed, 2 passed (9 assertions)
```
GREEN sqlite: `Tests: 4 passed` · GREEN PG: 4 passed (inside `Tests: 8 passed (19 assertions)`).
`HistoricalAndPosInvoicesRefusedBeforeProvenanceTest` was updated in step: its `ar_opening` data set became
`unknown_side_opening` (a real AR opening whose import-row link is severed), so the suite still pins four
refused families across five entry points — `Tests: 20 passed (122 assertions)`.

### F-3 [IMPORTANT] — `classifyReceivableSide()` had zero coverage · FIXED

Unit, in `PaymentApplicabilityMatrixTest`: `test_the_receivable_side_seam_refuses_a_payable_settlement()`
(asserts the SI is allocatable via `classify()` and refused via `classifyReceivableSide()` — the distinction
is the point), `test_the_receivable_side_seam_admits_the_ar_documents()` (4 data sets: posted invoice ⇒
clearing, confirmed invoice ⇒ advance, confirmed AND posted sales order ⇒ advance),
`test_the_receivable_side_seam_still_refuses_what_the_matrix_refuses()`.

Feature, in `tests/Feature/Treasury/ReceivableSideSeamTest.php` (new): reaches the seam through
`MultiPaymentService::createSplitPayment()` and `::applyDepositToDocument()` **called directly**, bypassing
the controller pre-guards — which the gate ruled load-bearing and which are NOT removed here. The fixture
mints a posted supplier invoice WITH a posted Cr-401 journal entry, so the refusal cannot be dismissed as
"that document was never payable anyway". Two positive cases pin that the seam did not become a blanket
refusal (posted invoice clears; confirmed invoice books both split lines as advances).

RED (on `ffcdd8555`, sqlite) — the seam fired, with the wrong reason:
```
⨯ the split payment service refuses a posted supplier invoice with the payable reason   Failed asserting that two strings are identical.
⨯ the deposit application service refuses a posted supplier invoice                     Failed asserting that two strings are identical.
✓ the split payment service still admits a posted invoice
✓ the split payment service books a confirmed invoice as an advance
Tests:    2 failed, 2 passed (5 assertions)
```
GREEN sqlite: `Tests: 4 passed` · GREEN PG: 4 passed.

### F-4 [IMPORTANT] — payable refused with credit-note copy · FIXED

New `AllocationRefusalReason::PayableNotSettleableHere` (`AllocationRefusalReason.php:93`), used at
`DocumentAllocationClassifier.php:157-167`. Copy added to `lang/{en,fr,ar}/treasury.php` pointing at the
supplier payment flow. The enum's own docblock records why `OutwardDocumentType` was wrong twice over
(a payable is INWARD money, and its message talks about credit notes). Asserted by the two RED→GREEN cases
above and by the unit seam test.

### F-5 [IMPORTANT] — three discarded `Document::find()` reads inside refund transactions · FIXED

`assertReversalAdmitted(string $documentId)` (`DocumentAllocationClassifier.php:290`) now takes the id the
caller already holds. The three reads are gone: `PaymentRefundService.php:224` (was a `find()` per
allocation inside the reversal loop), `:1325`, `:1980`; `VendorRefundService.php:150` passes `$lockedPo->id`.
`grep -c "Document::query()->find" PaymentRefundService.php` → **0**.

The evidential half is fixed too, in the artefact that makes the claim:
`PaymentAllocationWriterCensusTest::REVERSAL_SEAM_SITES_PREDICATE_DEFERRED` plus
`test_the_reversal_seam_sites_are_declared_as_predicate_deferred()`, which asserts exactly four reversal
calls exist and that only the two declared files use them. The method docblock and both call-site comments
now say **SEAM PRESENT, PREDICATE DEFERRED TO C-0a1** in those words.

GREEN: `tests/Unit/Treasury/PaymentAllocationWriterCensusTest.php` → `Tests: 3 passed (3095 assertions)`
(sqlite and PG). Reversal-writer regression: `PaymentRefundTest` · `PaymentRefundProrationTest` ·
`PaymentReversalNetLineageTest` · `PaymentReversalDocumentTest` · `ProRataResidualRedistributionTest` ·
`VendorRefundScalingTest` · `RefundSpineTest` · `PaymentReversalRefusalTest` → `Tests: 86 passed (392 assertions)`.

### F-6 [MINOR] — handback line table wrong · FIXED, re-derived from `4ee16617c`

Re-derived with `grep -n` on the final tree, not copied:

| File | Symbol | Line |
|---|---|---|
| `Treasury/Domain/Services/DocumentAllocationClassifier.php` | `__construct` (opening-side reader dep) | `:95-102` |
| | `HISTORICAL_OPENING_REFERENCE_PREFIX` · `POS_ACCOUNT_CHARGE_REFERENCE_PREFIX` · `LIVE_STATUSES` | `:108` · `:115` · `:121` |
| | `classify()` | `:126-141` |
| | `classifyReceivableSide()` (F-4 reason at `:157-167`) | `:152-170` |
| | `classifyOrNull()` · `isAllocatable()` | `:177-182` · `:184-187` |
| | `refusalReasonFor()` — rule 1 `:201`, provenance `:205-219`, exhaustive per-type `match` `:221-259` (SalesOrder `:235`, PurchaseOrder `:249`) | `:198-260` |
| | `assertReversalAdmitted(string $documentId)` | `:290-293` |
| | `treatmentFor()` (second exhaustive `match` at `:303`) | `:301-323` |
| | `refusalForHistoricalOpening()` (NEW, F-2) | `:349-365` |
| | `isHistoricalOpening()` · `isPosAccountCharge()` | `:377-384` · `:391-394` |
| `Treasury/Domain/Enums/AllocationTreatment.php` | `case PayableSettlement` · `isReceivableSide()` | `:43` · `:53-59` |
| `Treasury/Domain/Enums/AllocationRefusalReason.php` | 8 cases at `:31`, `:39`, `:47`, `:54`, `:61`, `:70`, `:79`, `:93` (`PayableNotSettleableHere`, NEW) | |
| `Treasury/Domain/Exceptions/DocumentNotAllocatableException.php` | `public readonly AllocationRefusalReason $reason` | `:41` |
| `bootstrap/app.php` | `'message' => __($e->reason->translationKey())` · `'reason' => $e->reason->value` | `:964` · `:970` |
| `Treasury/Application/Services/PaymentAllocationService.php` | ctor dep `:45` · AUTO/MANUAL branch `:240` · write `:250` · SQL provenance predicate `:585-594` · `rejectUnallocatable()` `:623-636` · `allocatableTreatmentOrSkip()` `:642-665` · `logAutoAllocationSkip()` `:667-677` · manual preview `:854` | |
| `Treasury/Application/Services/CloseInvoiceWithToleranceService.php` | ctor `:51` · `classifyReceivableSide()` `:100` (after the already-settled check) · write `:162` | |
| `Treasury/Domain/Services/MultiPaymentService.php` | ctor `:33` · split `:89`→write `:130` · deposit `:306`→write `:308` | |
| `Treasury/Domain/Services/PaymentRefundService.php` | ctor `:75` · seams `:224`, `:1325`, `:1980` → writes `:226`, `:1327`, `:1982` | |
| `Treasury/Domain/Services/VendorRefundService.php` | ctor `:34` · seam `:150` → write `:152` | |
| `Treasury/Presentation/Controllers/PaymentController.php` | ctor `:75` · pre-transaction fail-fast `:603` · locked-row `classify()` `:1045`→write `:1048` · multi-line `:1485`→write `:1639` · manual excess `:1812`→write `:1818` · auto excess `:1895`→write `:1900` | |
| `Treasury/Presentation/Controllers/MultiPaymentController.php` | typed rethrow ahead of the generic arm | `:215`, `:412` |
| `app/Shared/Contracts/Accounting/HistoricalOpeningSide.php` | NEW enum | — |
| `app/Shared/Contracts/Accounting/HistoricalOpeningSideReaderInterface.php` | NEW contract | — |
| `app/Modules/Accounting/Application/Services/HistoricalOpeningSideReader.php` | NEW impl | — |
| `app/Providers/AppServiceProvider.php` | binding (imports `:10`, `:50`) | `:123` |
| `lang/{en,fr,ar}/treasury.php` | `allocation_refused.*` — `payable_not_settleable_here` added, `historical_opening_provenance` copy rewritten for the AP/unprovable case | |

The r1 table's **call-site** lines were exact and are re-derived unchanged above where they did not move.
The gate's assertion-count note is also corrected: the matrix suite is now `Tests: 138 passed (877 assertions)`
after the F-8 rewrite, so the 953/955 discrepancy is moot.

### F-7 [MINOR] — auto-path tests asserted no status code · FIXED

`HistoricalAndPosInvoicesRefusedBeforeProvenanceTest::test_the_auto_allocation_execute_writes_nothing_for_it`
→ `test_the_auto_allocation_sweep_skips_it_without_refusing_the_request`, now asserting `assertOk()` before
`assertNoAllocationWasWritten()`. Its docblock states the gate's point in full: "nothing written" was true
both when the sweep passed over the row and when it threw and rolled the collection back — the status code
is the difference, which is why it is asserted.

The two vacuous PO auto cases are KEPT and explicitly labelled: `PurchaseOrderAllocationRefusedTest`
carries an "HONEST COVERAGE NOTE" recording that the gate proved them green on base AND under a tampered
classifier, so they pin the `getOpenInvoices()` mirror and must not be counted as coverage of the classifier.

### F-8 [MINOR] — matrix oracle was a structural clone · FIXED

`expectedRefusalReason()` is deleted. The oracle is now two LITERAL tables in
`PaymentApplicabilityMatrixTest`: `EXPECTED_NATIVE` (78 cells — every `DocumentType` × every
`DocumentStatus`) and `EXPECTED_PROVENANCE` (48 cells — Invoice/CreditNote × 4 provenance families ×
6 statuses). No shared control flow with the production `match` survives; changing a verdict means editing
a line that names the cell it decides.

Three properties guard the table itself: `test_the_tables_cover_exactly_the_whole_space()` (a missing cell
must FAIL, not silently skip), `test_provenance_markers_do_not_change_any_other_type()` (rules 2–5 are
scoped to the two minted types — this replaces 264 duplicate literal cells with the property they encode),
and `test_the_admitted_set_is_closed_and_exactly_this()`, which the gate named the primary guard and which
is now 18 literal strings.

GREEN: `Tests: 138 passed (877 assertions)` (sqlite and PG).

### F-9 [MINOR] — SalesOrder narrowing contradicted the N-6 gate · FIXED, N-6's ruling restored

`DocumentAllocationClassifier.php:235` is now `DocumentType::SalesOrder => null` — admitted at `confirmed`
OR `posted` (rule 1 has already excluded every non-live status). The docblock no longer claims a narrowing,
and states why: `Posted` IS reachable (`DocumentPostingService::cancelSalesOrder()` guards for exactly that
state; `DocumentStatusMachine` allows `Confirmed → Posted` for every sales-lifecycle type), the N-6 gate
ruled that narrowing below the pre-N-6 rule is a regression, and either way the money is an ADVANCE because
a sales order never carries a 411. Pinned by name in `test_the_cells_this_lane_closes()` and by four new
admitted cells in the closed-set assertion.

## Gate legs re-run after the fix

```
./vendor/bin/pint --test app/Modules/Treasury app/Modules/Accounting app/Shared/Contracts/Accounting \
    app/Providers/AppServiceProvider.php tests/Unit/Treasury tests/Feature/Treasury lang bootstrap/app.php
→ {"result":"pass"}

./vendor/bin/phpstan analyse <11 production paths + 3 new Shared/Accounting paths + AppServiceProvider
                              + 7 new/rewritten test paths>
→ [OK] No errors

./vendor/bin/deptrac analyse   (lane) → Violations 183   ⇒ UNCHANGED vs dev; grep for this lane's classes
                                                            in the violation list → 0 hits
```

Regression, by path, one process at a time (sqlite):

| Batch | Result |
|---|---|
| `N6PaymentOnUnpostedInvoiceTest` · `DocumentPaymentStatusTransitionTest` · `PaymentAllocationDocumentStateTest` · `CloseInvoiceWithToleranceServiceTest` · `Document/CreditNoteAllocationTest` · `VendorPrepaymentRefundTest` · `PurchaseOrderAllocationRefusedTest` | `69 passed (249 assertions)` |
| `PaymentAllocationServiceTest` (+ tolerance contract/persistence) · `SmartPaymentIntegrationTest` · `MultiPaymentSpineTest` · `PaymentControllerSpineTest` · `Unit/Document/DocumentStatusMachineTest` | `54 passed (265 assertions)` |
| the eight refund / reversal suites (F-5 blast radius) | `86 passed (392 assertions)` |
| `ArApOpeningPostLifecycleTest` · `OpeningBalanceBatchLifecycleHardeningTest` · `Reports/AgedOutstandingSourceTest` · `PostingMarkerPrintTest` · `AdvanceReversalRefusalsAndCeilingTest` · `PaymentAllocationPrecisionTest` · `TreasuryEventsTest` (F-2 blast radius: the opening-balance consumers) | `4 skipped, 49 passed (137 assertions)` |

PG 5433 (`autoerp_test_sc0a0`, created by this round and dropped at the end):

| Batch | Result |
|---|---|
| `PaymentApplicabilityMatrixTest` · `PaymentAllocationWriterCensusTest` · `AutoAllocationSkipsRefusedDocumentsTest` | `147 passed (3990 assertions)` |
| `HistoricalOpeningSideSettlementTest` · `ReceivableSideSeamTest` | `8 passed (19 assertions)` |
| `HistoricalAndPosInvoicesRefusedBeforeProvenanceTest` · `PurchaseOrderAllocationRefusedTest` | `27 passed (154 assertions)` |
| `N6PaymentOnUnpostedInvoiceTest` · `DocumentPaymentStatusTransitionTest` · `PaymentAllocationDocumentStateTest` · `CloseInvoiceWithToleranceServiceTest` · `PaymentRefundTest` · `VendorPrepaymentRefundTest` · `ArApOpeningPostLifecycleTest` | `74 passed (272 assertions)` |

The full PHPUnit suite was never run. The repo-global WIP shelf was never touched — `git stash list`
is unchanged (2 pre-existing entries belonging to other sessions).

## Residual disposition after r1

| # | Status |
|---|---|
| R-C0a0-1 (reversal seam total) | **Implementation fixed** per F-5 — no discarded reads, and the census declares the four sites as predicate-deferred. Policy unchanged and still owner-flagged for C-0a1. |
| R-C0a0-2 (`getOpenInvoices()` mirror drift) | **CLOSED.** It was F-1. The mirror is no longer the agreement mechanism — `rejectUnallocatable()` filters preview and `allocatableTreatmentOrSkip()` filters execute, from the same classifier verdict. |
| R-C0a0-3 (auto path returns 200 with no reason) | **Superseded.** The auto path now returns 200 and logs a per-document reason server-side. Surfacing skipped-document reasons in the API RESPONSE is not done — see R-R1-1. |
| R-C0a0-4 / R-C0a0-5 / R-C0a0-6 / R-C0a0-7 | Unchanged; gate accepted each. The older supplier-invoice type guards stay in place (R-C0a0-5), and F-3/F-4 are precisely what makes retiring them safe later. |
| R-GATE-1 (`type_never_allocatable` copy names Expense/Income) | **Addressed in copy**: the enum docblock and the en/fr/ar strings now say expenses and income are settled through their own metadata flags rather than through `payment_allocations`. |

**New residuals opened by this round:**

- **R-R1-1** — the AUTO sweep logs each skipped document and its reason (`logAutoAllocationSkip()`), but the
  API response does not carry them. An operator who collects less than expected sees the amount, not the
  reason. Surfacing a `skipped[]` block is an API-shape change and belongs with the reader lane, not here.
- **R-R1-2** — `HistoricalOpeningSideReader` issues one query per historical document classified. That is
  bounded by the number of openings in an allocation set and is zero for the native path, but it is a
  read inside the allocation transaction. C-PROV0 makes it a column and the reader disappears.
- **R-R1-3** — F-1(a) is implemented WITHOUT the literal `is_historical = false` SQL predicate the
  disposition names, because F-2 landed in the same round and that predicate would hide collectable AR
  openings from the sweep. Flagged for the r2 gate as a deliberate, reasoned deviation, not an omission.
- **R-R1-4** — OQ-74 (historical AR openings collectable on the AR path pre-C-0a1) is recorded here as the
  implemented default and still needs the owner's explicit ruling on the owner sheet.

---
---

# Finisher round r2 — response to `2026-08-25-sc-0a0-gate-r2-treasury.md`

**Gate verdict** spec ⚠ + quality ACCEPT-WITH-CONDITIONS, **merge-blocking NO**, at `4ab709d04`.
Three of the five conditions belong to this lane; conditions 2 (`SalesOrder + posted` ruling) and
3 (OQ-74 onto the owner sheet) are the orchestrator's and were NOT touched here — both are now flagged
inline in §1 so the merge artefact carries them.
**Migration: still NONE. Not merged. Not pushed.**

### Condition 1 / G-1 — handback §1 and §6 restated FROM THE CODE · DONE

§1 and §6 were the only sections the r1 fix commit left untouched, so they described a narrower policy and
a smaller scope than what ships. Both are rewritten in place (each carries a `REWRITTEN AT GATE r2` note):

- **§1** now states the admitted set as **six `(type, status[, provenance])` cells**, derived from
  `DocumentAllocationClassifier` and from the 18 literal strings in
  `PaymentApplicabilityMatrixTest::test_the_admitted_set_is_closed_and_exactly_this()` — including
  `invoice/posted/historical_ar` (row 3) and `sales_order/posted` (row 5), neither of which appeared in the
  r1 text. It explains why 6 cells expand to 18 strings (rules 2–5 are scoped to Invoice/CreditNote, so the
  sales-order and supplier-invoice rows are provenance-invariant). The behaviour-vs-N-6 table is rewritten
  the same way: `SalesOrder` is no longer described as narrowed, and historical AR/AP/unprovable and
  POS-derived each get their own row. The two orchestrator-owned open rulings are marked ⚠ inline.
- **§6** is retitled "six paths outside the brief's declared file boundary" and lists every one with its
  rule-6 justification: `bootstrap/app.php`, the two new `app/Shared/Contracts/Accounting/*` files,
  `HistoricalOpeningSideReader.php` (a READER — explicitly out of scope in the brief, and why F-2 forced
  it), `AppServiceProvider.php`, and the flagged Treasury test whose assertion was inverted. The r1
  claims "one file outside the boundary" and "readers are untouched" are gone, as is the stale
  `bootstrap/app.php:963-977` citation F-6 had already corrected elsewhere. The file list is re-derived
  from `git diff --name-only faf822f8a...HEAD` (29 files, all under `apps/api/`).

Documentation only — no code change.

### Condition 4 / G-3 — `historical_opening_provenance` copy · DONE (three locales, key unchanged)

The r1 copy told the operator to "settle supplier opening balances through the supplier payment flow".
That route does not exist: an AP opening is minted as `DocumentType::Invoice`
(`ArApOpeningService::postBatch()`), and the supplier branch of `PaymentController::store()` is gated on
`$document->type === DocumentType::SupplierInvoice` (`:514`), so following the instruction fails. The copy
now says supplier opening balances **cannot be settled yet** and that support arrives in a later release —
which is what the handback's own F-2 table already said ("C-0a1's route"). Key name unchanged.

- `lang/en/treasury.php` · `lang/fr/treasury.php` · `lang/ar/treasury.php`, `allocation_refused.historical_opening_provenance`.

Pinned so it cannot regress, by two new unit tests in `PaymentApplicabilityMatrixTest`:
- `test_every_refusal_reason_is_translated_in_every_locale()` (en/fr/ar data sets) — every
  `AllocationRefusalReason` case has a non-empty message in every locale, and no locale carries an orphan
  key. This is the guard the namespace never had: an untranslated reason renders as its raw dotted path,
  and that only shows up in production.
- `test_the_ap_opening_refusal_does_not_name_a_route_that_does_not_exist()` — asserts the English string
  does NOT contain "supplier payment flow", with the reason spelled out in the docblock.

### Condition 5 / R-R2-1 — cutover ordering · DONE (handback + classifier docblock)

An AR opening DOCUMENT creates no journal entry of its own. `ArApOpeningService` says so three times —
"No GL entry created (GL was handled by accounting opening)" at `:37`, `:257` and `:427` — it writes
`is_historical`, `balance_due` and a `posted` status and nothing else. The 411 that F-2's re-admitted
collection CREDITS therefore exists only if the tenant ALSO posted an `AccountingOpeningService` opening
batch.

**Runbook ordering: post the accounting opening batch BEFORE collecting against AR open items.** Out of
order, the credit lands against a debit that was never made and the partner's receivable goes negative.

Recorded in two places, because a runbook note nobody reads at the call site is how this gets lost:
- handback §1, as a ⚠ block attached to admitted row 3;
- `DocumentAllocationClassifier::refusalForHistoricalOpening()`'s docblock, immediately beside the
  "AR + posted ⇒ ADMITTED" line that re-enables the path.

Inherited, not introduced — base admitted the same collections — but C-0a0 is what re-enables them.

### Not touched (orchestrator-owned)

- **Condition 2 / G-2** — `SalesOrder + posted` contradicts spec r11 §2.1 rule 7. Flagged ⚠ in §1; the code
  is unchanged (`DocumentAllocationClassifier.php:235`), and it is money-safe either way (both live statuses
  take a Cr 419 advance).
- **Condition 3 / R-R1-4** — OQ-74 onto the owner sheet. Flagged ⚠ in §1.
- **G-4, G-5, G-6, G-7** — recorded by the gate for follow-on lanes; not conditions of this merge, and not
  touched.

### Verification for this round

```
./vendor/bin/pint --test app/Modules/Treasury app/Modules/Accounting app/Shared/Contracts/Accounting \
    app/Providers/AppServiceProvider.php tests/Unit/Treasury tests/Feature/Treasury lang bootstrap/app.php
→ {"result":"pass"}

./vendor/bin/phpstan analyse <the classifier + the 3 lang-adjacent paths + the rewritten unit suite>
→ [OK] No errors

php artisan test tests/Unit/Treasury/PaymentApplicabilityMatrixTest.php      # the i18n guard, by path
→ Tests: 142 passed (939 assertions)
php artisan test tests/Feature/Treasury/HistoricalOpeningSideSettlementTest.php \
                 tests/Feature/Treasury/HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php
→ Tests: 24 passed (134 assertions)                                          # the copy's consumers
```
