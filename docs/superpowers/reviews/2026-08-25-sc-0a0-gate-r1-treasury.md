# C-0a0 gate r1 — TREASURY lens

**Lane** `feat/sc-0a0-payment-applicability` · worktree `.worktrees/sc-0a0-payment-applicability`
**Reviewed SHA** `ffcdd8555` · **Base** local `dev` `faf822f8a` (post N-6 merge `084a2b062`)
**Date** 2026-08-25 · **Lens** treasury (adversarial, code-grounded) · **Reviewer** treasury-reviewer
**Inputs** `docs/sessions/session-C-lifecycle-2026-08-24/BRIEF-C-0a0-payment-applicability.md` ·
`SPEC-document-lifecycle-dimensions.md` r11 §2.1 (rules 1, 6–9, F-107 fail-closed) ·
handback `docs/superpowers/reviews/2026-08-25-sc-0a0-handback.md` (lane branch)
**Tree discipline** READ-ONLY except two authorised revert-probes and two tamper-probes, each restored;
`git status --short` on the lane is EMPTY at `ffcdd8555` after every probe; `git stash list` unchanged
(2 pre-existing entries belonging to other sessions). Throwaway PG `autoerp_test_sc0a0_gate` created on
5433 and DROPped.

---

## VERDICT: spec ❌ + quality CHANGES-REQUESTED — **MERGE-BLOCKING: YES**

The core fix is real and valuable: paying a purchase order and paying a historical **AP** opening both
booked wrong-direction customer GL on base, and both are now refused (proven by revert-probe below).
But the lane ships a **new, total availability break on the collection side**: the auto (FIFO / due-date)
allocation path now THROWS instead of skipping when a partner carries any historical opening, so nothing
allocates — not even to the native posted invoices behind it — and the same code path runs inside two
**queued fiscal projections** (`TreasuryAccountPaymentBridge`, `TreasuryDepositBridge`), where the throw
dead-letters a device-authored ACCOUNT_PAYMENT after 5 retries. Proven empirically, base vs lane, below.

---

## 1. Verification legs

### Leg 1 — diff surface, migration claim, boundary claims

```
git -C .worktrees/sc-0a0-payment-applicability diff --stat faf822f8a...ffcdd8555
→ 23 files, 2134 insertions(+), 274 deletions(-)
```
- **Migration: NONE — CONFIRMED.** No `database/` path in the diff; no DDL, no backfill.
- **`apps/web` / `apps/pos` untouched — CONFIRMED** (diff touches only `apps/api/**` + `docs/`).
- **Rule 19 (no float on money) — CLEAN.** `git diff … | grep '^+' | grep -Ei '\(float\)|floatval|parseFloat|number_format|getScale\(\)'` → **no matches**. No new money arithmetic; the only
  arithmetic moved is the pre-existing `bccomp`/`outstandingBalance(3)` pair in
  `CloseInvoiceWithToleranceService`.
- **Rule 13 (no `app()` in production) — CLEAN.** The six `+…app(` hits in the diff are all in
  `tests/Feature/Treasury/Concerns/PaymentApplicabilityScaffold.php` and the two new feature suites.
- **Rule 6 (module boundaries) — no new edge.** `git diff … -- apps/api/app | grep -E '^\+use'` yields
  exactly three lines, all intra-Treasury (`AllocationRefusalReason` ×2, `DocumentNotAllocatableException`).
  `App\Modules\Document\Domain\Document` was already imported in `PaymentRefundService` /
  `VendorRefundService` on base.
- **Enums — CONFORMANT.** `AllocationRefusalReason` (7 string-backed cases) and the new
  `AllocationTreatment::PayableSettlement` case; both `match`es are exhaustive with no `default` arm
  (`DocumentAllocationClassifier.php:204-236`, `:273-292`; `AllocationTreatment.php:53-60`).
