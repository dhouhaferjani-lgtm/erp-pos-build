# N-6 / B-20 Phase 1 — treasury/GL gate r1

**Lane** `fix/campaign-n6-payment-advance` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n6-payment-advance`
**Reviewed commit** `d1b88e0da` (merge of dev `4ee0c3c59`; merge-base with dev `69797e958`)
**Lens** treasury/GL (fiscal-pos reviews in parallel)
**Date** 2026-08-24

## VERDICT: spec ❌ · quality CHANGES-REQUESTED

The core of the lane is right and I verified it by execution: a payment on a confirmed invoice
books Cr 419 and moves no lifecycle status; posting clears 419 → 411 exactly once and settles
through `Posted`; the partner card reads receivable 0 / advances 200; close-with-tolerance
refuses an unposted invoice with 422; the DB CHECK refuses an unknown status value; 89 touched
treasury tests are green on PostgreSQL. **Two Critical defects and one failing architecture gate
block the merge.**

> NOTE ON STATE: I reviewed `d1b88e0da`. The worktree currently carries THREE uncommitted files
> (`DeliveryNoteService.php`, `DocumentPostingService.php`, `ReturnNoteService.php`) from the
> fiscal-pos lens's r1 fix round (chain-fork finding F-1, which is caused by
> `settleIfFullyPrepaid()` moving to `Paid` inside the sealing transaction). Those are NOT part of
> this review. The two lenses' fix rounds must be merged deliberately, not raced.

---

## What I verified BY EXECUTION (not by reading the handback)

Method: `git worktree add --detach` on a scratchpad path (the lane worktree was never touched),
with a **copied** vendor tree — a symlinked `vendor` silently runs the MAIN checkout's `app/`
(`autoload_psr4.php` resolves `$baseDir` through the symlink), which is how I got a free
independent red-proof.

| Probe | Result |
|---|---|
| Red-proof on base (lane tests against dev's `app/`) | `Tests: 24, Errors: 19, Failures: 4` — **exactly** the handback's claim |
| `N6PaymentOnUnpostedInvoiceTest` + `DocumentStatusMachineTest`, sqlite | `OK (24 tests, 62 assertions)` |
| `RepairPaidNeverPostedDocumentsCommandTest` + PHPStan-rule test, sqlite | `OK (10 tests, 33 assertions)` |
| Same on PostgreSQL 16 (`autoerp_test_n6tr`, 127.0.0.1:5433, dropped after) | `OK (15 tests, 56 assertions)` |
| 7 touched/adjacent treasury suites on PG (incl. `AdvanceReversalGlShapeTest`) | `OK (89 tests, 503 assertions)` |
| Split payment on a confirmed invoice | 411 = 0.000, **419 = −200.000**, status `confirmed` ✅ |
| Close-with-tolerance on a confirmed invoice | `HttpResponseException` **422** before any write-off JE, status unchanged ✅ |
| Repair `--execute` → post, end-to-end ledger | 411 −200 → 0 → 0 · 419 0 → −200 → 0 · revenue −200 ✅ GL-sound |
| `is_historical` opening-balance invoice vs repair | `candidates=0` — correctly excluded ✅ (but **untested by the lane**, see I-9) |
| Partner balances after an advance | `receivable_balance=0.000`, `credit_balance=200.000`; API surfaces both ✅ |
| PG: both migrations, re-run | `INFO Nothing to migrate` — idempotent ✅; columns `boolean NOT NULL DEFAULT false`, 2× nullable ✅ |
| PG: `chk_documents_status_enum` tamper | `INSERT … status='bogus_status'` → `ERROR: … violates check constraint "chk_documents_status_enum"` ✅ |
| `pint --test` | `{"result":"pass"}` ✅ |
| `feature-lane-manifest-check.php` | `EXIT=0`, gated 1164 ✅ |
| PHPStan (7 changed files, live-DB env, new rule active) | `[OK] No errors` ✅ |
| **deptrac ratchet** | **`RESULT: FAIL` — BLOCKER +1, total 182 → 184** ❌ (see I-1) |
| Manifest reconciliation vs *current* dev `b5410a920` | dev `gated_ceiling 1163` / `Document 79`; lane `1164` / `80`. Union arithmetic correct — the two dev commits since the merge-base are docs-only ✅ |

---

# FINDINGS

## CRITICAL

### C-1 — The repair command dates its correcting entry, AND evaluates its period-lock gate, on the WRONG date. Proven by execution.
`apps/api/app/Console/Commands/RepairPaidNeverPostedDocumentsCommand.php:226-242`

```php
$paymentEntry = JournalEntry::query()
    ->where('company_id', $invoice->company_id)
    ->where('source_type', 'payment')          // ← never matches
    ->whereIn('source_id', $paymentIds)
