# N-6 / B-20 Phase 1 — treasury/GL gate **r2**

**Lane** `fix/campaign-n6-payment-advance` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n6-payment-advance`
**Reviewed commit** `862cd15d3` (merge of `dev`; merge-base with local `dev` = `5969ad886`)
**r1** `docs/superpowers/reviews/2026-08-24-n6-gate-r1-treasury.md` (spec ❌ / CHANGES — C-1, C-2, I-1..I-10, M-1..M-5)
**Fiscal r2** `docs/superpowers/reviews/2026-08-24-n6-gate-r2-fiscal.md` (ACCEPT-with-conditions)
**Date** 2026-08-24 · **Lens** treasury / GL

## VERDICT: spec ✅ (the N-6 edge itself) · quality **CHANGES-REQUESTED**

Every r1 finding I raised is genuinely closed, and I re-proved each one by execution rather than
reading the handback. C-1 and C-2 are real fixes, not paraphrases; the deptrac BLOCKER is gone
(**PASS 183/183**); the taxed end-to-end arc is GL-sound on PostgreSQL.

**But the fix round left one wrong-money hole that the lane itself created and neither gate saw in
r1, and it is reachable from a live button:** `PaymentRefundService::refundPayment()` never consults
`PaymentLedgerPartitionReader`, so refunding an N-6 prepayment posts **Dr 411 / Cr cash** while the
**419 stays credited**. I-3 fixed only the *reversal* half of the partition story. Proven below.
Plus one red test this lane caused and did not declare.

---

## What I verified BY EXECUTION

Method: `git worktree add --detach` onto a scratchpad path at `862cd15d3` with a **copied** vendor
tree (a symlinked `vendor` silently runs the MAIN checkout's `app/`). The lane worktree was never
touched. Throwaway PostgreSQL 16 `autoerp_test_n6t2` + campaign-tenant clone `n6t2_clone`
(127.0.0.1:5433) — **both dropped**. One test process at a time. Tampering only in the scratch
worktree, always reverted.

| # | Probe | Result |
|---|---|---|
| 1 | `tools/deptrac-ratchet.php` on the lane | **`RESULT: PASS`**, TOTAL **183 / 183**, `ModuleDomain on ModuleApplication` back at its baseline **54** ✅ |
| 2 | Lane suites on **sqlite** (N-6, repair, conversion, status machine, PHPStan rule) | `OK (62 tests, 192 assertions)` ✅ |
| 3 | Same + the 2 pgsql-only guards on **PostgreSQL** | `OK (66 tests, 207 assertions)` ✅ |
| 4 | Treasury regression on **PostgreSQL** (`AdvanceReversalGlShape`, `PaymentRefund`, `PaymentReversalDocument`, `MultiPayment`, `DeferredTenderGuards`, `DocumentPaymentStatusTransition`) | `OK (85 tests, 481 assertions)` ✅ (matches the handback exactly) |
| 5 | **Campaign dry-run on a clone** (`--dry-run`, migrations applied to the clone only) | `INV-2026-0003 … entry date 2026-08-23` · `candidates=1 repaired=1 skipped=0`; `journal_entries` where `source_type='payment_advance_reclass'` = **0**; invoice still `paid`; live tenant `documents` md5 **`de36f7c22af1f732079f83c939751f5d` before and after** — nothing written anywhere ✅ |
| 6 | C-1 ground truth on the clone | payment JE is `source_type=customer_payment`, `entry_date=2026-08-23`, `status=posted`; the reported date is now genuinely read from it ✅ |
| 7 | I-2 ground truth on the clone | the payment's lines are `53 Dr 45.506 / 411 Cr 45.506` — the money really is in 411, so the evidence gate passes for the right reason ✅ |
| 8 | Campaign-tenant allocation census (read-only) | exactly one allocation in the whole tenant, `invoice/paid` — no (type,status) pair newly refused by I-5 is in live use there ✅ |
| 9 | **I-5 matrix, enumerated by execution** (13 types × 6 statuses through `classifyOrNull`) | table below; `sales_order` = `prepayment` at confirmed/**posted**/**received**/paid; every unlisted pair `REFUSED` ✅ |
| 10 | **I-3 tamper** — netting hunk deleted from `PaymentLedgerPartitionReader` | `test_a_repaired_payment_reads_as_advance_backed_to_the_reversal_partition` **FAILS** (`'0.000'` vs `'200.000'`, "nothing is left in 411 to reverse") — the pin discriminates ✅ (file restored) |
| 11 | **I-4 emission counts** (own probe, `Event::assertDispatchedTimes`) | prepay→post = **1**; ordinary post→pay = **1**; re-post of a settled invoice = **0** ✅ |
| 12 | **Item 8 — taxed end-to-end on PG** (200.000 HT + 19% = 238.000 TTC, DN linked) | after pay: `status=confirmed 411=0.000 419=-238.000`; after post: `status=paid sealed=y 411=0.000 419=0.000 revenue=-200.000 vatCollected=-38.000 clearingEntries=1`; partner card while open `receivable=0.000 credit=200.000`, after settlement `0.000/0.000`. All values at TND scale 3 ✅ |
| 13 | **Reversal before posting** (`reversePayment` on a fresh N-6 prepayment) | `411=0.000 419=0.000` — correctly unwinds the advance ✅ |
| 14 | **`refundPayment` on a fresh N-6 prepayment** | `411=+200.000 419=-200.000` — **WRONG MONEY** ❌ (finding R2-C1) |
| 15 | **`refundPayment` after a repair** | `411 −200 → 0 → +200`, `419 0 → −200 → −200` ❌ same defect on the repaired population |
| 16 | **R-13 multi-payment repair** (2 × 100.000 on one 200.000 legacy invoice) | payment 0: `arBacked=0.000 advanceBacked=200.000 total=200.000` (≠ its own 100.000 ⇒ reversal belt-1 refuses it forever); payment 1: `arBacked=100.000 advanceBacked=0.000` (reverses an already-discharged 411) ❌ (finding R2-I1) |
| 17 | Adjacent classifier surface on PG | `CloseInvoiceWithToleranceServiceTest::test_calls_tolerance_checker_with_strict_true_for_a2_close` **ERRORS** — `ArgumentCountError`, 4 passed / 6 expected ❌ **lane-caused** (finding R2-C2) |
| 18 | `TreasuryDepositBridgeTest` (9 red in the same run) | `git diff dev...862cd15d3` on both the bridge and its test is **empty** ⇒ **inherited red on `dev`**, not this lane ✅ |
| 19 | Rule 19 drift scan on the fix-round diff (`+` lines matching `(float)`/`parseFloat`/`number_format`/no-arg `getScale()`/`$this->scale()`) | **empty** ✅ |
| 20 | I-8 as shipped | `GeneralLedgerService.php:1800` `$scale = $this->scaleResolver->getScaleSafe($currencyCode, 3)` used for all three ceiling comparisons ✅; `reclassifyCustomerPaymentToAdvance()` at `:436` likewise ✅ |
| 21 | Manifest vs current local `dev` (`b74a1ed3b`) | dev `gated_ceiling 1163` / `Document 79`; lane `1166` / `82`. `git diff <merge-base>..dev -- apps/` is **empty** (docs-only) ⇒ **merge value = 1166 / Document 82**, no conflict ✅ |

### I-5 — the matrix as shipped (measured, not read)

```
invoice        confirmed → prepayment          sales_order   confirmed → prepayment
invoice        posted    → receivable_clearing sales_order   posted    → prepayment
invoice        paid      → receivable_clearing sales_order   received  → prepayment
invoice        draft/cancelled/received → REFUSED            sales_order paid → prepayment
purchase_order confirmed/posted/paid/received → receivable_clearing  (explicit legacy row, R-1)
credit_note · supplier_invoice · supplier_credit_note · delivery_note · return_note ·
quote · expense · income · purchase_rfq · correcting_entry — REFUSED at every status
```
`SalesOrder + Posted` is Cr 419 again ✅. Two unlisted pairs probed: `expense/posted` and
`supplier_credit_note/posted` — both `REFUSED` ✅. Newly-refused pairs are all fail-closed and none
of them is in live use on the campaign tenant (probe 8) or reachable from any Treasury call site I
could find (`grep DocumentType::{Expense,Income,SupplierCreditNote}` across `app/Modules/Treasury/`
returns nothing in production code).

---

# FINDINGS

## CRITICAL

### R2-C1 — Refunding an N-6 prepayment posts `Dr 411 / Cr cash` while the 419 stands. Live route, live button, wrong money on both sides. **Created by this lane.**
`apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:546-596` (`postRefundGlAndMovement`)
· `apps/api/app/Modules/Treasury/Presentation/routes.php:194-200`
· `apps/web/src/features/treasury/PaymentDetailPage.tsx:212`

`refundPayment()` and `partialRefund()` route their GL through `postRefundGlAndMovement()`, which
calls `createPaymentRefundJournalEntry(...)` **unconditionally** — the AR-only shape. The partition
reader is consulted **only** by `postReversalGlAndMovement()` (`:672`, the `reversePayment()` path,
partition read at `:744`). I-3 taught `PaymentLedgerPartitionReader` about the reclass, and that is
correct and verified (probe 10/13) — but it can only help the path that asks it.

Measured on PostgreSQL, fresh N-6 prepayment on a confirmed invoice:

```
AFTER PREPAY:            411=0.000     419=-200.000
AFTER refundPayment():   411=+200.000  419=-200.000    ← 400.000 of misstatement
AFTER reversePayment():  411=0.000     419=0.000       ← the correct arm
```

Before this lane the payment credited 411, so `refundPayment`'s Dr 411 exactly unwound it. **The
lane moved the credit to 419 and left the debit on 411.** The partner now carries a receivable that
does not exist *and* an advance liability that has been paid out in cash. There is no guard: I read
`assertRefundableSubject()` (`:434`) and `assertWithinRefundableBalance()` (`:461`) — neither looks
at the ledger shape. `POST /api/v1/payments/{payment}/refund` is a live `can:payments.refund` route
and `PaymentDetailPage.tsx:212` is a live button.

**Fix (choose one, both small):**
* **Fail closed now (recommended for Phase 1):** in `refundPayment()`/`partialRefund()`, read the
  partition and `throw` a typed refusal when `advanceBacked > 0`, naming `reversePayment()` as the
  supported path. Mirrors the existing A-D6 refusal at `:797-806`, is 6 lines, and cannot mis-state
  money.
* **Or make it correct:** give `postRefundGlAndMovement()` the same partition-driven account
  selection `postReversalGlAndMovement()` already has, plus the A-D3 unconsumed-advance ceiling.
Either way pin it: prepayment → `POST /payments/{id}/refund` ⇒ 419 goes to 0 (or a loud 422), and
411 never moves.

### R2-C2 — The lane widened `CloseInvoiceWithToleranceService::__construct()` 4 → 6 and left its unit test red. Not declared.
`apps/api/app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php:46-53`
· `apps/api/tests/Unit/Treasury/CloseInvoiceWithToleranceServiceTest.php:395-399`

```
ArgumentCountError: Too few arguments to function
  App\Modules\Treasury\Application\Services\CloseInvoiceWithToleranceService::__construct(),
  4 passed in …/CloseInvoiceWithToleranceServiceTest.php on line 396 and exactly 6 expected
