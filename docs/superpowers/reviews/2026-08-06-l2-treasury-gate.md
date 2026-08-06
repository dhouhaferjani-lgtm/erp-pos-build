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

---

## ROUND 2 — closure verification (2026-08-06, Sonnet narrow re-check, NOT a full Opus re-gate)

**Verifier:** treasury-reviewer (adversarial closure check only — scope limited to the items
named in this gate record; no new-area audit performed). Worktree
`/Users/houssamr/Projects/syneriva/apps/erp.fix-l2-ar`, HEAD `b76749ac8` (8 commits on top of
the round reviewed above). Tests run BY PATH against **live PostgreSQL**
(`DB_CONNECTION=pgsql`, `-c phpunit-pgsql.xml`, `127.0.0.1:5433/autoerp`), not the SQLite
default, per this round's explicit instruction.

### CRITICAL 1 (F-6 on MultiPaymentService) — CLOSED, verified

`MultiPaymentController::createSplitPayment()` and `::applyDeposit()` both call
`DocumentAllocationStateGuard::assertAllocatable($document)` **before** the try/catch
(`MultiPaymentController.php` — guard calls read at the top of each action, ~line 174 and
~line 378). Confirmed the stated reasoning against the actual catch block: both actions end in
`catch (\Exception $e) { return response()->json(['error' => $e->getMessage()], 422); }` —
`HttpResponseException` extends `RuntimeException` extends `Exception`, so a guard call placed
*inside* that try would have been swallowed and re-emitted as `{"error": ""}` (empty message),
losing the structured `{error:{code,message,details}}` envelope. Placing it before the try is
the only way to preserve `DOCUMENT_NOT_ALLOCATABLE`. `MultiPaymentService::createSplitPayment()`'s
`:54`-area total-match check now reads `$document->outstandingBalance($this->documentScale($document))`,
with a new `documentScale()` helper that correctly calls `getScaleSafe($document->currency, 3)`
(never the bare no-arg `getScale()` — rule 20 respected).

`tests/Feature/Treasury/PaymentAllocationDocumentStateTest.php` run against live PG:
**6/8 pass, 2 fail** (not 8/8 as the file's SQLite run shows — see "Test-quality regression
found" below). Both probed paths (cancelled invoice via split-payment, cancelled invoice via
apply-deposit) correctly return 422 with `error.code = DOCUMENT_NOT_ALLOCATABLE` on live PG —
these specific assertions pass in both failing tests' surrounding cases and in the two
dedicated tests that exercise exactly this probe
(`test_the_split_payment_path_refuses_a_cancelled_document`,
`test_the_apply_deposit_path_refuses_a_cancelled_document` — verified passing individually).

### CRITICAL 2 (D2 opening-balance regression) — CLOSED, verified

`Document::outstandingBalance()` (`Document.php`) now returns `CurrencyScale::bcround((string)
$this->balance_due, $scale)` whenever `balance_due !== null` (authoritative), and only falls to
the allocation-derived computation when it is genuinely `NULL`. Byte-read against both writers
named in the gate: the trigger (unchanged) and `ArApOpeningService`'s `open_amount` write — both
produce a non-NULL `balance_due` that is now taken as-is. Consumers probed:
- `AgedOutstandingSourceTest` (opening-balance shape: total 1500 / balance_due 300 / zero
  allocations) — **15/15 pass on live PG** (run together with `AgedAgingBucketsTest`,
  `UpcomingPaymentsTest`).
- `PaymentController::store()`'s per-allocation cap now reads
  `$document->outstandingBalance($this->scaleResolver->getScaleSafe((string) $document->currency,
  3))` — rule-19/20 compliant, read at the source.
- `CloseInvoiceWithToleranceService::close()`'s rerouted `:58`-area read is
  `$invoice->outstandingBalance(3)` — verified in place, guard runs first.