```

AR customer payments are written with **`source_type = 'customer_payment'`**
(`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1597`, `source_id = $paymentId` at `:1599`).
The only writer of `'payment'` is `createPaymentEntry`
(`GeneralLedgerService.php:370`) — which sets **no `source_id` at all**. So `$paymentEntry` is
**always `null`** for the population this command targets, and `:233-235` silently falls back to
`Carbon::parse($invoice->document_date)`.

Executed proof (throwaway PG + sqlite, isolated probe):
```
PAYMENT ENTRY: source_type=customer_payment date=2026-08-24
INVOICE document_date=2026-08-02
RECLASS ENTRY date=2026-08-02      ← the invoice's date, not the payment's
```

Why it matters:
1. The brief's evidence gate ("refuses if the JE is in a locked period") is checked at `:237`
   against the **invoice** date. A payment booked into a period that is now CLOSED, on an invoice
   dated in an OPEN period, is **repaired straight into the closed period** — the guard cannot
   see it. The converse spuriously skips repairable invoices.
2. The correcting entry lands in the wrong accounting period, which is precisely what the
   command's own comment at `:223-225` says must not happen ("Dating it `now()` instead would
   leave the misstatement standing in its own period").
3. The campaign dry-run line the owner reviewed — `entry date 2026-08-23` for INV-2026-0003 — is
   the **invoice's** `document_date`, not the payment entry's date. The handback presents it as
   the latter.

**Fix (exact):** at `:226-231` use
`->where('source_type', 'customer_payment')->whereIn('source_id', $paymentIds)`, and make a
missing payment entry a **SKIP with a named reason**, not a silent fallback to `document_date` —
a repair that cannot find the entry it is restating has no business choosing a date for it. Add a
test that asserts `reclass.entry_date === paymentEntry.entry_date` and a test that a payment entry
in a closed period SKIPS.

### C-2 — `SalesOrderToInvoiceConverter` stamps `advance_cleared_at` BEFORE the clearing entry is actually posted, and thereby disables the lane's own clear-at-posting safety net.
`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php:577-643`

The new stamp at `:638-643` (`booked_as_advance = true, advance_cleared_at = now()`) runs on the
success path of `clearCustomerAdvanceToReceivable(...)` at `:579-588` — which is called with the
**default `PostingMode::AfterCommit`**. That call only *creates* the entry; the post is deferred.
The file's own pre-existing comment at `:589-608` states this explicitly:

> "`clearCustomerAdvanceToReceivable` posts via `postEntryAndDispatchPostedEventAfterCommit`,
> which defers the post through `DB::afterCommit` whenever `DB::transactionLevel() > 0` … so the
> refusal is raised after this frame has returned and propagates past this catch."

Consequences:
* If the deferred post is refused (unbalanced-entry chokepoint, closed period, advance-ceiling),
  the **marker is already committed**. `DocumentPostingService::clearAdvancesAllocatedToInvoice()`
  (`DocumentPostingService.php:190-197`) filters `whereNull('advance_cleared_at')` and therefore
  **skips**. Result: 419 permanently stranded, the invoice's fresh 411 never discharged, and
  `settleIfFullyPrepaid()` may still flip the invoice to `Paid` because `balance_due` is 0. Silent
  wrong money — the exact state the lane exists to prevent, now made invisible.
* Latent variant: with `$postedByUserId === null` and `AfterCommit`, `clearCustomerAdvanceToReceivable`
  takes **neither** branch at `GeneralLedgerService.php:1868-1876` — the entry is created **Draft
  and never posted** — while the allocation is stamped cleared anyway. The lane documents this
  exact trap at `GeneralLedgerService.php:1781-1787` and then walks into it on the converter path.
  Not live today (`DocumentConversionController.php:76` always passes `actor_user_id`), but it is
  one nullable caller away.

The handback's claim that the converter "stamps the transferred allocations … **after a SUCCESSFUL
clearing**" is not what the code does; it stamps after a *scheduled* one.

**Fix (exact):** switch the converter call at `:579-588` to
`PostingMode::SynchronousInTransaction` (it already runs inside
`billingConcurrencyRetrier->run()`'s transaction, so the new `DB::transactionLevel() < 1` guard at
`GeneralLedgerService.php:1788` is satisfied) and keep the stamp where it is — then a refusal rolls
the stamp back with it, exactly as the posting path already does. Alternatively move the stamp
into the `DB::afterCommit` callback. Then write the **real** converter probe the brief asked for
(order prepayment → `convertOrderToInvoice` → post ⇒ exactly one `prepayment_application` entry);
today's test at `N6PaymentOnUnpostedInvoiceTest.php:257-260` *simulates* the converter by hand-writing
`advance_cleared_at`, so this code path has **zero** coverage.

---

## IMPORTANT

### I-1 — deptrac FAILS with a BLOCKER; the gate was never run. (merge-blocking)
Measured on the lane, `php tools/deptrac-ratchet.php`:

```
ModuleDomain on ModuleApplication      54 -> 55   BLOCKER (+1)
SharedContracts on ModuleDomain        35 -> 36   RATCHET (+1)
TOTAL                                 182 -> 184
RESULT: FAIL — architecture boundary regression.
```

The BLOCKER is `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:34` —
`use App\Modules\Treasury\Application\Services\DocumentAllocationClassifier;` (Domain depending on
Application). The ratchet is
`apps/api/app/Shared/Contracts/Accounting/CustomerAdvanceClearingInterface.php:40` (Shared contract
typed on `Document`). The handback lists phpstan / pint / manifest / typecheck / eslint / vitest —
deptrac is absent, and the memory rule "⛔ red-gate reconciliation before ANY promotion" names it.

**Fix:** relocate `DocumentAllocationClassifier` to `App\Modules\Treasury\Domain\Services` — it is a
pure, side-effect-free policy object with no infrastructure dependency, so Domain is where it
belongs (this also removes the `HttpResponseException`-from-Domain smell if the refusal becomes a
typed Domain exception rendered in `bootstrap/app.php`, exactly like `DocumentTransitionException`).
Record an explicit baseline waiver for the `+1` on `CustomerAdvanceClearingInterface`, mirroring the
`r2f4` waiver already in `deptrac.baseline.json` for `DocumentGlCorrectionInterface`.

### I-2 — The repair takes NO evidence that the money is actually sitting in 411.
`RepairPaidNeverPostedDocumentsCommand.php:169-259` checks: allocations exist, none already marked,
sum > 0, no prior reclass, period open. It never checks that the payment's ledger footprint
actually **credited CustomerReceivable**. It then unconditionally writes `Dr 411 / Cr 419`.

The codebase already has the exact primitive for this:
`apps/api/app/Modules/Accounting/Application/Services/PaymentLedgerPartitionReader.php:62-85`
returns `arBacked` / `advanceBacked` per payment.

Live counter-population: a customer **deposit** applied pre-N-6 via
`MultiPaymentService::applyDepositToDocument()` posted **no GL at all** (the lane's own residual
R-2) while the deposit's money was already in **419**, and the old
`getDocumentStatusAfterPayment()` flipped the invoice to `Paid`. Such an invoice is `paid` +
`fiscal_hash IS NULL` + `is_historical = false` ⇒ a **candidate**. Repairing it invents a 411
DEBIT that never existed and **doubles** the 419 credit.

**Fix:** gate `assess()` on `PaymentLedgerPartitionReader::read(...)->arBacked >= $amount`; skip
with a named reason otherwise. The campaign tenant has one candidate from a direct payment, so the
immediate run is safe — but this command is written to run fleet-wide.

### I-3 — A repaired payment is invisible to the reversal partition reader; a later refund reverses 411, not 419.
`PaymentLedgerPartitionReader.php:66-82` computes `advanceBacked` as Σ credit on `CustomerAdvance`
**where `journal_entries.source_type = 'advance'`** and `source_id = payment.id`. The repair writes
`source_type = 'payment_advance_reclass'` (`GeneralLedgerService.php:57`, `:441`), and the original
`customer_payment` entry (still crediting 411) is untouched. So after a repair:
`arBacked = full amount`, `advanceBacked = 0` — and `PaymentRefundService`'s reversal will treat the
money as AR-backed, reversing 411 while the 419 the repair created is left standing.

New (post-N-6) prepayments are fine — they go through `createCustomerAdvanceJournalEntry`
(`source_type = 'advance'`, `source_id = payment.id`). Only the **repaired** population is wrong.

**Fix:** teach `PaymentLedgerPartitionReader` to net `payment_advance_reclass` entries for the same
`source_id` (subtract its 411 debit from `arBacked`, add its 419 credit to `advanceBacked`), and pin
it with a test: repair → refund ⇒ 419 goes to 0 and 411 stays 0.

### I-4 — `DocumentFullyPaid` is never emitted on the prepay → post → `Paid` flow. Audit-chain gap.
`DocumentPostingService.php:245-269` (`settleIfFullyPrepaid`) calls `markPaid()` and dispatches
nothing. Every pre-N-6 writer that flipped to `Paid` also dispatched `DocumentFullyPaid`
(`PaymentAllocationService.php:462`, `PaymentController.php:1070/1724/1838/1920`), and
`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:181-197` persists it into the
audit event store. A fully-prepaid invoice now becomes `Paid` with **no audit event at all**.

**Fix:** dispatch the existing `DocumentFullyPaid` (rule 8: reuse, do not version) from
`settleIfFullyPrepaid()` via `DB::afterCommit`, with `totalPaid = $invoice->total` and
`paidAt = now()`.

### I-5 — The classifier's legacy fall-through is NOT "unchanged behaviour" for every unruled pair — it flips SalesOrder+Posted from Cr 419 to Cr 411, reproducing N-6 on a different pair.
`apps/api/app/Modules/Treasury/Application/Services/DocumentAllocationClassifier.php:82-84`

The pre-N-6 rule in the smart-payment path was a pure TYPE test
(`if ($doc->type === DocumentType::SalesOrder) → advance`), i.e. **any** sales order booked Cr 419.
The new classifier only maps `SalesOrder + Confirmed` to `Prepayment`; `SalesOrder + Posted` and
`SalesOrder + Received` fall through to `ReceivableClearing`. A posted sales order is reachable
(`DocumentPostingService::cancelSalesOrder()` at `:388` guards for exactly that state), and reaches
the classifier through `previewManualAllocation` → `applyAllocationFromCommand`
(`PaymentAllocationService.php:730`, `:210`). Result: Cr 411 against a document that has no
receivable — the N-6 defect, on a new pair, introduced by the fix.

Full enumeration of what `default =>` now books **Cr 411** (13 types × 6 statuses, minus the
refusals and the three ruled rows):

| Type | Statuses reaching `ReceivableClearing` by fall-through |
|---|---|
| SalesOrder | Posted, **Received** *(behaviour change from Cr 419)* |
| PurchaseOrder | Confirmed *(= residual R-1)*, Posted, Received |
| Quote | Confirmed, Posted, Received |
| DeliveryNote | Confirmed, Posted, Received |
| ReturnNote | Confirmed, Posted, Received |
| SupplierCreditNote | Confirmed, Posted, Paid, Received |
| Expense / Income | Confirmed, Posted, Received |
| PurchaseQuoteRequest | Confirmed |
| CorrectingEntry | Confirmed, Posted |
| Invoice | Received |

I confirmed two of these by execution against `POST /api/v1/payments`:
`POSTED-SO: http=201 411=-200.000 419=0.000 booked_as_advance=false` and
`CONF-PO: http=201 411=-200.000 419=0.000` — a **negative customer receivable on a supplier
partner** for the purchase order.

**Fix (minimum):** add `DocumentType::SalesOrder => Prepayment` for `Posted`/`Received` so the
smart-payment path's pre-N-6 semantics are genuinely preserved. **Fix (right):** invert the
default to `null` (refuse) and enumerate the allowed pairs explicitly, with `PurchaseOrder` kept as
an explicitly-named, explicitly-wrong legacy row carrying R-1's ticket reference — a
"deliberate, measured decision" that is written as `default =>` cannot be distinguished from an
oversight by the next reader.

### I-6 — `getOpenInvoices()` now admits confirmed invoices into the FIFO auto set, which the POS projection bridges consume. No test, no mention in the handback.
`PaymentAllocationService.php:525-549` — the new `Invoice + Confirmed` arm — combined with
`orderBy('document_date','asc')` at `:544`. `applyAllocationFromCommand` is driven by
`apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:216` and
`TreasuryDepositBridge.php:200` with `AllocationMethod::FIFO`.

So a device-authored POS `ACCOUNT_PAYMENT` / back-office `DEPOSIT_RECEIPT` now allocates to an
**older confirmed-but-unposted invoice ahead of a genuinely due posted invoice**, parking the cash
in 419 while real AR stays open. That is a defensible model — but it is a behaviour change on a
device-originated fiscal projection path with no test and no line in the handback.

Secondary: `DocumentAllocationClassifier::refuse()` throws
`Illuminate\Http\Exceptions\HttpResponseException` (`:95-106`). Raised inside a queued projection
that is what the worker sees — an HTTP response object with no request. Reachable if a document's
status changes between the unlocked `getOpenInvoices()` read and the locked `classify()` at `:210`.

**Fix:** add a projection-level test for the new FIFO membership (bridge + confirmed invoice +
posted invoice, assert which one absorbs and that 419/411 land correctly), and convert the refusal
to a typed Domain exception rendered in `bootstrap/app.php` alongside `DocumentTransitionException`.

### I-7 — The PHPStan rule misses Eloquent builder mass-updates, and the claimed DB-CHECK backstop cannot see an illegal EDGE. Both proven by tamper.
`apps/api/app/PHPStan/Rules/DocumentStatusWriteOnlyViaStatusService.php:206-209` — `isDocument()`
requires the receiver's type to be *exactly* `App\Modules\Document\Domain\Document`. I ran the rule
over a probe fixture through its own `RuleTestCase` harness:

| Probe | Reported? |
|---|---|
| `Document::query()->whereKey($id)->update(['status' => DocumentStatus::Paid])` | **NO** ← ordinary Laravel, real hole |
| `$attrs = ['status'=>Paid]; $doc->update($attrs)` | NO (documented) |
| `$doc->setAttribute('status', DocumentStatus::Paid)` | NO (not documented) |
| `DB::table('documents')->update(['status'=>'paid'])` | NO (documented) |
| `?Document` narrowed by `!== null`, then `update([...])` | YES ✅ |

The rule's docblock at `:53-55` says "raw SQL / `DB::table()` writes are outside this rule's shape
entirely — **the DB CHECK is the backstop for those**." That is false. I tampered the constraint on
PostgreSQL: `chk_documents_status_enum` refuses `'bogus_status'` ✅, but its predicate is
`status IN ('draft','confirmed','posted','paid','received','cancelled')` — `'paid'` is a legal
VALUE, so a raw `UPDATE documents SET status='paid' WHERE status='confirmed'` passes it. The
migration's own docblock is honest about this ("SCOPE — VALUE DOMAIN ONLY, NOT THE ADJACENCY",
`2026_08_24_100100_…php:24-28`); the PHPStan rule's is not. A future
`Document::query()->update(['status' => Paid])` reproduces N-6 with **zero** guards firing.

**Fix:** extend `isDocument()` to accept `Illuminate\Database\Eloquent\Builder<…\Document>` (and
`Relation`) receivers — that is one `str_starts_with` on the precise type describe — add the probe
fixture to the rule test, and correct the docblock to say the CHECK backstops VALUES only.

### I-8 — Rule 19: the advance ceiling is compared at the COMPANY scale against a DOCUMENT-currency amount, via a no-arg `getScale()`.
`GeneralLedgerService.php:1810`, `:1818`, `:1820` use `$this->scale()` →
`$this->scaleResolver->getScale()` with no currency (`:71-74`), which resolves from
`CompanyContext` and **throws `UnboundCompanyContextException`** outside a request
(`CurrencyScaleResolver.php:45-52`). The lane routes a NEW caller through this method
(`CustomerAdvanceClearingAdapter.php:38-48`) and dutifully passes `$document->currency` — which is
then used only for `postEntryNow`, not for the ceiling arithmetic. For a document in a currency
whose scale differs from the company's, `bccomp($amount, $availableAdvance, $this->scale())`
compares at the wrong scale. And the moment `post()` is driven from a queue or console (no such
caller today — I grepped) the clearing throws and, being inside `post()`'s transaction, refuses the
posting.

**Fix:** thread `$currencyCode` into the three call sites — `$scale = $this->scaleResolver->getScaleSafe($currencyCode, 3)` at the top of
`clearCustomerAdvanceToReceivable` and use it at `:1810/:1818/:1820`.

### I-9 — Test gaps on load-bearing behaviour.
* **The converter double-clear probe is simulated, not executed** —
  `N6PaymentOnUnpostedInvoiceTest.php:250-260` hand-writes `advance_cleared_at` instead of calling
  `SalesOrderToInvoiceConverter`. The brief asked for "order prepayment → convert → post ⇒ ONE
  clearing". The converter's new stamping code (`:632-643`) is covered by nothing — which is how
  C-2 survived.
* **No repair test for the `is_historical` exclusion.** The handback calls this exemption the thing
  that stops the lane "silently breaking every migrated tenant's opening AR". I verified it works
  (probe: `candidates=0`), but nothing in the lane pins it. Add one.
* **No repair test for the locked-period skip**, and none for the post-repair ledger state — the
  existing tests assert `reclassEntryCount() === 1`, never that 411 returns to 0 and 419 carries the
  credit. I verified that separately; it should be in the suite.
* `test_posting_a_prepaid_invoice_clears_419_recognises_revenue_and_settles_the_lifecycle`
  (`:115-152`) asserts 419 = 0 and 411 = 0 but **never asserts revenue or VAT**, despite its name
  and the brief's "revenue+VAT recognised".
* No test for the split-payment or deposit-application prepayment paths (I probed both; both behave
  correctly — pin them).

### I-10 — Rule 6: the lane adds three new direct uses of a Treasury Eloquent model from the Document module while building a Shared contract next door.
`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:23` (`use App\Modules\Treasury\Domain\PaymentAllocation;`)
was pre-existing with a single read at `:303`; the lane adds `:192-197` (`lockForUpdate()->get()`),
`:230-232` (`update(['advance_cleared_at' => now()])`) and `:260-262` (`exists()`). The lane built
`CustomerAdvanceClearingInterface` for the Accounting seam and then reached straight into Treasury's
table for the allocation seam. Not a deptrac category (the class-level edge already existed), but it
is the rule the lane's own docblock at `CustomerAdvanceClearingInterface.php:20-23` invokes.
**Fix:** a `Shared\Contracts\Treasury\OpenAdvanceAllocationsInterface` with
`openAdvancesFor(Document): {total, ids}` + `markCleared(ids)`, or accept and record it as a named
residual.

---

## MINOR

* **M-1** — The "characterisation, not red-first" label for
  `test_aged_receivables_reads_zero_while_the_advance_is_open`
  (`N6PaymentOnUnpostedInvoiceTest.php:277`) exists only in the handback. Put it in the test's own
  docblock — the handback will not be read by the next person who touches the file. (The honesty
  itself is exemplary and I confirmed the claim: `AgedReceivablesService` filters `status = Posted`.)
* **M-2** — `apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:10` imports
  `App\PHPStan\Rules\DocumentStatusWriteOnlyViaStatusService` into production Domain code purely for
  a docblock `@see`. Use the FQCN in the docblock text instead.
* **M-3** — `2026_08_24_100100_add_status_check_constraint_to_documents.php:101-105` says the values
  are "derived from the enum, never hand-listed" so a new case "would otherwise be rejected". The
  CHECK is frozen at migration-run time: adding a `DocumentStatus` case later leaves already-migrated
  tenants refusing it at the DB while the app accepts it. Say so, and add a guard test that the
  live constraint's value set equals `DocumentStatus::cases()`.
* **M-4** — `apps/api/tests/feature-lane-manifest.json` `lanes.Document.note` now contains the
  N-2 "DELIBERATE RAISE 77 -> 78" paragraph **twice** (merge artefact). Deduplicate.
* **M-5** — `DocumentPostingService.php:217` uses the `auth()` helper (rule 13 forbids `app()`;
  `auth()` is the same shape). Pre-existing pattern at `:400`, but this is a new call site — pass the
  actor in, or accept `null` explicitly.

---

## R-5 — the line for the owner

**The lane is right that `CorrectingEntryService` cannot express this repair, and it proved it
rather than asserting it.** `AccountingService::assertCorrectingEntryIsPostable()` refuses with
`targetHasNoLedgerEntry` on an empty footprint, and a never-posted invoice has exactly that; the
mis-booking lives on the *payment's* entry, not the document's. What was shipped instead is a
**GL-sound reversing-and-re-booking pair, not a raw mutation**: the original hash-chained payment
entry is never touched, and a new balanced entry (`Dr 411 / Cr 419`, partner-tagged, its own
`source_type = 'payment_advance_reclass'`) states the correction. I verified the whole arc by
execution: 411 −200 → 0, 419 0 → −200, then posting nets both to 0 with revenue recognised.
**Two riders before you rule:** the entry currently carries the **wrong date** (C-1), and it is
**invisible to the refund/reversal partition reader** (I-3). Fix those first; then the only question
left for you is the doctrinal one — whether a ledger correction that has no document to attach to
may exist as a bare journal entry, or whether `assertCorrectingEntryIsPostable()`'s
"target must already have a footprint" invariant should be widened so a payment can be a correcting
entry's target. Phase 1 does not need that ruling; the document-per-action programme does.

---

## What must change before merge

1. **C-1** — fix the `source_type` lookup so the reclass is dated on, and period-locked against, the
   real payment entry; skip loudly when it cannot be found. Test both.
2. **C-2** — post the converter's clearing `SynchronousInTransaction` (or move the stamp into the
   afterCommit callback) so `advance_cleared_at` can never outlive a failed clearing; add the REAL
   order→convert→post single-clearing test.
3. **I-1** — relocate `DocumentAllocationClassifier` to Treasury `Domain\Services` to clear the
   deptrac BLOCKER, and add a baseline waiver for the `CustomerAdvanceClearingInterface` +1. Re-run
   `tools/deptrac-ratchet.php` and put the number in the handback.
4. **I-2 / I-3** — evidence-gate the repair on `PaymentLedgerPartitionReader::read()->arBacked`, and
   teach that reader to net `payment_advance_reclass`. Both are ledger-correctness, both are cheap.
5. **I-5** — stop `SalesOrder + Posted/Received` falling through to Cr 411; enumerate the allowed
   pairs rather than defaulting into them.
6. **I-4, I-7, I-8** — emit `DocumentFullyPaid`; close the PHPStan builder-update hole and correct
   the docblock's backstop claim; thread the currency into the advance-ceiling scale.
7. **I-9** — the four missing tests (converter, `is_historical`, locked period, revenue/VAT).
8. Re-run the treasury gate after the fix round; coordinate the merge with the fiscal-pos lens's
   in-flight F-1 chain-fork fix, which touches the same `DocumentPostingService`.

**Do NOT run `documents:repair-paid-never-posted --execute` on the campaign tenant until C-1 and
I-2 are fixed** — as shipped it will date INV-2026-0003's correcting entry on the invoice's
`document_date` and has evaluated the period lock against the wrong date.

---

*Gate run in an isolated `git worktree` on a scratchpad path; the lane worktree was never modified.
Throwaway PostgreSQL database `autoerp_test_n6tr` (127.0.0.1:5433) created and dropped. Never the
full suite; one test process at a time.*