- **Error envelope — CONFORMANT** and unchanged in shape: `bootstrap/app.php:960-973` still emits
  `{'error': {'code','message','details'}}`, 422. The `{error:{errors}}` validation envelope is not in play here.

### Leg 2 — the census (verified independently, not read off the handback)

```
grep -rn -E "PaymentAllocation::(create|insert|updateOrCreate|firstOrCreate)\s*\(" apps/api/app
```
→ **12 sites / 6 files**, byte-identical to the handback's table:
`CloseInvoiceWithToleranceService:162` · `PaymentAllocationService:218` ·
`MultiPaymentService:130,308` · `PaymentRefundService:229,1333,1991` · `VendorRefundService:151` ·
`PaymentController:1048,1639,1818,1900`.

Blind-spot sweep for shapes the census's regex cannot see — all **empty**:
`->allocations()->create|insert` (only `->sum('amount')` reads), `new PaymentAllocation`,
`PaymentAllocation::query()->insert|update|delete`, `DB::statement/insert` on `payment_allocations`.

Classifier call sites (`grep -rn "allocationClassifier->" apps/api/app`) — **14 calls, every line number in
the handback is exact**: `CloseInvoiceWithToleranceService:100` · `VendorRefundService:149` ·
`PaymentRefundService:226,1330,1988` · `PaymentAllocationService:214,734` · `MultiPaymentService:89,306` ·
`PaymentController:603,1045,1485,1812,1895`. Each precedes its write inside the same method (read, not assumed).

**Non-writer claims verified by reading**, not accepted: `TreasuryReceiptBridge` /
`TreasuryAccountPaymentBridge` / `TreasuryDepositBridge` create `payments` only and reach allocations
through `PaymentAllocationService::applyAllocationFromCommand()`
(`TreasuryAccountPaymentBridge.php:216`, `TreasuryDepositBridge.php:200`).

### Leg 3 — GREEN on the lane (sqlite), by path, one process at a time

```
php artisan test tests/Unit/Treasury/PaymentApplicabilityMatrixTest.php
→ Tests: 238 passed (955 assertions)          [handback says 953 — 2-assertion drift, immaterial]
php artisan test tests/Feature/Treasury/PurchaseOrderAllocationRefusedTest.php
→ Tests: 7 passed (32 assertions)
php artisan test tests/Feature/Treasury/HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php
→ Tests: 20 passed (118 assertions)
php artisan test tests/Feature/Treasury/PaymentRefundTest.php tests/Feature/Treasury/VendorPrepaymentRefundTest.php
→ Tests: 31 passed (110 assertions)           [the reversal writers, after the new ctor dep]
```

### Leg 4 — GREEN on a throwaway PG (127.0.0.1:5433, `autoerp_test_sc0a0_gate`, dropped after)

```
DB_CONNECTION=pgsql … php artisan test tests/Feature/Treasury/PurchaseOrderAllocationRefusedTest.php
→ Tests: 7 passed (32 assertions)   (46.86s)
DB_CONNECTION=pgsql … php artisan test tests/Feature/Treasury/HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php
→ Tests: 20 passed (118 assertions) (74.59s)
DB_CONNECTION=pgsql … php artisan test tests/Feature/Treasury/DocumentPaymentStatusTransitionTest.php \
    tests/Unit/Treasury/CloseInvoiceWithToleranceServiceTest.php \
    tests/Feature/Modules/Document/CloseInvoiceWithToleranceEndpointTest.php
→ Tests: 25 passed (110 assertions) (39.04s)
```
The tolerance endpoint's `returns 422 invalid status when invoice is confirmed not posted` still passes,
so the `57b4dd940` guard reorder did not swallow `INVOICE_NOT_POSTED`.

### Leg 5 — REVERT-PROBE #1 (headline claim: PO payment refused)

Every production file in the diff reverted to base (`git show faf822f8a:<path> > <path>`, 11 files; the
new enum left in place because base code never references it), then the lane's own suite run:

