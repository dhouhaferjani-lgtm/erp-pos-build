# N-6 / B-20 Phase 1 — treasury/GL gate **r3**

**Lane** `fix/campaign-n6-payment-advance` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n6-payment-advance`
**Reviewed commit** `36e1638c9` (fix round `2cbb32e82` `55ab31e5a` `8c9e51973` `7266c6b73` `9679c0047`; fiscal condition round `1cf556d06` `5e14fa82e` `76ce57af1`)
**Prior** r1 `2026-08-24-n6-gate-r1-treasury.md` · r2 `2026-08-24-n6-gate-r2-treasury.md` (CHANGES: R2-C1, R2-C2, R2-I1, R2-I2, R2-I3)
**Date** 2026-08-25 · **Lens** treasury / GL

## VERDICT: spec ✅ · quality **APPROVED-WITH-CONDITIONS**

**merge-blocking: NO** — with one condition that must be discharged in the merge commit (finding 4, the manifest
number), and one Important finding (finding 1) that I am content to see land as a **named residual** rather than a
fix, because it is a pre-existing belt-bypass the lane merely started using, not new wrong money.

All five r2 findings are genuinely closed and I re-proved each by execution, including four tamper red-proofs. The
R2-C1 CRITICAL is gone: the live refund route now refuses an advance-backed payment at 422 **before any write**, on
BOTH arms (`refundPayment` and `partialRefund` — the second is pinned by no test, but I probed it directly and it
refuses). The GL end-to-end arc on PostgreSQL is textbook. deptrac **PASS 183/183**, PHPStan level 8 whole-project
`[OK] No errors`, pint pass.

---

## What I verified BY EXECUTION

Method: `git worktree add --detach` at `36e1638c9` onto a scratchpad path with a **copied** (not symlinked) vendor
tree. The lane worktree was never modified (`git status --porcelain` empty at start and end). Throwaway PostgreSQL 16
`autoerp_test_n6t3` (127.0.0.1:5433) — **created and dropped**; scratch worktree removed. One test process at a time;
never the full suite. All tampering was in the scratch worktree and reverted (scratch `git status` empty before removal).

| # | Probe | Result |
|---|---|---|
| 1 | `tools/deptrac-ratchet.php` | **`RESULT: PASS`** — TOTAL **183 / 183**, every category `held` ✅ |
| 2 | `phpstan analyse` whole project, live-DB env | **`[OK] No errors`** ✅ |
| 3 | `pint --test` | `{"result":"pass"}` ✅ |
| 4 | `tools/feature-lane-manifest-check.php` | `OK` — **1167** parked classes = declared `gated_ceiling` ✅ |
| 5 | **R2-C2** `CloseInvoiceWithToleranceServiceTest`, sqlite | `Tests: 11 passed (44 assertions)` ✅ exactly the required 11/44 |
| 6 | same on **PostgreSQL** | `11 passed (44 assertions)` ✅ |
| 7 | Lane suite, sqlite (N-6, repair, matrix, A-D refusals) | `119 passed (415 assertions)` ✅ |
| 8 | 14-file treasury+bridge run on **PostgreSQL** | **`9 failed, 254 passed (1081 assertions)`** — all 9 are `TreasuryDepositBridgeTest` `ArgumentCountError` (3 passed / 4 expected) |
| 9 | **Inherited-red proof** | `git rev-parse` on BOTH blobs: `TreasuryDepositBridge.php` lane `2e5693c6b…` = dev `2e5693c6b…`; `tests/Feature/Fiscal/TreasuryDepositBridgeTest.php` lane `8784a0a23…` = dev `8784a0a23…` — **byte-identical**. An `ArgumentCountError` depends only on the ctor signature in one file and the call in the other, so the red is `dev`'s, verbatim ✅ |
| 10 | **TAMPER — R2-C1** (both `assertNotAdvanceBacked($original)` calls deleted) | `test_refunding_a_prepayment_is_refused_and_moves_no_money` **FAILS**: `Expected response status code [422] but received 201` — the pin discriminates, and 201 confirms the live route really was reachable ✅ (restored) |
| 11 | **TAMPER — R2-I1** (`paymentId: $leg['paymentId']` / `amount: $leg['amount']` → the r1 aggregate) | `test_a_two_payment_invoice_…both_stay_reversible` **FAILS**: `payment … must carry its OWN reclass entry — Failed asserting that 2 is identical to 1` ✅ (restored) |
| 12 | **TAMPER — R2-I3** (classifier `SalesOrder => ReceivableClearing`) | matrix **5 failed / 75 passed** — `sales order + confirmed/posted/paid/received` + `the pairs that cost a finding` ✅ (restored) |
| 13 | **TAMPER — R2-I2** (the `Invoice + Confirmed` arm removed from `getOpenInvoices()`) | FIFO test **FAILS** at the membership assert ✅ (restored) |
| 14 | **PROBE — `partialRefund()` on an advance-backed payment** (the arm no test covers) | `DomainException :: payment … has 200.000 booked as a customer advance (419) … Reverse the payment instead`; `411 0.000 → 0.000`, `419 -200.000 → -200.000` — **refuses, writes nothing** ✅ |
| 15 | **PROBE — ordinary AR refund unchanged** (payment on a POSTED invoice, live route) | `status=201`, `411 0.000 → +200.000`, `419 0.000 → 0.000` — the AR shape is byte-for-byte the pre-lane behaviour ✅ |
| 16 | **PROBE — the bridge write path** `applyAllocationFromCommand(FIFO)` onto a confirmed invoice | `411=0.000 419=-200.000 status=confirmed` ✅ (the half the shipped R2-I2 test does NOT reach — see finding 3; the behaviour is correct) |
| 17 | **PROBE — 2-payment repair, full arc on PG** | before `411=-200.000 419=0.000`; after `411=0.000 419=-200.000`; reclass entries first run **2**, second run **2** (idempotent); payment 0 `arBacked=0.000 advanceBacked=100.000 total=100.000` date `2026-08-24`; payment 1 same **with its own date `2026-08-23`**; then `reversePayment` on **both** → `OK` / `OK`, ending `419=0.000 411=0.000` ✅ R2-I1 fully discharged |
| 18 | **PROBE — end-to-end GL replay on PG**, journal lines dumped | `advance`: Dr **512** 238.000 / Cr **419** 238.000 · `prepayment_application`: Dr **419** 238.000 / Cr **411** 238.000 · `Document`: Dr **411** 238.000 / Cr **707** 200.000 / Cr **4375** 38.000. After post `status=paid sealed=y 411=0.000 419=0.000 clearingEntries=1` ✅ |
| 19 | Rule-19 scan on the r2 fix round AND the fiscal condition round (`+` lines matching `(float)`/`(double)`/`parseFloat`/`Number(`/`number_format`/`floatval`/no-arg `getScale()`/`$this->scale()`) | **empty on both** ✅ |
| 20 | **PROBE — `$entry->lines()->delete()` on a POSTED, CHAINED entry** (the exact call R2-F5 ships) | model delete → `ImmutableJournalEntryException` ✅; **mass delete → SUCCEEDED, `lines remaining=0`** ❌ finding 1 |
| 21 | `ClearCustomerAdvanceOrphanDraftTest` + `DocumentStatusCheckConstraintParityTest` on PG | `2 passed (11 assertions)` ✅ |
| 22 | Manifest union vs **current** `dev` (`b339a8211`) | dev `gated_ceiling` **1164**, `Document` **79**; lane **1167** / **82**; `git diff HEAD...dev --stat -- apps/` is **EMPTY** ⇒ union = the lane's numbers verbatim ✅ (see finding 4) |

### R2-I3 — five pairs hand-checked against the design note

The design note is the `DocumentAllocationClassifier` docblock (`…/Treasury/Domain/Services/DocumentAllocationClassifier.php:33-52`) plus handback R-1. `13 DocumentType::cases() × 6 DocumentStatus::cases() = 78` — the count is right.

| pair | docblock says | test's derived rule | classifier | ✓ |
|---|---|---|---|---|
| `invoice + posted` | `Invoice + Posted/Paid → ReceivableClearing` (:33) | `ReceivableClearing` | `:94-95` | ✅ |
| `invoice + confirmed` | `Invoice + Confirmed → Prepayment` — THE N-6 EDGE (:34) | `Prepayment` | `:98-99` | ✅ |
| `sales_order + received` | `SalesOrder, any live status → Prepayment` (:35) | `Prepayment` (non-draft/cancelled) | `:105` | ✅ |
| `purchase_order + received` | `PurchaseOrder, any live status → ReceivableClearing`, explicitly-wrong legacy row R-1 (:36-43) | `ReceivableClearing` | `:108` | ✅ |
| `invoice + received` | not listed ⇒ `everything else → refusal` (:44) | `null` | `default => null` inside the Invoice arm | ✅ |

Two more for the refusal families: `credit_note + posted` and `supplier_invoice + posted` → `null` (:90-91, docblock :48-52) ✅.

---

# FINDINGS

## IMPORTANT

### 1. `R2-F5`'s cleanup uses a mass-delete that BYPASSES the journal-line immutability observer. Proven. Narrow window, silent consequence.
`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1901-1917` (added by the fiscal condition round `5e14fa82e`)

```php
try {
    $this->postEntryNow($entry, $user, $currencyCode);
} catch (\Throwable $postFailure) {
    try {
        $entry->lines()->delete();   // ← relation-builder mass delete: NO model events
        $entry->delete();            // ← model delete: observer fires, refuses if chained
    } catch (\Throwable $cleanupFailure) { … }
    throw $postFailure;
}
```

**The GL question — is deleting the draft sound?** Yes. The entry is `Draft`, unchained, and contributes nothing to any
balance (every reader I checked, including `PaymentLedgerPartitionReader::debitTotal/creditTotal` at
`…/Accounting/Application/Services/PaymentLedgerPartitionReader.php:145` and `:190`, filters `status = Posted`). The
comment's reasoning about the released SAVEPOINT is correct, the original exception is always re-thrown, and
`ClearCustomerAdvanceOrphanDraftTest` pins it green on PG. **That half is right.**

**The defect is the belt it steps around.** `$entry->lines()->delete()` on a `HasMany` query builder issues a mass
`DELETE` and fires no model events, so `JournalLineObserver::deleting()`
(`…/Accounting/Domain/Observers/JournalLineObserver.php:71-84`) never runs. I proved this on PostgreSQL against a real
posted, hash-chained invoice entry:

```
PROBE6 entry status=posted chained=YES lines=2
PROBE6 model delete: App\Modules\Accounting\Domain\Exceptions\ImmutableJournalEntryException
PROBE6 mass delete: SUCCEEDED (guard BYPASSED)  lines remaining=0
```

The order then matters: lines go first, and only *then* does `$entry->delete()` consult
`JournalEntryObserver::deleting()` (`:43-48`), which refuses a chained entry — and that refusal is **swallowed by the
inner catch and downgraded to a `Log::warning`**. Outcome in that case: a **posted, hash-chained journal entry with
zero lines**, still in the chain, contributing nothing, with only a log line to find it by.

**Honest reachability.** `sealAndPersistEntry()` chains the entry at its final statement
(`GeneralLedgerService.php:3721` `$entry->update([... 'fiscal_hash' => $hash ...])`); after it only a DTO construction
and a `DB::afterCommit()` registration remain, so a throw *after* chaining is unlikely — I did not find a live path
that does it. This is a **defensive** finding, not a live wrong-money one. But the catch is `\Throwable`, the cleanup
is unconditional, and the failure mode is silent GL corruption, which is exactly the class of thing that must not
depend on "nothing throws there today".

**Fix (3 lines, no behaviour change on the real path):**
```php
} catch (\Throwable $postFailure) {
    try {
        if ($entry->fresh()?->status === JournalEntryStatus::Draft) {   // re-read: never strip a chained entry
            $entry->lines->each->delete();                              // MODEL delete: the observer belt applies
            $entry->delete();
        }
    } catch (\Throwable $cleanupFailure) { … }
    throw $postFailure;
}
```
Then extend `ClearCustomerAdvanceOrphanDraftTest` with the negative arm: force a post failure *after* chaining and
assert the entry keeps its lines and the original exception still surfaces.

**Merge-blocking: no.** Acceptable to land as a named residual (`R-17`) if the parent prefers — but it must be
*recorded*, not left silent, because probe 20 shows the bypass is real and the lane is what started calling it.

---

## MINOR

### 2. `R2-M1` (r2) is still open and undeclared: the payment-entry lookup has no `status = Posted` predicate while its own SKIP message says "posted".
`apps/api/app/Console/Commands/RepairPaidNeverPostedDocumentsCommand.php:292-303`

```php
$paymentEntry = JournalEntry::query()
    ->where('company_id', $invoice->company_id)
    ->where('source_type', self::CUSTOMER_PAYMENT_SOURCE_TYPE)
    ->where('source_id', $paymentId)
    ->orderBy('entry_date')
    ->first();          // ← no ->where('status', JournalEntryStatus::Posted)
