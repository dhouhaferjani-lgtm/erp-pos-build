# L2 AR/document-status integrity — treasury/payments/AR-AP merge gate

**Branch:** `fix/l2-ar-integrity` (5 commits on `dev` @ `a84c1b53e`) ·
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-l2-ar` ·
**Date:** 2026-08-06 · **Lens:** payments / allocations / AR-AP semantics
(GL + hash-chain mechanics reviewed in parallel by the fiscal-pos gate).

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

D4 is correct and complete. D2 is correct for the *never-allocated* case it was
written for and **wrong for the opening-balance case** (a launch-path
regression). F-6 is fixed on 3 of 5 entry points that write a
`payment_allocation` and flip a document to `Paid`; **two unguarded routes carry
the identical exploit**, so the flipped tripwire `MTP-CONC-06` now asserts a
property the system does not have.

All lane tests pass (21/21 new). No regressions in the payment/allocation,
document-cancel or fiscal-hardening suites. PHPStan and Pint clean on the diff.

---

## Findings

### [CRITICAL] F-6 is NOT closed — two routes still resurrect a cancelled invoice as `paid`

`apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:109`
creates the `PaymentAllocation` and `:135-139` then writes

```php
$document->update([
    'balance_due' => '0.00',
    'status' => $this->getDocumentStatusAfterPayment($document),   // -> Paid
]);
```

with **no state predicate anywhere on the path**:

- `MultiPaymentService.php:40-58` gates only on `bccomp($totalSplit, $document->balance_due ?? $document->total)`
  — for a cancelled, never-allocated invoice `balance_due` is NULL, so the
  fallback offers the **full total** (the campaign's escalation (a), verbatim).
- `MultiPaymentController.php:161-172` checks tenant/company and
  `type === SupplierInvoice`, nothing else.
- Route: `apps/api/app/Modules/Treasury/Presentation/routes.php:220-222`,
  `POST /api/v1/documents/{document}/split-payment`, middleware
  `can:payments.create` — **the same permission** as the guarded
  `PaymentController::store`, so this is not behind a privilege wall.

Same shape, second route: `MultiPaymentService::applyDepositToDocument()`
`:251-292` — allocation at `:275`, `status => getDocumentStatusAfterPayment()`
at `:288-289`, no status check, reachable at
`POST /api/v1/payments/{payment}/apply-deposit` (`routes.php:228-230`).

**Why it matters.** The lane's own thesis is "call the guard on the SHARED
per-allocation paths so a status write can never be reached with a withdrawn
document in hand" (`DocumentAllocationStateGuard.php:20-32`). Two writers were
not enumerated, so the invariant is not established — and
`apps/web/e2e/money-campaign/w7-concurrency.spec.ts:589` now asserts F-6 FIXED,
which converts an open P0 into a green tripwire.

**Fix:** inject `DocumentAllocationStateGuard` into `MultiPaymentService` (or
call it in `MultiPaymentController::createSplitPayment` / `applyDeposit`) and
assert on `$document` before the split-total check and before
`applyDepositToDocument`'s transaction; add two API tests mirroring
`PaymentAllocationDocumentStateTest::test_the_multi_payment_path_refuses_a_cancelled_document`.

---

### [CRITICAL] D2 regression — `outstandingBalance()` overstates every AR/AP **opening balance**

`Document::outstandingBalance()` (`Document.php:723-750`) assumes
`documents.balance_due` is *only ever* the trigger formula. That is false for the
go-live onboarding path:

`apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php:293-313`
creates `status = Posted`, `type = Invoice` (or `CreditNote`) documents with
`'total' => $mappedData['total']` and `'balance_due' => $mappedData['open_amount']`,
and `:204-209` explicitly validates only `open_amount <= total` — a partially
settled legacy invoice (total 1 500 / open 300) is a **first-class supported
input**. No `payment_allocations` row is ever written for these.

Consequences of the switch:

| Surface | before | after |
|---|---|---|
| `AgedReceivablesService.php:180` (`openBalance`) | `open_amount` (300) — correct | `total` (1 500) — overstated by 1 200 |
| `AgedPayablesService.php:362` | `open_amount` | `total` |
| `UpcomingPaymentsService.php:365` | `open_amount` | `total` |
| `PaymentController.php:496` allocation cap | `open_amount` | `total` → **over-allocation of 1 200** |

The last row is the same defect class the lane claims to have *fixed* at that
exact line, reborn on the migration path. Reachable through
`OpeningBalanceBatchController` and `App\Modules\Import\Services\PartiesBalancesPhase`
— i.e. first-tenant go-live.

**Fix options:** (a) `outstandingBalance()` seeds from
`COALESCE(balance_due, total)` and subtracts allocations from that (matches the
trigger for trigger-maintained rows, preserves the opening carve-out), or
(b) `ArApOpeningService` writes a synthetic settled `payment_allocation` for
`total − open_amount` so the trigger formula becomes true, or (c) exclude
`is_historical` documents from the computed path. (a) is the smallest and keeps
the migration-free constraint. Whichever is chosen, add a test at the shape
`total 1500 / balance_due 300 / zero allocations`.

---

### [IMPORTANT] The reversal refusal is a permanent dead-end — the stated remedy cannot work

`UnreversibleDocumentGlException.php:44` tells the operator *"Post a correcting
entry first."* `AccountingService.php:766-793` decides balance from entries
matching `source_type = 'Document' AND source_id = $document->id AND status = Posted`.
The only manual-entry writer, `JournalEntryController::store()`, hard-codes
`'source_type' => 'manual'` and `source_id = $entry->id`
(`app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:100-102`).

A correcting entry therefore can **never** enter the predicate: the document with
an unbalanced sealed entry (per the ticket, exactly one exists — MTP-DOC-06 /
D1b) becomes **permanently un-cancellable**, with a message that sends the
accountant down a path that does nothing.

**Ruling on their open question (i):** *refuse* is the right call — mirroring an
unbalanced original would seal a second unbalanced entry, which is what D1a's
guard exists to prevent, and plugging the gap to a suspense account is a
posting the accountant did not authorise. But the refusal must be **escapable**.
Either (a) count `source_type IN ('Document', 'manual')` scoped by a
`source_document_id` the manual endpoint can accept, or (b) add an explicit
`force`/`correcting_entry_id` parameter on the cancel route that an
`accounting.manage` holder can supply, or (c) at minimum change the message to
name the real remedy. Do (c) in-lane; (a)/(b) may be a ticket.

---

### [IMPORTANT] Concurrent cancel double-reverses the ledger — no lock, no unique index

- `DocumentPostingService.php:149-156`: the in-transaction re-check is
  `$document->refresh()` — a plain `SELECT`, **no `lockForUpdate()`**.
- `AccountingService.php:721-729`: idempotence is an `exists()` on
  `(company_id, source_type = 'DocumentCancellation', source_id)`.
- `journal_entries` has **no** unique constraint on `(source_type, source_id)`
  (confirmed: `reference_journal_entries_no_global_source_uniqueness`), and
  `reverseDocumentGl()` takes **no company advisory lock** (unlike the
  `PostingMode::SynchronousInTransaction` GL paths).

Under READ COMMITTED two concurrent `POST /invoices/{id}/cancel` both pass the
`exists()` check and both seal a `REVCAN-…` entry: the ledger is reversed twice
(the document's revenue/AR/VAT are credited-then-debited **twice**), and both
entries claim the same `chain_sequence` from
`JournalEntry::getNextChainSequence()` with the same `previous_hash`.

**Ruling on their open question (ii): IN-LANE.** The pre-existing double-check
had the same shape but a harmless consequence (two idempotent writes of the same
terminal status). This lane makes the consequence *money in the ledger*, so the
lane owns it. The fix is one line — replace `$document->refresh()` at `:150`
with a `lockForUpdate()` re-read of the document row, taken **before**
`reverseDocumentGl()`. Add a test asserting exactly one
`source_type = 'DocumentCancellation'` entry after a repeated cancel (the
existing `test_cancelling_twice_cannot_double_reverse` covers the serial case
only).

---

### [IMPORTANT] `CreditNoteService::allocateCreditNote()` is unguarded — money against a withdrawn document

`app/Modules/Document/Application/Services/CreditNoteService.php:1236-1288`
locks the source invoice (`:1253-1260`) and writes a `CreditNoteAllocation`
(`:1280-1285`) with **no status predicate on the invoice**. Creation requires a
posted invoice (`:802-803`) but the credit note is created as `Draft`
(`:848-849`) and allocated only when it is *posted* — the invoice can be
cancelled in between.

Result: a credit is booked against a withdrawn invoice, and — now that this lane
adds `REVCAN` — the invoice's revenue is reversed **twice**: once by the
cancellation mirror and once by the credit note's own GL entry. The document's
`status` is not rewritten here, so this is not the resurrection bug, but it is
the same P0 class (money against a withdrawn document) with a *worse* GL
outcome than before the lane.

**Ruling on their open question (iii): YES, guard `allocateCreditNote()`
IN-LANE.** It is one call to the existing guard immediately after the
`findOrFail` at `:1260`, inside a transaction that already holds the row lock,
plus one test. The GL double-reversal is caused by this lane, so it is in scope.

**Tolerance-close: P1, in-lane guard, ticket the rest.**
`CloseInvoiceWithToleranceService.php:52-60` already holds `lockForUpdate()` on
the invoice; a `CANCELLED` invoice that carries a partial allocation has
`balance_due > 0` and is written straight to `DocumentStatus::Paid` at `:118`
with a GL write-off at `:94-104`. One guard call after `:56`. Its
`balance_due ?? '0'` read at `:58` is a separate D2 consumer — ticket.

---

### [IMPORTANT] The D2 consumer sweep stops short — three live `?? total` / cache readers remain

- `PaymentController.php:1394`, `:1699`, `:1766` — `storeMultiple()`'s primary
  and both excess caps still read `$doc->balance_due ?? $doc->total`. This is the
  *exact* over-allocation shape fixed at `:496` (their "150-allocated 200
  invoice accepted another 200"), left live on the multi-line path.
- `PaymentAllocationService.php:483` — `getOpenInvoices()` decides openness with
  `whereRaw('total > COALESCE((SELECT SUM(amount) FROM payment_allocations …), 0)')`,
  **ignoring `credit_note_allocations`**: a fully credit-noted invoice is still
  offered to FIFO/due-date auto-allocation.
- `PaymentAllocationService.php:696-705` — `getInvoiceBalance()` has the same
  omission, and it is the cap for the manual smart-payment path.

Either sweep these or state in the ticket why they are out of scope; as it
stands the lane's "consumers swept" claim reads broader than it is.

---

### [IMPORTANT] Duplicate `aged-receivables` route confirmed — shadowed service still carries D2 **and** D4

- `app/Modules/Accounting/Presentation/routes.php:178-180` and
  `app/Modules/Document/Presentation/routes.php:360-362` register the identical
  `GET api/v1/reports/aged-receivables` under the identical `api/v1` prefix
  (`Accounting/…/routes.php:25`, `Document/…/routes.php:33`).
- `bootstrap/providers.php:69` (`DocumentServiceProvider`) loads **before**
  `:71` (`AccountingServiceProvider`); Laravel's `RouteCollection::addToCollections()`
  keys on method+URI and the **last registration wins** — so Accounting's
  handler is live and the lane's fix is on the served path. Claim VERIFIED.
- The shadowed `app/Modules/Document/Application/Services/AgedReceivablesService.php`
  still reads `balance_due ?? total` (`:93`), `balance_due ?? '0.00'` (`:346`)
  and `whereRaw('balance_due > 0')` (`:331`), and exposes a **different bucket
  contract** (`days_1_30` / `days_31_60` / `days_61_90` / `days_over_90` vs the
  Accounting DTO's `days_30` / `days_60` / `days_90` / `over_90`).

**Ruling: IN-LANE, delete the Document registration** (`routes.php:360-362`) —
it is a 3-line deletion of a route that is provably never served, and leaving it
means D2+D4 silently return the day someone reorders `bootstrap/providers.php`
or a route cache is built in a different order. Add a one-line assertion
(`Route::getRoutes()->getByName('reports.aged-receivables')` resolves to the
Accounting controller) so the collision cannot come back. Ticket the *service*
deletion separately if other callers exist — `overdueSummary` /
`customerStatement` on the same controller are still served.

---

### [MINOR] AP report still reports purchase orders, not payables

`AgedPayablesService.php:155` and `:191` filter `DocumentType::PurchaseOrder`
only. `DocumentType::SupplierInvoice` — the document `PaymentController` pays,
that carries the Cr-401, and that `UpcomingPaymentsService.php:49` uses for the
outgoing forecast — **never appears in aged payables**. The lane's "AP mirror"
claim for D2 is therefore about POs. Pre-existing; ticket.

### [MINOR] Layering: an Application-layer guard throws a Presentation exception

`DocumentAllocationStateGuard.php:38-55` throws
`Illuminate\Http\Exceptions\HttpResponseException` built with the `response()`
helper, and is called from `PaymentAllocationService` (an Application service).
Prefer a domain exception mapped in `bootstrap/app.php` like
`UnreversibleDocumentGlException`. Same file, `:7`: a Treasury class type-hints
`App\Modules\Document\Domain\Document` — a cross-module Eloquent import
(CLAUDE.md rule 6), consistent with `PaymentController`'s pre-existing usage but
newly extended.

### [MINOR] `Shared/Contracts` depends on a module implementation

`app/Shared/Contracts/Accounting/DocumentGlReversalInterface.php:7` imports
`App\Modules\Accounting\Application\Services\AccountingService` purely to render
a `@see`. Use the FQCN in the docblock text instead.

### [MINOR] `whereOutstanding()` discards the only supporting index

`Document.php:705-708` replaces `where('balance_due','>',0)` — which has a
partial index (`2026_01_08_214145_add_balance_due_cache_trigger.php:70`,
`documents_balance_due_index … WHERE type='invoice' AND status='posted'`) —
with two correlated subqueries evaluated per row, and the callers then
`->get()->filter()` every matching document with two eager-loaded relations into
memory. Fine at demo-tenant scale; measure before a large tenant.

### [MINOR] Scale mixing in `AgedPayablesService::openBalance()`

`:358-366` returns the raw `(string) $document->balance_due` for the `Received`
accrual branch (already `bcformatStrict`'d at company scale upstream, `:234`)
but `$document->outstandingBalance($scale)` for everything else, then both are
accumulated at `self::DECIMAL_SCALE = 4`. Consistent today; brittle if the
accrual branch ever emits scale-4.

---

## Verification performed

| Check | Result |
|---|---|
| `tests/Feature/Accounting/Reports/{AgedOutstandingSource,AgedAgingBuckets,UpcomingPayments}Test` | 11/11 pass |
| `tests/Feature/Treasury/PaymentAllocationDocumentStateTest` + `tests/Feature/Accounting/DocumentCancellationGlReversalTest` | 10/10 pass |
| `PaymentTest`, `PaymentAllocationPrecisionTest`, `SmartPaymentIntegrationTest`, `DocumentPaymentStatusTransitionTest`, `MultiPaymentTest`, `SupplierPaymentGuardTest`, `PaymentControllerSpineTest` | 76 pass, 1 skipped |
| `DocumentCancelConsolidationTest`, `DocumentPostingServiceTest`, `Types/InvoiceDocumentTest` | 40 pass |
| `Compliance/FiscalHardeningE2ETest`, `Accounting/AgedPayablesAutoPoTest`, `Document/AgedReceivablesScalingTest` | 20 pass, 4 skipped |
| PHPStan (all changed app files, live-DB env) | `[OK] No errors` |
| Pint `--test` on changed files | pass |

**D4 sign, verified empirically** (Carbon 3.11.4):
`Carbon::parse('2026-06-01')->diffInDays(Carbon::parse('2026-07-06'), false) === 35`
and the reverse `=== -35`. The new receiver/argument order yields
`asOf − reference`, positive = overdue. `determineBucket()` untouched in both
services; the e2e ladder helper added at `finance-aged.spec.ts:317-323` matches
`determineBucket()` exactly (`<=30 current`, `<=60 days_30`, `<=90 days_60`,
`<=120 days_90`, else `over_90`).

**D2 formula parity, verified byte-for-byte** against both triggers:
`Document.php:679-682` vs `update_document_balance_due()`
(`2026_01_08_214145_…:32-47`) and `update_invoice_balance_due_from_credit_note()`
(`2026_05_27_100001_…:41-56`). Type-agnostic, joins
`credit_note_allocations` on `invoice_id` in both. `creditsAgainstDocument`
(`Document.php:686-689`) is correctly pinned to `invoice_id`, unlike the
type-switching `creditNoteAllocations()` at `:323-337`. Neither
`PaymentAllocation` nor `CreditNoteAllocation` uses `SoftDeletes`, so the raw
SQL bound and the Eloquent relations see the same rows.

**Coarse-bound false-negative probe (asked explicitly).** No realistic false
negative. On PostgreSQL both `documents.total` and the allocation amounts are
`numeric`, so `total − Σpa − Σcna > 0` is *exact* — the SQL bound and the bcmath
verdict cannot disagree in the excluding direction. On SQLite the expression is
evaluated in doubles with ~1e-16 relative error; to push a true outstanding of
`0.001` to `<= 0` you would need a document total above ~1e10, outside
`decimal(15,3)` practice. In the other direction (SQL says `>0`, bcmath says
`0`) the bcmath `->filter()` correctly drops the row — which is the direction
their comment describes and the only one that occurs. **Caveat:** the parity is
between the SQL bound and `outstandingBalance()`; it does **not** cover the
opening-balance divergence above, which is a *semantic* false positive
(over-inclusion of an already-settled portion), not a floating-point one.

**Tripwire flips.** `w7-concurrency.spec.ts:589-660` and
`finance-aged.spec.ts:145-200 / :247-360 / :390-446` are faithful inversions —
nothing weakened, and `MTP-GL-14` is strengthened (bucket equality against a
computed ladder replaces a blanket `toBe('current')`). One exception:
`MTP-CONC-06` asserts a property the system does not yet have on two other
routes (finding 1). Playwright not run, per instruction.

**Pre-existing-failure claims.** `Document/IngressPrecisionTest` — **13 errors,
confirmed**, all `ArgumentCountError: Too few arguments to
…CreateDocumentRequest::__construct(), 3 passed … and exactly 4 expected`
(`CreateDocumentRequest.php:24`), a class the diff does not touch.
`Treasury/BankStatementAggregateSchemaTest::test_migration_down_order_is_fk_safe_and_reapply_is_clean`
and
`Treasury/RepositoryMovementsEndpointTest::test_search_returns_allocation_capacity_for_manual_matching`
— **both fail at HEAD, confirmed**. Not run at base: causation excluded by path
disjointness (`git diff --name-only a84c1b53e..HEAD` matches no migration, bank
statement or repository-movement file) plus failure-site inspection. Consistent
with the claim; not independently reproduced at `a84c1b53e`.

## Test quality

New tests use `RefreshDatabase`, real models, `RolesAndPermissionsSeeder`
(`PaymentAllocationDocumentStateTest:50`), real permission grants (`:59`) and
real API calls — no faked payloads, no `assertTrue(true)`, nothing mocked that is
under test. Gaps to close with the fixes above: the opening-balance shape
(`total` ≠ `balance_due`, zero allocations); `split-payment` / `apply-deposit`;
credit-note allocation to a cancelled invoice; concurrent cancel.

## What to fix before merge

Guard `MultiPaymentService`'s two allocation writers (F-6 is still open on
`/documents/{id}/split-payment` and `/payments/{id}/apply-deposit`), stop
`outstandingBalance()` from overstating opening-balance documents, take a
`lockForUpdate()` before `reverseDocumentGl()`, guard
`CreditNoteService::allocateCreditNote()`, and make the unbalanced-original
refusal escapable (or at least stop it recommending a remedy that cannot work).