- **PaymentTest, PaymentAllocationPrecisionTest, SmartPaymentIntegrationTest,
  DocumentPaymentStatusTransitionTest, MultiPaymentTest, SupplierPaymentGuardTest,
  PaymentControllerSpineTest: 76/76 pass on live PG** (the base gate reported 76 pass + 1
  skipped; on PG this round nothing skipped, 76/76).

**The original over-allocation test's NULL-cache premise does NOT hold on live PostgreSQL** —
see finding below. The underlying fix logic (non-null-authoritative / null-fallback-compute) is
still verified correct by direct code reading and by the passing opening-balance and aged-report
tests; only the specific SQLite-only reproduction of "an allocation exists but the cache stayed
NULL" is a false premise on real PG, because the PL/pgSQL trigger fires on ANY
`payment_allocations` DML (any INSERT, regardless of writer/ORM), not only on the app's own code
paths. The narrower real-world NULL-forever case (a posted document with ZERO allocations ever)
is unaffected and still correctly handled.

### [IMPORTANT] Test-quality regression found by this round — 2 of 8
`PaymentAllocationDocumentStateTest` tests fail on live PostgreSQL (pass on SQLite; this is
exactly the class of drift CLAUDE.md's testing conventions and rule 20 exist to catch)

1. `test_the_split_payment_path_refuses_a_cancelled_document` — errors on live PG:
   `SQLSTATE[42703]: column "document_id" does not exist` on `assertDatabaseMissing('payments',
   ['document_id' => $invoice->id])` (`PaymentAllocationDocumentStateTest.php:301`). The
   `payments` table has never had a `document_id` column — that column lives on
   `payment_allocations` (confirmed against
   `database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:171-179` and a live
   `\d payments` on the PG instance). On SQLite the same query returns `false` (no error) rather
   than throwing — verified directly (`PRAGMA table_info(payments)` confirms no such column
   either; the query builder simply produces an empty result set instead of erroring on SQLite's
   PDO driver for this specific pattern). **This is a broken/misdirected assertion, not a
   production defect** — the test's other assertions
   (`PaymentAllocation::where('document_id', ...)->count() === 0`, cancelled status unchanged)
   already correctly cover the no-payment-was-created invariant. Fix: change the table to
   `payment_allocations`, or delete the redundant line.
2. `test_the_split_payment_total_check_uses_the_computed_outstanding_not_the_cache` — fails on
   live PG: `assertNull($invoice->refresh()->balance_due, 'The cache never fired on this manual
   allocation')` gets `150.000`, not `null`
   (`PaymentAllocationDocumentStateTest.php:337`). The test's premise — that
   `PaymentAllocation::create(['document_id' => ..., 'amount' => '50.000'])` leaves
   `balance_due` NULL because "the cache never fired on this manual allocation" — is **false on
   real PostgreSQL**: the row-level trigger fires on the INSERT regardless of which code wrote
   it (Eloquent `::create()` is still a plain `INSERT`). The scenario only reproduces on SQLite,
   which has no trigger at all. **Production code is unaffected** — the assertion after the split
   (`assertCreated()` + the 200.000-total sum check) still passes on live PG, because
   `outstandingBalance()`'s non-null-authoritative branch and its null-fallback-compute branch
   agree numerically here (both derive 150.000). Fix: either rewrite the precondition to state
   what's actually true on PG (`balance_due` becomes `150.000` via the trigger, and the SPLIT
   TOTAL CHECK must use that value rather than `total`), or explicitly seed the NULL-cache case
   the way `ArApOpeningService`-style rows would (a document that has never had *any*
   `payment_allocations` DML), which is the only PG-real NULL scenario.

**Neither issue changes the verdict on CRITICAL 1 or CRITICAL 2** — both production fixes are
independently verified correct by direct code reading, by the passing aged/opening-balance
suites, and by the corrected/individually-verified guard tests. But the closure claim
"PaymentAllocationDocumentStateTest 8/8" should be read as "8/8 on SQLite, 6/8 on live
PostgreSQL, 2 test bugs" — fix before merge (mechanical, no design question).