```
php artisan test tests/Feature/Treasury/PurchaseOrderAllocationRefusedTest.php
→ Tests: 5 failed, 2 passed (12 assertions)
  the direct payment endpoint refuses a purchase order   Expected 422, received 201
  the apply deposit path refuses a purchase order        Expected 422, received 200
```
**Red confirmed** — and paying a purchase order really did return **201 Created** on base. Base classifier
row confirmed by source: `git show faf822f8a:…/DocumentAllocationClassifier.php` line 108
`$document->type === DocumentType::PurchaseOrder => AllocationTreatment::ReceivableClearing` with its own
docblock (line 36) calling the row "EXPLICITLY WRONG".

The 2 tests that pass on base (`the auto allocation preview/execute … purchase order`) pass because
`getOpenInvoices()` never listed POs — see F-7.

### Leg 6 — REVERT-PROBE #2 (headline claim: historical AP opening refused)

Same reverted tree:
```
php artisan test tests/Feature/Treasury/HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php
→ Tests: 20 failed (50 assertions)
  direct payment endpoint … historical AP / historical AR / POS confirmed  Expected 422, received 201
  direct payment endpoint … POS posted                                     Expected 422, received 500
  manual preview / execute / apply-deposit (×12)                           Expected 422, received 200
```
**Red confirmed.** The dangerous cell is real: on base an AP opening (`ArApOpeningService::postBatch()`
mints it as `DocumentType::Invoice`, `status = Posted`, `is_historical = true`,
`reference = "Opening Balance Batch: …"` — `ArApOpeningService.php:310-332`) took a 201 through the AR
branch, i.e. Dr bank / Cr 411 for a payable. Fixing that is the lane's real value.

Tree restored (`git checkout -- apps/api`; `git status --short` empty).

### Leg 7 — TAMPER-PROBE #1 (production side: make the classifier admit a PO)

`DocumentAllocationClassifier.php:226` `PurchaseOrder => PurchaseOrderWrongDirection` → `=> null`, plus a
`PurchaseOrder => ReceivableClearing` arm in `treatmentFor()`:
```
php artisan test tests/Feature/Treasury/PurchaseOrderAllocationRefusedTest.php
→ Tests: 5 failed, 2 passed (12 assertions)
```
The feature suite has teeth on 5 of its 7 cases. Restored.

### Leg 8 — TAMPER-PROBE #2 (test side: flip an expected refusal to admission)

`PaymentApplicabilityMatrixTest::expectedRefusalReason()` `PurchaseOrder => PurchaseOrderWrongDirection`
→ `=> null`:
```
php artisan test tests/Unit/Treasury/PaymentApplicabilityMatrixTest.php
→ Tests: 6 failed, 232 passed (931 assertions)
  failing at PaymentApplicabilityMatrixTest.php:83 and :343
```
The oracle has teeth. Restored.

### Leg 9 — **the FIFO probe (this is F-1)**

A disposable feature test (`ZzGateProbeFifoCollateralTest`, added, run, deleted) mints, through the REAL
services, one partner holding: (a) a historical **AR** opening dated 2026-01-01 (oldest ⇒ first in FIFO)
and (b) a **native** posted invoice dated today, then posts a 50.000 payment to
`POST /api/v1/smart-payment/apply-allocation` with `allocation_method: fifo`.

| | HTTP | alloc on opening | alloc on native invoice |
|---|---|---|---|
| **base `faf822f8a`** | **200** `{"success":true,"allocations":[{"amount":"50.000"}],"journal_entry_id":"…"}` | 1 | 0 |
| **lane `ffcdd8555`** | **422** `DOCUMENT_NOT_ALLOCATABLE` / `reason: historical_opening_provenance` | 0 | **0** |

The whole request is refused and rolled back. Nothing is mis-booked — and nothing is collected either.

### Leg 10 — gates