```

`dev`'s constructor takes 4 (`toleranceChecker, toleranceService, glService, allocationStateGuard`);
the lane added `DocumentAllocationClassifier` and `DocumentStatusService`. `git diff dev...862cd15d3`
on that test file is **empty**, so the red is unambiguously this lane's. It appears nowhere in the
handback's green table and is not in the R-8 inherited-red list. (For contrast, the 9 red in
`TreasuryDepositBridgeTest` in the same run ARE inherited — both the bridge and its test are
byte-identical to `dev`.)

**Fix:** add the two arguments at `:399` (`$this->app->make(DocumentAllocationClassifier::class)`,
`$this->app->make(DocumentStatusService::class)`), re-run the file, and put the number in the
handback. Then state the inherited `TreasuryDepositBridgeTest` red explicitly rather than leaving a
reviewer to discover both together.

---

## IMPORTANT

### R2-I1 — R-13 is not an attribution nicety: a multi-payment repair corrupts the reversal partition for **both** payments. Merge-blocking for any fleet run.
`apps/api/app/Console/Commands/RepairPaidNeverPostedDocumentsCommand.php:338-347` — one reclass entry
for the **whole** invoice, keyed on `$verdict['paymentIds'][0]`, while `$verdict['amount']` sums the
allocations of **all** payments.

Measured (2 × 100.000 on one 200.000 legacy invoice, PostgreSQL):

```
PAYMENT 0 (100.000):  arBacked=0.000    advanceBacked=200.000   total=200.000
PAYMENT 1 (100.000):  arBacked=100.000  advanceBacked=0.000     total=100.000
```

* Payment 0's partition totals **200.000** against an original of 100.000, so
  `PaymentRefundService.php:770-777` (coverage belt 1) throws *"refusing to reverse a payment whose
  GL we cannot fully account for"* — that payment is **permanently unreversible**.
* Payment 1 reads fully AR-backed and reverses a 411 the repair already discharged, leaving the 419
  standing — precisely the defect I-3 was written to eliminate, reintroduced by the attribution.

The campaign tenant has one payment (verified, probe 8), so the immediate run is safe — but the
command's own docblock at `:53-59` describes fleet-wide invocation through `tenants:run`.

**Fix (minimum, 4 lines):** in `assess()`, when `count($paymentIds) > 1`, return a named SKIP
(*"more than one payment backs this invoice; the reclass would be attributed to one of them — needs
a human"*). **Fix (right):** loop the payments and write one reclass per payment for that payment's
own allocated amount. Either way add the 2-payment test; today nothing exercises it.

### R2-I2 — r1 I-6 was half-closed: the typed exception landed, the FIFO-membership behaviour change still has no test and is not recorded as a residual.
`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:525-549` (the new
`Invoice + Confirmed` arm) with `orderBy('document_date','asc')` at `:544`, driven by
`apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:216` and
`TreasuryDepositBridge.php:200` with `AllocationMethod::FIFO`.

The Domain-exception half is properly done — `Treasury\Domain\Exceptions\DocumentNotAllocatableException`
with a good docblock, rendered in `bootstrap/app.php`; I confirmed there is no `HttpResponseException`
left on that path. But the other half of I-6 stands: a device-authored `ACCOUNT_PAYMENT` /
back-office `DEPOSIT_RECEIPT` now parks cash in 419 against an **older confirmed-but-unposted**
invoice ahead of a genuinely due posted invoice. `git diff d1b88e0da...862cd15d3 --
tests/Feature/Fiscal` shows only `NewSaleServerAuthoringDispositionTest`; no bridge test was added,
and the handback's "I-6" entry describes only the exception.

**Fix:** one projection-level test (bridge + one older confirmed invoice + one posted invoice;
assert which absorbs, and that 419/411 land correctly), **or** record it as a named residual so the
change is visible. Do not leave it as neither.

### R2-I3 — `DocumentAllocationClassifier` is now the single policy object for the entire AR allocation surface and has **no direct test**.
`apps/api/app/Modules/Treasury/Domain/Services/DocumentAllocationClassifier.php` — there is no
`DocumentAllocationClassifierTest` anywhere (`grep -rln DocumentAllocationClassifier tests/` returns
only three consumers). The r2 rewrite changed the outcome of ~50 (type,status) pairs from
`ReceivableClearing` to a refusal, and pinned `SalesOrder + Posted → Prepayment` — the exact I-5
regression — **nowhere**. The only refusal coverage is two cases in
`N6PaymentOnUnpostedInvoiceTest.php:295` and `:306`.

I enumerated the matrix myself (probe 9) and it is correct as shipped. But a policy table that
nobody asserts will drift on the next edit, and the whole point of I-5 was that an unruled pair must
not be silently decided.

**Fix:** a table-driven unit test over `DocumentType::cases() × DocumentStatus::cases()` asserting
the full matrix. It is ~30 lines and it is the cheapest defect insurance in this lane.

---

## MINOR

* **R2-M1** — `RepairPaidNeverPostedDocumentsCommand.php:249-254` selects the payment entry with no
  `status` predicate, while its own SKIP message at `:259` says *"no **posted** customer-payment
  journal entry"*. A payment carrying both a Draft (failed `AfterCommit`) and a Posted entry could
  take its `entry_date` from the Draft. `PaymentLedgerPartitionReader` counts only `Posted`
  (`:150`, `:192`), so the two disagree. Add `->where('status', JournalEntryStatus::Posted)`.
* **R2-M2** — r1 **I-10** (the Document module reaching into Treasury's `PaymentAllocation` Eloquent
  model three new times: `DocumentPostingService.php:190-197`, `:230-232`, `:260-262`) was neither
  fixed nor recorded. R-12 records the `auth()` call site but not this. Either add the
  `Shared\Contracts\Treasury\OpenAdvanceAllocationsInterface` seam or list it as a residual —
  the lane built `CustomerAdvanceClearingInterface` for the Accounting seam in the same file.
* **R2-M3** — `SalesOrderToInvoiceConverter.php:560` still uses the no-arg `$this->scale()` for
  `bcsub($invoiceTotal, $totalPrepaid, …)`. Pre-existing and HTTP-only today, so not rule-19 drift
  from this lane, but it sits four lines from code the lane rewrote and the invoice currency is in
  hand.
* **R2-M4** — `N6PaymentOnUnpostedInvoiceTest.php:158-166`: the VAT assertion is honest about being
  an invariant, and the comment is exemplary, but with `BuildsDeliveryPolicyFixtures` it evaluates
  `'0.000' === '0.000'`. My probe 12 proves the real thing (`vatCollected=-38.000` on a 19% invoice).
  Consider parameterising the fixture's `tax_amount` so the lane owns that proof.

---

## r1 findings — closed / not closed

| r1 | Status at `862cd15d3` |
|---|---|
| **C-1** repair dated on the wrong entry | **CLOSED** — `source_type='customer_payment'` at `:251`, fallback deleted, SKIP at `:256-261`, period lock on `$paymentEntry->entry_date` at `:301`. Two pins, both discriminating; re-verified on real campaign data (probes 5–7) |
| **C-2** stamp before the post | **CLOSED** — `SynchronousInTransaction` at `SalesOrderToInvoiceConverter.php:605-616`, stamp conditioned on a **re-read** `JournalEntryStatus::Posted` at `:686-695`, catch narrowed to re-throw `UnbalancedJournalEntryPostException` at `:625-627`. The REAL converter path is now tested (`it_posts_the_prepayment_clearing_even_without_an_actor_and_only_then_marks_it_cleared`, `it_clears_an_order_prepayment_exactly_once_at_conversion`); imbalance stays loud (`it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud` green on PG) |
| **I-1** deptrac BLOCKER | **CLOSED** — classifier relocated to `Treasury\Domain\Services`; PASS 183/183. **Waiver judged: legitimate.** `CustomerAdvanceClearingInterface` typed on `Document` is the identical shape already at baseline for `DocumentGlCorrectionInterface`/`GlPreflight`/`GlReversal`; the waiver text names the alternatives and honestly says the BLOCKER half was *fixed, not waived*. I would have refused a waiver that absorbed the BLOCKER; this one does not |
| **I-2** no 411 evidence | **CLOSED** — `arBacked` gate at `:279-299` via the same reader the refund path uses; deposit-shaped and partly-backed arms both pinned |
| **I-3** partition blind to the reclass | **CLOSED for `reversePayment`** (netting at `PaymentLedgerPartitionReader.php:101-121`, tamper-discriminating). **NOT closed for `refundPayment`** — see R2-C1 |
| **I-4** no `DocumentFullyPaid` | **CLOSED** — `DocumentPostingService.php:296-324` via `DB::afterCommit`, existing event reused (rule 8). Exactly one emission on each path, none on re-post (probe 11) |
| **I-5** fall-through flipped SalesOrder | **CLOSED** — no `default => ReceivableClearing`; matrix verified (probe 9). Untested, though: R2-I3 |
| **I-6** typed refusal + FIFO test | **HALF-CLOSED** — see R2-I2 |
| **I-7** PHPStan builder hole + false backstop claim | **CLOSED** — `isDocument()` accepts `Builder<…Document>`/`Relation` at `:237-249`; `DocumentStatusPaidBuilderUpdateFixture` expects the error at line 19; the docblock at `:48-57` now says plainly *"the CHECK backstops values, never EDGES"* and lists the three uncovered forms |
| **I-8** no-arg `getScale()` on the ceiling | **CLOSED** — `getScaleSafe($currencyCode, 3)` at `GeneralLedgerService.php:1800` |
| **I-9** four missing tests | **CLOSED** — converter (real), `is_historical`, locked period, 411→419 ledger arc, revenue asserted. VAT caveat = R2-M4 |
| **I-10** Document→Treasury model reach | **NOT ADDRESSED, NOT RECORDED** — R2-M2 |
| **M-1..M-5** | M-1/M-2/M-3/M-4 closed (parity guard `DocumentStatusCheckConstraintParityTest` green on PG); M-5 accepted as R-12 with a stated reason — fine |

## New residuals R-9..R-13 — merge-blocking?

| # | Judgement |
|---|---|
| **R-9** pre-existing forked chains unrepaired | **Not blocking (treasury).** Fiscal lens owns it; the census SQL in the handback is correct and read-only. Nothing in the GL depends on it |
| **R-10** DN/RN chain fixes unpinned | **Not blocking (treasury).** Fiscal |
| **R-11** `CreditNoteDetail.tsx` infers seal-ness from status | **Not blocking (treasury).** No money |
| **R-12** `auth()` in `DocumentPostingService` | **Not blocking.** Advisory actor; the reason is stated in the code, not only in the handback |
| **R-13** multi-payment reclass attribution | **BLOCKING for any fleet `--execute`.** Not an attribution nicety — it corrupts the reversal partition for both payments (proven, probe 16). Promoted to **R2-I1** |

---

## R-5 — the line for the owner

**The reversing-and-re-booking JE pair is the right instrument for this repair, and the lane has now
earned that claim on both riders.** `AccountingService::assertCorrectingEntryIsPostable()` refuses
with `targetHasNoLedgerEntry` on an empty footprint, and a never-posted invoice has exactly that;
the mis-booking lives on the *payment's* entry, not the document's, so `CorrectingEntryService`
structurally cannot express it. What is shipped is not a raw mutation: the original hash-chained
`customer_payment` entry is never touched, and a new balanced, partner-tagged entry
(`source_type = 'payment_advance_reclass'`) states the correction — and it is now **dated on the
payment entry it restates** (r1 C-1, verified on real campaign data) and **visible to the reversal
partition reader** (r1 I-3, tamper-proved). Both riders I attached in r1 are discharged. The only
question left for you is the doctrinal one: whether a ledger correction with no document to attach
to may exist as a bare journal entry, or whether the correcting-entry invariant should be widened so
a **payment** can be a correcting entry's target. Phase 1 does not need that ruling; the
document-per-action programme does. One new caveat before you sign the `--execute`: the command is
safe on the campaign tenant (one payment, one candidate, verified) but **not yet safe fleet-wide**
until R2-I1 lands.

---

## What to fix before merge

1. **R2-C1** — stop `refundPayment()`/`partialRefund()` mis-booking an advance-backed payment: refuse
   loudly when `advanceBacked > 0`, or make it partition-driven like `reversePayment()`. Pin it.
2. **R2-C2** — fix the 2 missing constructor arguments in `CloseInvoiceWithToleranceServiceTest`;
   declare the inherited `TreasuryDepositBridgeTest` red separately.
3. **R2-I1 (R-13)** — SKIP (or correctly split) a multi-payment repair; add the 2-payment test.
4. **R2-I2** — close the FIFO-membership half of I-6 with a projection test, or record it as a residual.
5. **R2-I3** — a table-driven test over the full classifier matrix.
6. **R2-M1..M4** — posted-status predicate on the payment-entry lookup; record or close I-10;
   the two scale/VAT-fixture notes.
7. Re-run this gate. `--execute` on the campaign tenant is safe on items 1/3 grounds **only because
   that tenant has a single payment** — do not generalise the clearance.

**Manifest at merge:** `gated_ceiling` **1166**, `Document` **82** (dev is 1163 / 79 and is docs-only
since the merge-base, so the union is the lane's numbers verbatim).

---

*Gate run in an isolated `git worktree` on a scratchpad path; the lane worktree was never modified.
Throwaway PostgreSQL databases `autoerp_test_n6t2` and campaign clone `n6t2_clone` created and
dropped; the live tenant `tenant01a03028-…` was read-only throughout (`documents` md5 identical
before and after). Never the full suite; one test process at a time.*