### Item 3 — DocumentPostingService::cancel() lockForUpdate — CLOSED, verified LIVE

`$document->refresh()` replaced with
`Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail()`, taken before
`reverseDocumentGl()` is called, exactly as specified.

**The two-process `pcntl_fork` concurrency test was run against live PostgreSQL and PASSED**:
`DocumentCancellationGlReversalTest::test_concurrent_cancels_cannot_double_reverse_the_ledger`
— `OK (1 test, 8 assertions)` — and the full file: `OK (7 tests, 38 assertions)`. This is a
genuine independent execution (not a re-read of the implementer's claim): `pcntl` is available
in this environment, `phpunit-pgsql.xml` connects to a live PG instance at
`127.0.0.1:5433/autoerp`, and the fork test's own child/parent race actually exercised the row
lock — the child cancel serializes behind the parent's lock and takes the idempotent early
return, and exactly one `DocumentCancellation`-sourced `JournalEntry` exists afterward. This is
the strongest possible verification of I-1/finding-3's fix.

### Item 4 — CreditNoteService::allocateCreditNote + CloseInvoiceWithToleranceService guards — CLOSED, verified

Both guard calls placed correctly (`CreditNoteService.php` — after the `findOrFail`+`lockForUpdate`
on the source invoice, inside the transaction; `CloseInvoiceWithToleranceService.php` — after the
document's `lockForUpdate()`, before the `outstandingBalance()` read). DI: both services gained a
4th constructor param `DocumentAllocationStateGuard $allocationStateGuard`; grepped `app/` and
`tests/` for `new CreditNoteService(` / `new CloseInvoiceWithToleranceService(` — the only manual
construction site is `tests/Unit/Treasury/CloseInvoiceWithToleranceServiceTest.php:396`, which
was updated to pass `$this->app->make(DocumentAllocationStateGuard::class)`. `DocumentAllocationStateGuard`
is a concrete class with no constructor dependencies, so every other site (both services'
production constructors) resolves it automatically via the container — no explicit binding
needed, none was added, none is missing.

Both red→green tests are genuine (no `assertTrue(true)`, no mocking the thing under test):
`CreditNoteAllocationTest::it_refuses_to_allocate_a_credit_note_against_a_cancelled_invoice` and
`CloseInvoiceWithToleranceServiceTest::test_rejects_a_cancelled_invoice_even_with_a_positive_partial_balance`
both assert the structured 422 envelope, assert zero allocation/JE rows were written, and assert
the document's state was not rewritten. **Both pass on live PostgreSQL** (run together with the
rest of the credit-note/tolerance suite: 29/29 total in that batch, both failures isolated to
`PaymentAllocationDocumentStateTest` above).

**[IMPORTANT] New cross-module coupling, not flagged in the original gate** (the fix predates
this finding): `CreditNoteService` (Document module, `Application/Services`) now directly
imports and constructor-injects `App\Modules\Treasury\Application\Services\DocumentAllocationStateGuard`
— a concrete Treasury Application service, not a `Shared/Contracts` interface. The original gate's
MINOR finding already flagged the REVERSE direction (`DocumentAllocationStateGuard` type-hints
`App\Modules\Document\Domain\Document`) as debt; this fix adds the opposite direction, so the two
modules are now coupled **both ways** through this one guard class — worse than the pre-existing
one-directional debt, and not caught by `deptrac.yaml` (its ruleset is glob-based per hexagonal
tier, explicitly NOT enforcing cross-module coupling — confirmed by reading the config's own
header comment). Doesn't break anything today and mirrors the `DocumentGlReversalInterface`
precedent in spirit, just not in shape — the cheaper fix would have been a
`Shared/Contracts/Treasury/DocumentAllocationGuardInterface` the same way GL reversal got one.
Ticket-worthy, not blocking.

### Item 5 — duplicate-route deletion + pinning assertion — CLOSED, verified

`Document/Presentation/routes.php` no longer registers `reports/aged-receivables` (confirmed by
direct grep — only `Accounting/Presentation/routes.php:178-180` registers it now).
`AgedOutstandingSourceTest::test_the_aged_receivables_route_resolves_to_the_accounting_controller`
asserts `Route::getRoutes()->getByName('reports.aged-receivables')` resolves to
`Accounting\...\ReportsController`. Confirmed this is the RIGHT shape even though it would have
passed before the deletion too (provider load order already made Accounting win) — its actual
job is guarding against a FUTURE regression (someone reordering `bootstrap/providers.php`, or
someone re-adding a same-named Document route), not proving today's deletion changed behavior.
15/15 pass on live PG in the same run as the CRITICAL 2 aged-report suites.

### GL items (I-2, I-5, exception message) — CLOSED, verified (see companion GL gate file for the fuller GL-gate-scoped closure)

- `balanceAssertable = $this->residualPlan($document, $scale)->balanceAssertable;`
  (`AccountingService.php`, inside `reverseDocumentGl()`) — single source, confirmed reused
  rather than reimplemented. `DocumentGlResidualPlan::$balanceAssertable` defaults `true` for
  every non-lineless constructor call in `residualPlan()`, so behavior for documents with lines
  is unconditionally unchanged (verified by reading every `return new DocumentGlResidualPlan(...)`
  branch in `residualPlan()` — none pass a 7th argument except the lineless branch's explicit
  `false`). `residualPlan()`'s account lookups (`Account::findByPurpose`) return `null` rather
  than throwing on a missing account, so calling it on the cancel path introduces no new
  exception surface versus the hand-rolled predicate it replaced; it does add a handful of extra
  read queries per cancel (revenue/VAT/rounding-account lookups whose result is discarded except
  for the boolean flag) — negligible cost, not flagged as a defect.
- `calculateHash($freshEntry, $previousHash, $document->currency)` — confirmed the 3rd arg is
  now passed, closing the no-arg-`getScale()` divergence from `verifyChain()`'s explicit-currency
  path. Grepped the whole diff for `getScale(` — the only remaining no-arg call
  (`MultiPaymentService.php` private `scale()` helper) is PRE-EXISTING, untouched by this round
  (confirmed via `git diff a84c1b53e..HEAD` — that line is unchanged context, not a `+` line),
  consistent with the gate's own note that this class of debt is inherited, not new.
- `UnreversibleDocumentGlException::forUnbalancedOriginal()`'s message no longer says "Post a
  correcting entry first" — now says "Contact accounting/engineering support to correct the
  underlying ledger entry manually before retrying," which does not claim a self-service path
  exists. Matches the gate's ruling (c): stop recommending an impossible remedy. The escape
  hatch itself (options a/b) is correctly NOT implemented in-lane — ticketed as required.

### Item 7 — ticket files — CLOSED, verified

All 5 exist at `docs/superpowers/tickets/2026-08-06-l2-*.md` (not under `reviews/` — the diff
stat's truncated paths were misleading at a glance; confirmed via `find`). Read all 5 in full:
each faithfully restates its source finding (I-3 VAT-declaration desync + the period-refusal
condition from ruling 6a's second attached condition; I-4/6c COGS-and-AP-unreversed as two
explicit parts; the correcting-entry escape hatch with both suggested fixes (a)/(b) preserved
verbatim from the gate's ruling; the GL-gate MINOR bundle (M-2/M-3/M-4/6b/AP-report-PO-only, all
five items present); and the remaining `balance_due` consumer sweep gap, naming all four sites
the treasury gate identified (`PaymentController::storeMultiple()` x3,
`PaymentAllocationService::getOpenInvoices()`, `::getInvoiceBalance()`,
`SmartPaymentController::previewAllocation()`). None of the deferred items were silently dropped
or softened.

### Item 8 — regression sweep — CLOSED, verified on live PostgreSQL

| Suite | Result (live PG) |
|---|---|
| `DocumentGlPreflightTest` + `InvoiceGLIntegrationTest` + `CreditNoteGLIntegrationTest` + `InvoiceAndCreditNoteGLIntegrationTest` | 37/37 pass |
| `FiscalHardeningE2ETest` | 13/13 pass (pre-existing deprecation notices, no failures) |
| `DocumentCancelConsolidationTest` + `DocumentPostingServiceTest` | 29/30 — **1 pre-existing failure, path-disjoint from this round's diff**: `test_revert_clean_purchase_order_to_draft` fails on live PG with `invalid input syntax for type uuid: "user-1"` (a test fixture bug — non-UUID string forced into a UUID column, tolerated by SQLite's loose typing, rejected by PG). `DocumentPostingServiceTest.php` does not appear in `git diff a84c1b53e..HEAD --name-only` — untouched by this lane. Not this round's regression. |
| `RefundResidualTenantIsolationTest` + `InvoicePostedListenerTest` + `Types/InvoiceDocumentTest` | 37/37 pass |
| `AgedPayablesAutoPoTest` + `AgedReceivablesScalingTest` | 7/7 pass |
| `AgedOutstandingSourceTest` + `AgedAgingBucketsTest` + `UpcomingPaymentsTest` | 15/15 pass |
| `PaymentTest` + `PaymentAllocationPrecisionTest` + `SmartPaymentIntegrationTest` + `DocumentPaymentStatusTransitionTest` + `MultiPaymentTest` + `SupplierPaymentGuardTest` + `PaymentControllerSpineTest` | 76/76 pass |
| `PaymentAllocationDocumentStateTest` | 6/8 pass — 2 test-quality bugs, see above (production code unaffected) |
| `CreditNoteAllocationTest` + `CloseInvoiceWithToleranceServiceTest` | all pass (batched with the above at 29/29 minus the 2 treasury-file failures) |
| TypeScript typecheck of `e2e/money-campaign/w7-concurrency.spec.ts` + `finance-aged.spec.ts` | clean, 0 errors (`npx tsc --noEmit --strict`, Playwright not run per instruction) |
| PHPStan level 8, all 18 changed non-test PHP files, live-DB env | `[OK] No errors` |
| Pint `--test`, same file set | `{"result":"pass"}` |

Playwright itself was not run (per instruction). `BankStatementAggregateSchemaTest` and
`RepositoryMovementsEndpointTest` (the base gate's noted pre-existing failures) were not
re-verified this round — out of scope for this closure check, and path-disjointness from this
round's diff still holds (`git diff a84c1b53e..HEAD --name-only` touches no migration, bank
statement, or repository-movement file).

### ROUND 2 VERDICT: spec ✅ + quality APPROVED, with two mechanical test fixes owed

All items named in this gate's "What to fix before merge" are closed and independently
re-verified, including the two hardest-to-fake claims (the live two-process `pcntl_fork`
concurrency test, and the D2 opening-balance byte-parity). One class of NEW finding surfaced by
this round's live-PostgreSQL verification: 2 of 8 `PaymentAllocationDocumentStateTest` tests fail
against real PG (a wrong-table assertion, and a false NULL-cache precondition) — both are test
bugs, not production defects, and both are mechanical, small fixes. Recommend fixing them in the
same PR (or as a fast one-line follow-up commit) rather than blocking the merge on them, since
the underlying CRITICAL 1/CRITICAL 2 production code is independently verified correct by
several other passing live-PG suites plus direct code reading. This is a narrow closure check
under the program's Sonnet-capped posture (Opus capped until Aug 9) — nothing found here rises
to a level that needs a full Opus re-gate; the test-bug finding is mechanical enough for the
implementer (or a fast follow-up) to fix without new design review.