```
./vendor/bin/pint --test app/Modules/Treasury tests/Unit/Treasury tests/Feature/Treasury lang bootstrap/app.php
→ {"result":"pass"}

./vendor/bin/phpstan analyse <11 production paths + 5 new/rewritten test paths>
→ [OK] No errors

./vendor/bin/phpstan analyse tests/Feature/Treasury/DocumentPaymentStatusTransitionTest.php
→ 2 errors, lines 335-336, bcmul argument.type  → INHERITED: identical code at base lines 317-318
  (git show faf822f8a:…/DocumentPaymentStatusTransitionTest.php | grep -n bcmul → 317,318). Not this lane's.

./vendor/bin/deptrac analyse            (lane)          → Violations 183
./vendor/bin/deptrac analyse            (main checkout) → Violations 183   ⇒ UNCHANGED, claim confirmed

php apps/api/tools/feature-lane-manifest-check.php   (cwd = lane worktree)
→ "tests/Feature lane manifest OK — 1408 Feature classes in 74 groups; every group has a disposition;
   every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
   against 1801 test classes across all suites."  exit 0
   (2 pre-existing ⚠ advisories: 70 groups parked behind the F-2 execution-gate flag; 1 group of
   coverage debt. Both inherited, not introduced here.)

php artisan test tests/Feature/Treasury/AdvanceReversalGlShapeTest.php
  main checkout (dev, no lane code) → Tests: 11 failed, 1 passed (28 assertions)
  lane                              → Tests: 11 failed, 1 passed (28 assertions)   ⇒ INHERITED, unchanged
```

### Leg 11 — the two brief-flagged items

**Scope excursion — `bootstrap/app.php`.** The hunk is confined to the existing
`DocumentNotAllocatableException` render arm: `:964` `'message' => __($e->reason->translationKey())`
(was a hardcoded English sentence) and `:970` `'reason' => $e->reason->value` added to `details`. No other
handler, no ordering change. The excursion is **justified and minimal** — the brief demands "typed 422
with an i18n reason key" and this is the only boundary that renders the exception. (Handback cites
`:963,968,977`; actual are `:963` (unchanged), `:964`, `:970` — see F-6.)

**The inverted assertion.** `DocumentPaymentStatusTransitionTest::test_fully_paid_purchase_order_retains_confirmed_status`
at base (verified via `git show faf822f8a:…`, lines 165-184) asserted
`postJson('/api/v1/payments', […PO…])->assertCreated()` — i.e. it pinned that paying a purchase order
SUCCEEDS. Cross-referenced against the base classifier line 108 (`PurchaseOrder => ReceivableClearing`),
the handback's claim is **CORRECT**: the old assertion pinned F-153. Inverting it to
`test_paying_a_purchase_order_is_refused` (lane `:179-202`) is the right move and is documented in place.

---

## 2. Findings

### [CRITICAL] F-1 — the auto (FIFO / due-date) allocation path THROWS instead of skipping, so a single historical opening kills the whole collection — including in two queued fiscal projections

*Where.* `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:214`
(`classifyReceivableSide($document)` inside the execute loop, inside `DB::transaction`), against the
offered set built by `getOpenInvoices():517-555`, which has **no provenance predicate** — it selects
`Invoice+posted | Invoice+confirmed | SalesOrder+confirmed` with
`total > COALESCE(SUM(payment_allocations.amount),0)`.

*Evidence.* Leg 9 above, base vs lane, same fixture: base **200** with the opening allocated; lane **422**
with **zero** allocations, including on the native posted invoice FIFO would have reached next. Historical
openings are `Invoice + Posted + open` by construction (`ArApOpeningService.php:310-332`) and are dated at
the cutover, i.e. **oldest**, so FIFO reaches them FIRST on every affected partner.

*Consequence — three distinct failures, all new:*
1. **HTTP smart-payment auto path** — any partner with an opening balance can no longer be collected from
   at all through FIFO/due-date, and the refusal takes the native invoices down with it.