…
'reason' => 'SKIP — no posted customer-payment journal entry found …'
```
The r2 fix round correctly narrowed this from `whereIn($paymentIds)` to `where($paymentId)` — but did not add the
status predicate the r2 review asked for. `PaymentLedgerPartitionReader` counts **only** `Posted` (`:145`, `:190`), so
a payment carrying both a Draft (failed `AfterCommit`) and a Posted entry can take its **reclass date** from the Draft
while its **evidence** comes from the Posted one. Two dates, one repair.
**Fix:** add `->where('status', JournalEntryStatus::Posted)` at `:296`. One line.

### 3. `R2-I2`'s test proves the two halves separately, and the handback claims more than it delivers.
`apps/api/tests/Feature/Treasury/N6PaymentOnUnpostedInvoiceTest.php:491-536` · handback §R2-I2

The membership half is genuine and shares code with the bridges: `previewAllocation()` delegates to
`previewAllocationForContext()` (`…/Treasury/Application/Services/PaymentAllocationService.php:78-85`), which is the
same helper `applyAllocationFromCommand()` calls at `:160`, and both reach the same `getOpenInvoices()` at `:111`.
Tamper probe 13 confirms it discriminates.

The **ledger** half does not. `payFull()` at `:577-590` posts to `/api/v1/payments`, and `PaymentController` runs its
own classify/GL branch (`PaymentController.php:1031`, `:1471`) — **not** `applyAllocationFromCommand()`'s N-6 bucket
accumulator at `PaymentAllocationService.php:174-212`, which is what `TreasuryAccountPaymentBridge.php:216` and
`TreasuryDepositBridge.php:200` actually execute. The in-test comment *"Driven through the same execute path the
bridges use"* (`:527`) is inaccurate, and no test anywhere drives `applyAllocationFromCommand()` onto a
`Confirmed` invoice (`grep` over `tests/` returns 6 call sites, none of them prepayment-shaped).

**I probed the uncovered path myself and it is CORRECT** (probe 16: `411=0.000 419=-200.000 status=confirmed`), so
this is a coverage/claim gap, not a defect. Also note `test_fifo_offers…`'s last assertion — *"the genuinely due POSTED
invoice keeps its receivable untouched"* — is weaker than it reads: `$newer` is `forceFill`-ed to `Posted` and never
GL-posted, so its 411 was 0 before and after. It still discriminates (a wrong classify would drive 411 to `-238`), but
the sentence overstates it.
**Fix:** either correct the comment at `:527`, or (better, ~15 lines) add the bridge-shaped assertion using
`applyAllocationFromCommand(AllocationMethod::FIFO)` — my probe is the recipe.

### 4. The handback's manifest row says **1166**; the committed manifest says **1167**. Use 1167. **Condition on the merge commit.**
`apps/api/tests/feature-lane-manifest.json` (`gated_ceiling`) · handback §Gates

The handback gate table reads *"1166 parked = declared ceiling (Document 82) — unchanged"*. The file itself was
re-taken at the 2026-08-25 dev merge and now says `1167`, and its own note documents the arithmetic (`dev's 1164 +
this lane's +3`). I ran the checker on the lane: `1406 Feature classes in 74 groups … 70 group(s) / **1167** class(es)
are laned but not yet running` — **1167 is the correct number**, the handback prose is stale.

Union arithmetic re-verified against **current** `dev` `b339a8211`: dev is `gated_ceiling 1164` / `Document 79`, and
`git diff HEAD...dev --stat -- apps/` is **EMPTY** (dev's 7 commits since the merge-base are docs/reviews only). So
the union is the lane's numbers verbatim: **`gated_ceiling` 1167, `Document` 82**.

⚠ **Merge-order caveat:** `docs/superpowers/reviews/2026-08-24-w27-batch-gate-r2-inventory.md`'s successor (dev commit
`e63f5d3e2`) projects W2-7 at **1168 / Company 31**. Whichever of N-6 and W2-7 merges second must re-take the union.
State **1167 / Document 82** in the N-6 merge commit if N-6 goes first.

### 5. `R2-M2` (r1 `I-10`) — still neither fixed nor recorded, and the lane widened it 1 → 4 call sites.
`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:24` (`use App\Modules\Treasury\Domain\PaymentAllocation;`), used at `:204`, `:250`, `:280`, `:489`.

`dev`'s copy has the same import and **one** usage (`:303`). The lane added three more. This is rule 6 (a Document
Domain service reaching into Treasury's Eloquent model) and deptrac cannot see it — its layers are tier-based
(`deptrac.yaml:54-57` puts every module's `Domain/` in ONE `ModuleDomain` layer), which is why probe 1 is green.
Extending a pre-existing violation is not a new defect, and the lane did build `CustomerAdvanceClearingInterface` for
the Accounting seam in the same file — so the omission is visible.
**Fix:** add an `App\Shared\Contracts\Treasury\OpenAdvanceAllocationsInterface` seam, **or** name it as a residual.
Do not leave it as neither for a third round.

### 6. `R2-M3` — `SalesOrderToInvoiceConverter` still uses the no-arg `$this->scale()` for prepayment arithmetic.
`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php:545`, `:556`, `:557`, `:577`

Verified **pre-existing**: `git show dev:…` has the identical four lines. HTTP-only today, so not rule-19 drift from
this lane. Recording it once so the next converter lane picks it up; the invoice currency is in hand at all four sites.

### 7. `R2-M4` — answered in code, and answered well.
`apps/api/tests/Feature/Treasury/N6PaymentOnUnpostedInvoiceTest.php:158-172`

Rather than parameterising the fixture, the lane wrote a comment stating plainly that `BuildsDeliveryPolicyFixtures`
hardcodes `tax_amount => '0.000'` so *"pretending otherwise would be a vacuous assertion"*, and asserts the invariant
instead. That is the honest answer and I accept it — my probe 18 supplies the real proof independently
(`Cr 4375 38.000` on a 19% invoice). No action.

---

## r2 findings — closed / not closed

| r2 | Status at `36e1638c9` |
|---|---|
| **R2-C1** [CRITICAL] refund mis-books an advance-backed payment | **CLOSED** — `assertNotAdvanceBacked()` at `PaymentRefundService.php:522-541`, called from `refundPayment()` `:173` and `partialRefund()` `:344`, both **inside the transaction, before any write**, after `assertWithinRefundableBalance()`. Renders **422 `BUSINESS_ERROR`** via the generic `DomainException` handler (`bootstrap/app.php:989-997`) — same shape as every other belt on this service. Message names `reversePayment()`. Tamper-proved (probe 10) and both arms probed live (probes 14, 15). **Uses `getScaleSafe($original->currency, 3)`** — rule 19 clean. |
| — the **decision** (fail-closed vs proration) | **CORRECT FOR PHASE 1, and I would have refused the alternative.** The "make it correct" option is only safe on the reversal arm because A-D6 has already refused any advance-backed original carrying a prior refund, so `netUnreversed == arBacked + advanceBacked` exactly and no proration is needed (the C-A note at `:930-948`). `partialRefund()` refunds an ARBITRARY amount, so splitting it across 411/419 **is** proration — the rule the reversal lane deliberately declined to invent and ticketed as OQ-3. Inventing it in a fix round, for a path that already has a correct sibling one click away, would mis-state two accounts on a guess. Fail-closed cannot. It also keeps A-D6's premise a fact rather than a stale comment. **Ticketing as R-15/OQ-3 is right; do not let it drift** — the operator-facing consequence is that a prepayment can only be unwound in FULL (`reversePayment`), never partially, and that limitation should reach the FE before a cashier meets it as a 422. |
| **R2-C2** red test from the ctor widening | **CLOSED** — `11 passed (44 assertions)` on sqlite AND PG (probes 5, 6). Inherited `TreasuryDepositBridgeTest` red **declared** in the handback as R-16 with the diff evidence; I re-proved byte-identity of BOTH blobs against `dev` (probe 9) and reproduced exactly **9 red** (probe 8). |
| **R2-I1** multi-payment repair corrupts the partition | **CLOSED** — one reclass per payment, each with its **own** amount, `customer_payment` entry date, closed-period verdict and `arBacked` evidence (`RepairPaidNeverPostedDocumentsCommand.php:272-370`), `repair()` looping the plan at `:390-412`. Tamper-proved (probe 11). Probe 17 is the whole arc: per-payment dates `2026-08-24` / `2026-08-23`, both partitions read their OWN 100.000, **both payments reverse successfully**, ledger ends `411=0.000 419=0.000`, and a second `--execute` writes nothing (the `$alreadyReclassified` guard at `:223-231` plus the `paid` candidate predicate at `:117-118`). Also correct for a payment that spans this invoice AND another: `arBacked` is the payment's total 411 credit while `ownAmount` is only its allocation here, so the `>=` gate at `:322` passes and the partition nets right — I traced this by hand. |
| **R2-I2** FIFO membership untested | **CLOSED for membership, PARTIAL for the ledger half** — finding 3. Tamper-proved; the behaviour it doesn't reach is correct (probe 16). |
| **R2-I3** classifier untested | **CLOSED** — `DocumentAllocationClassifierMatrixTest`, 78 generated pairs + 3 named + a non-empty-buckets guard, `80 tests / 240 assertions`. Tamper-proved (probe 12). Five pairs hand-checked against the docblock design note above. The `expectedTreatment()` restatement is close in shape to the `match(true)` it checks — but it is written in a different construct, ordered differently, and derived from the prose rules, and it did catch the tamper on all four affected pairs. Accepted. |
| **R2-M1..M4** | M1 **open + undeclared** (finding 2) · M2 **open + undeclared** (finding 5) · M3 **open, pre-existing** (finding 6) · M4 **answered in code** (finding 7) |
| **A-D6 fixture rewrite** | **Meaningful, not a tautology.** The fixture now writes a `payment_type = refund` row keyed on `original_payment_id` directly. `alreadyRefundedForOriginal()` (`:549-566`) queries exactly that shape and needs no `journal_entry_id`, and the original's partition is unaffected either way (a `partialRefund` posts its GL against the REFUND payment's id, not the original's) — so the fixture is faithful to what the old one produced. The assertion is unchanged and still exercises the real A-D6 branch at `:856-870`; it went green in probe 7 and the test is now the only defence for legacy rows already carrying that shape. Correctly declared as a knock-on in the handback. |

## Fiscal condition round (`1cf556d06`, `5e14fa82e`, `76ce57af1`) — treasury lens

`1cf556d06` is presentation only (blade marker + `en`/`fr`/`ar` strings); grep for money-bearing added lines returns
nothing. `5e14fa82e`'s docblock and `use`-removal hunks touch no money and deptrac stays at 183. **R2-F5 is the only
hunk in my lens** and it is GL-sound on the path it was written for — see finding 1 for the one belt it steps around.
Rule-19 scan across both commits: **clean**.

## R-5 — the line for the owner (unchanged from r2, now with the fleet clearance)

The reversing-and-re-booking JE pair remains the right instrument, and both riders I attached in r1 stay discharged.
What is new: **the fleet caveat I raised in r2 is now lifted.** `--execute` was safe on the campaign tenant only
because it has a single payment; after R2-I1 a multi-payment invoice produces one correctly-attributed reclass per
payment and every payment stays reversible — proven end to end on PostgreSQL (probe 17), including the second-run
no-op. `tenants:run … --option='execute=1'` is now safe fleet-wide on treasury grounds. The doctrinal question is
untouched and still yours: whether a ledger correction with no document to attach to may exist as a bare journal
entry, or whether the correcting-entry invariant should widen so a **payment** can be a correcting entry's target.
Phase 1 does not need that ruling; the document-per-action programme does.

---

## What to fix before merge

**One line: state `gated_ceiling` 1167 / `Document` 82 in the merge commit (not the handback's stale 1166), and record findings 1 and 5 as named residuals if you are not fixing them.**

Ordered:
1. **Finding 4 (condition)** — merge commit says **1167 / Document 82**; re-take the union if W2-7 lands first.
2. **Finding 1** — guard the R2-F5 cleanup on a re-read `Draft` and delete lines through the model, **or** record it as `R-17`.
3. **Finding 2** — one-line `status = Posted` predicate at `RepairPaidNeverPostedDocumentsCommand.php:296`.
4. **Finding 3** — fix the inaccurate comment at `N6PaymentOnUnpostedInvoiceTest.php:527`, or add the bridge-shaped assertion.
5. **Finding 5** — seam or residual for the Document→Treasury `PaymentAllocation` reach; third round of silence is one too many.
6. **Finding 6** — record the converter's no-arg `scale()` for the next converter lane.
7. Carry **R-15/OQ-3** forward with the operator-facing note: a prepayment can only be unwound in full today.

None of 2–7 is merge-blocking.

---

*Gate run in an isolated `git worktree` on a scratchpad path with a copied vendor tree; the lane worktree was never
modified (`git status --porcelain` empty before and after). Throwaway PostgreSQL database `autoerp_test_n6t3` created
and dropped (verified: 0 rows matching `%n6t%`); scratch worktree removed and pruned. Never the full suite; one test
process at a time. Every tamper reverted and the scratch tree verified clean before removal.*