2. **`TreasuryAccountPaymentBridge:216`** — the device-authored POS `ACCOUNT_PAYMENT` projection creates
   the `Payment` row and then calls `applyAllocationFromCommand(… FIFO …)`. The classifier's
   `DocumentNotAllocatableException` is a plain `DomainException`, so it is NOT the
   `NonRetryableProjectionException` the job special-cases: `ApplyFiscalEventProjectionJob` catches it at
   the generic `catch (Throwable $e)` arm (`:414-421`) and **re-throws for Horizon retry**; with
   `$tries = 5` (`:158`) and exponential backoff it burns ~21 minutes and then `failed()` flips the row to
   `dead_lettered`. A sealed device fiscal fact never projects.
3. **`TreasuryDepositBridge:200`** — same shape for back-office `DEPOSIT_RECEIPT`.

This is exactly the class of defect the treasury lens exists to catch: the lane's own residual R-C0a0-2
describes it as benign ("nothing is mis-booked … the FIFO sweep silently writes nothing", "an operator
sees a document listed as payable and then a refusal"). That characterisation is **factually wrong** —
`classifyReceivableSide()` throws, it does not skip, and two of the three consumers are queue workers.

*Required change (any one, but it must land in THIS lane):*
- teach `getOpenInvoices()` the same predicate the classifier applies (`is_historical = false` AND
  `reference NOT LIKE 'Opening Balance Batch: %'` AND `NOT LIKE 'POS-ACCOUNT-CHARGE:%'`), so preview and
  execute agree and the sweep simply passes over them; **and/or**
- in the AUTO branch only (`AllocationMethod::FIFO` / `DUE_DATE_PRIORITY`), use the non-throwing
  `classifyOrNull()` / `isAllocatable()` and `continue` past a refused locked row, keeping the throwing
  form for MANUAL and direct (operator-chosen) targets.

The manual and direct paths must keep throwing — the operator named that document.

---

### [IMPORTANT] F-2 — historical **AR** openings now have NO settlement path anywhere, and the AR/AP discriminator the lane says does not exist DOES exist today

*Where.* `DocumentAllocationClassifier.php:191-199` refuses BOTH sides of every historical opening;
`:305-312` (`isHistoricalOpening()`) fires on `is_historical === true` OR the batch reference prefix.

*Evidence.* Every entry point refuses (Leg 3, 20/20 green including the `historical AR opening` data set);
Leg 9 shows the auto path refuses too. There is no third route: the AP branch of
`PaymentController::store()` requires `type === DocumentType::SupplierInvoice` (`:514`), and an opening is
minted as `Invoice`.

The lane's justification — "there is no column to tell the two apart yet" (classifier docblock `:60-72`) —
is not accurate. A **durable** discriminator exists on base:
`OpeningBalanceBatchService::markRowsPosted()` writes
`opening_balance_import_rows.mapped_entity_id = <document id>` (`:641-648`) and the row's batch carries
`OpeningBatchType::ArOpenItems | ApOpenItems`; independently, `documents.partner_id → partners.type`
(`PartnerType::Customer | Supplier`) is already on the row. Refusing the AR side is therefore a **choice**,
not a forced fail-closed.

*Consequence.* A first-client cutover imports its open AR as historical openings; from this commit forward
those invoices cannot be collected by any means until C-0a1 ships. Together with F-1 this is a launch
blocker, not a "recoverable refusal an operator can escalate".

*Required change.* Either (a) narrow the fail-closed refusal to the **AP** side (the one that actually
books wrong-direction GL), resolving the side from the existing import-row link or partner type, or
(b) obtain and record an explicit owner ruling that opening-balance collection is intentionally frozen
between C-0a0 and C-0a1, with C-0a1 pinned as a **release co-requisite** of this lane. Do not merge on the
current, unruled reading.

---

### [IMPORTANT] F-3 — `classifyReceivableSide()`, the seam that guards 8 of the 12 writers, has ZERO behavioural test coverage

*Where.* `DocumentAllocationClassifier.php:138-153`; `AllocationTreatment::isReceivableSide()`
(`AllocationTreatment.php:53-60`).

*Evidence.* `grep -rn "classifyReceivableSide\|isReceivableSide" apps/api/tests` returns exactly **one**
hit — the regex string inside `PaymentAllocationWriterCensusTest.php:31`. No test calls either method.
`PaymentApplicabilityMatrixTest` exercises `refusalReasonFor` / `classifyOrNull` / `isAllocatable` /
`classify` only. The distinguishing branch (refuse `PayableSettlement` with `OutwardDocumentType`) is never
executed by any test.

It is also currently **unreachable at runtime**: every AR-only writer already rejects supplier invoices
earlier and with a different code — `PaymentAllocationService::rejectSupplierInvoiceAllocation()` at
`:200` and `:724`, `MultiPaymentController::rejectSupplierInvoice()` at `:174` and `:387`,
`PaymentController::rejectSupplierInvoiceInMultiline()` at `:1478` and `:1806`. So the seam is dead code
whose behaviour has never been proven — and R-C0a0-5 explicitly anticipates removing the older guards.

*Required change.* Add unit cases over `classifyReceivableSide()` for at least
`SupplierInvoice+posted` (refused) and `Invoice+posted` / `Invoice+confirmed` / `SalesOrder+confirmed`
(admitted, correct treatment), plus one feature case that reaches it through
`MultiPaymentService::createSplitPayment()` with the controller pre-guard bypassed or removed.

---

### [IMPORTANT] F-4 — a payable settlement is refused with `outward_document_type`, whose operator copy talks about credit notes

*Where.* `DocumentAllocationClassifier.php:143-149` throws
`AllocationRefusalReason::OutwardDocumentType` when `! $treatment->isReceivableSide()`, i.e. for a
**posted supplier invoice**. `lang/en/treasury.php` renders that key as *"Credit notes are money owed to
the other party: apply them to another document or refund them, rather than receiving a payment against
them."* (fr/ar are equivalent.)

*Consequence.* Both halves of the refusal are wrong for this case: the machine-readable `reason` the
frontend routes on says a supplier invoice is an "outward document type" (it is an inward payable), and the
message tells the operator about credit notes. This defeats the stated purpose of the enum
(`AllocationRefusalReason` docblock: "one code behind several different remedies is an operator dead end").

*Required change.* Add a distinct case, e.g. `PayableNotSettleableHere = 'payable_not_settleable_here'`,
with en/fr/ar copy pointing at the supplier-payment flow, and use it in `classifyReceivableSide()`.

---

### [IMPORTANT] F-5 — `assertReversalAdmitted()` is a no-op, but three new unguarded `Document::find()` reads were added inside refund transactions to satisfy a grep

*Where.* `DocumentAllocationClassifier.php:260-263` — the whole body is `unset($document);`.
Callers: `PaymentRefundService.php:220-226` (per allocation, inside the reversal loop), `:1324-1330`
(per document), `:1979-1988` (per document); `VendorRefundService.php:149`.

*Consequence.* Two separate costs. (a) Runtime: three `Document::query()->find()` round-trips per touched
document, executed inside `DB::transaction` on the refund path, whose results are discarded. (b) Evidential:
`PaymentAllocationWriterCensusTest`'s headline assertion ("every writer of `payment_allocations` is
classified first") is **vacuous for 4 of the 12 sites** — it matches a method name that does nothing. The
handback's §4 census table presents all 12 rows as equivalent; they are not.

Note this is **not** a new wrong-money path — base behaviour is preserved exactly, and the reasoning for a
total reversal admission (do not strand cash on documents this lane just stopped admitting) is sound.

*Required change.* Either give the seam a real predicate (the "target already carries a live allocation"
rule the docblock names) or drop the three `Document::find()` reads and let the method take the ids it
already has; and mark the four reversal rows in the census table as *seam present, predicate deferred*.

---

### [MINOR] F-6 — the handback's `file:line` table is systematically wrong for the classifier and for `bootstrap/app.php`

Re-derived from `ffcdd8555`:

| Handback claims | Actual |
|---|---|
| `refusalReasonFor()` :175-232, rule 1 :178, provenance :185-194, match :199-231 | `:181-237`, rule 1 `:184`, provenance `:191-199`, match `:204-236` |
| `classify()` :117-131 · `classifyReceivableSide()` :142-157 · `classifyOrNull()` :164-169 · `isAllocatable()` :171 | `:112-127` · `:138-153` · `:160-165` · `:167` |
| `assertReversalAdmitted()` :255-258 · `treatmentFor()` :267 | `:260-263` · `:271` |
| `isHistoricalOpening()` :288 · `isPosAccountCharge()` :303 · constants :96,:103 | `:305` · `:319` · `:94`,`:101` |
| `AllocationTreatment.php:44` new case · `isReceivableSide()` :55-61 | `:43` · `:53-60` |
| `bootstrap/app.php:963,968,977` | `:964` (message), `:970` (reason); `:963` is the unchanged `code` line |

Every **call-site** line in §3/§4 is exact; only the classifier-internal and bootstrap citations drift. The
brief requires "file:line of every production change", so the table needs re-deriving before this handback
is filed as the lane record.

Also: matrix-test assertion count is **955** on this machine, not the 953 recorded.

---

### [MINOR] F-7 — the auto-path tests assert no status code, which is precisely what hid F-1

*Where.* `HistoricalAndPosInvoicesRefusedBeforeProvenanceTest.php:156-166`
(`test_the_auto_allocation_execute_writes_nothing_for_it`) fires the request and then asserts only
`assertNoAllocationWasWritten()`; the response is discarded. Its own docblock and the handback describe the
behaviour as the sweep "writing nothing", when it is in fact a 422 that rolls back the entire request.

The two PO auto tests are additionally **vacuous with respect to this lane**: they passed on base
(Leg 5) *and* under a classifier tampered to admit purchase orders (Leg 7). They pin the `getOpenInvoices()`
SQL mirror, not the change under review — which is fine, but they must not be counted as coverage of it.

*Required change.* Assert the status code and the reason on the auto path, and re-title the test to state
what actually happens once F-1 is fixed (skip, or refuse).

---

### [MINOR] F-8 — the matrix oracle is a structural clone of the implementation

`PaymentApplicabilityMatrixTest::expectedRefusalReason()` (`:287-334`) reproduces
`DocumentAllocationClassifier::refusalReasonFor()` (`:181-237`) arm for arm, in the same order, with the
same comment markers. The claim "restated FROM THE SPEC, never read off the implementation" is a matter of
authorship intent that the artefact cannot evidence: any future edit will be applied to both in one pass.

The genuinely independent oracle is `test_the_admitted_set_is_closed_and_exactly_this()` (`:127-152`) —
eight literal strings, no shared control flow. That test is what actually pins the policy; keep it and
consider it the primary guard.

---

### [MINOR] F-9 — `SalesOrder` narrowed to `confirmed` contradicts the N-6 gate's explicit prior ruling

The base classifier docblock (`git show faf822f8a:…:101-105`) states: *"`Posted` IS reachable
(`DocumentPostingService::cancelSalesOrder()` guards for exactly that state), and the pre-N-6 rule was a
pure type test — anything narrower here is a regression, not a fix."* C-0a0 makes it narrower.

`DocumentStatusMachine::salesLifecycleTargetsOf()` (`:120-141`, `Confirmed` arm `:127-132`) does allow `Confirmed → Posted` for every
sales-lifecycle type, and `DocumentPostingService::cancelSalesOrder():441-446` still guards `isPosted()`, so
the state is at least representable. Whether any live route posts a sales order — **cannot verify**; I found
no SO-specific posting caller.

Spec r11 rule 7 supports the lane's narrowing, so this is a spec-vs-prior-gate conflict rather than a
defect. It should be recorded as such (and the N-6 docblock sentence removed rather than left contradicting
the code), not flipped silently.

---

## 3. Residual disposition (R-C0a0-1 … 7)

| # | Handback position | Gate ruling |
|---|---|---|
| R-C0a0-1 | reversal admission total by design, defer to C-0a1 | **ACCEPTED as policy, REJECTED as implementation** → F-5. Not a new wrong-money path (base behaviour preserved), but the dead `Document::find()` reads and the vacuous census rows must go or gain a predicate. |
| R-C0a0-2 | `getOpenInvoices()` mirror drifts; "nothing is mis-booked", operator just sees a refusal; **out of scope** | **REJECTED — this is F-1, CRITICAL and merge-blocking.** The characterisation is wrong: the execute loop throws, so the entire request (and two queued projections) fail, not just the one row. Must be fixed in this lane. |
| R-C0a0-3 | auto path returns 200 with zero allocations, no reason | **PARTLY WRONG.** True only when the offered set is empty (the PO case). When a refused document IS offered (every opening), the path returns 422 and rolls back — see Leg 9. Folded into F-1. |
| R-C0a0-4 | other `catch (\Exception)` arms in `MultiPaymentController` flatten typed refusals | **ACCEPTED as out of scope.** Verified: the two allocation paths are fixed and correctly ordered (`:215` before `:224`; `:412` before `:421`); the remaining arms at `:348`, `:470`, `:525`, `:570`, `:604` sit on `recordDeposit` / `getUnallocatedBalance` / `recordPaymentOnAccount` / `getPartnerAccountBalance` / `validateSplit`, none of which write `payment_allocations`. |
| R-C0a0-5 | `rejectSupplierInvoiceAllocation()` etc. now redundant, left in place | **ACCEPTED**, and it is load-bearing: those older guards are the only reason F-3/F-4 are not user-visible today. Do not remove them until `classifyReceivableSide()` has coverage and a correct reason code. |
| R-C0a0-6 | `AdvanceReversalGlShapeTest` 11-failed inherited red | **CONFIRMED.** 11 failed / 1 passed on the main `dev` checkout (no lane code) and 11 failed / 1 passed on the lane — identical. Not caused by this lane. |
| R-C0a0-7 | PHPStan red in 5 untouched Treasury test files + `DocumentPaymentStatusTransitionTest:335-336` | **CONFIRMED for the file this lane edited**: the 2 `bcmul argument.type` errors are in the pre-existing `createDocument()` helper, present verbatim at base lines 317-318. The other 5 files are absent from the diff. All 16 touched paths are `[OK] No errors`. |

New residual opened by this gate: **R-GATE-1** — `AllocationRefusalReason::TypeNeverAllocatable`'s copy
("This document type never carries a balance a payment could settle") is emitted for `Expense` and
`Income`, which DO carry a paid/received metadata flag (spec §1.3). Cosmetic; not blocking.

---

## 4. Manifest note

`php apps/api/tools/feature-lane-manifest-check.php` run with cwd = the lane worktree:
**OK, exit 0** — 1408 Feature classes in 74 groups, every group dispositioned, every declared lane present
in `ci.yml`, every `--filter` anchored and uniquely matched across 1801 test classes. The two ⚠ advisories
(70 groups parked behind the F-2 execution-gate flag; 1 group of coverage debt) are inherited and identical
on the main checkout. The lane's two new Feature classes are absorbed without a manifest edit.

---

## 5. What to fix before merge

**Fix F-1 (the auto path must skip, not throw — and the two fiscal-projection bridges must not dead-letter),
then get an owner ruling or a narrowing for F-2 (historical AR openings currently have no settlement path
at all); F-3/F-4/F-5 should land in the same round.**

---

## VERDICT: spec ❌ + quality CHANGES-REQUESTED — **MERGE-BLOCKING: YES**
