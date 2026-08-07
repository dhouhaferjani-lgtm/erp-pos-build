# R2-F1 residuals — cancellation period-refusal lane

**Filed:** 2026-08-07 by the R2-F1 lane after its consolidated fix round
(both gates APPROVE-WITH-FIXES, all blocking items addressed).
**Lane status:** merged-ready on `fix/r2f1-cancel-period-refusal`, unpushed.

---

## RUNBOOK — refusal strength is bounded by period-row coverage (taxation gate m-5)

**Operators and accountants must know this before trusting the guard.**

`VatPeriodCancellationGuard` refuses a cancellation only when a `vat_periods` row
COVERS the document's `document_date` and that row is CLOSED or FILED. A date with
no covering row is permitted — deliberately, and matching the house posture of
`FiscalPeriodResolverService::isDateInClosedPeriod()` ("absence of configuration is
not the same as a deliberately closed period"). Fail-closed on absence would make
every document uncancellable on every tenant that has not begun declaring VAT.

The consequence: **a tenant whose generated periods have GAPS has cancellable
holes.** If periods exist for January and March but not February, a February
invoice can still be cancelled after January and March are filed, and its GL
reversal lands in the current period exactly as the guard was built to prevent.

Operational requirements:

1. `VatPeriodManagementService::generatePeriods()` must be run for a CONTIGUOUS
   span covering every date on which the company issues documents. Verify no gaps
   before relying on the lock.
2. Add a pre-filing check to the VAT close runbook: for the fiscal year being
   filed, assert `vat_periods` covers every day with no gap and no unintended
   overlap.
3. A backfilled or historically imported document dated BEFORE the earliest
   generated period is not protected at all. If historical documents are loaded,
   generate periods back to the earliest `document_date`, or accept the exposure
   explicitly.

This is a coverage property of the DATA, not a defect in the guard — no code change
closes it. A detection query (companies with gaps between consecutive
`period_end` + 1 day and the next `period_start`) belongs in the Phase-0
data-readiness sweep.

---

## Out-of-lane findings (recorded, NOT fixed by F1)

1. **`RefundController`'s generic catch returns a non-standard error envelope.**
   `cancelInvoice()` / `cancelCreditNote()` flatten every failure into
   `{"error": <message>, "code": <message>}` — `code` carries the message text,
   not a code. Load-bearing: `DocumentCancelConsolidationTest` asserts on it
   (`code` == `DOCUMENT_HAS_PAYMENTS`). F1 only carved its own typed exception out
   of that branch by re-throwing.
2. **No HTTP cancel endpoint exists for supplier invoices, supplier credit notes,
   expenses or purchase orders.** The only cancel routes are `invoices.cancel` and
   `credit-notes.cancel`; the only in-process callers of
   `DocumentPostingService::cancel()` are `RefundService` (invoice/credit-note) and
   `SalesOrderService`. See relay note (ii) on R-c — c2's purchase arm is
   forward-looking.
3. **`tests/Feature/Accounting/DocumentCancellationGlReversalTest.php:126`** sets
   `'selling_price'` on `Product`; the model has `sale_price` and no
   `selling_price` fillable, so the value is silently dropped by mass assignment.
   Latent fixture bug.
4. **deptrac: +2 distinct violations, +3 raw occurrences** (`dev` 102 → branch
   105 by occurrence count; 67 → 69 by distinct file+message). Both new ones sit
   in `App\Shared\Contracts\Taxation\DocumentPeriodLockInterface`, depending on
   `Modules\Document\Domain\Document` (counted twice — once per method signature)
   and on `Modules\Taxation\Domain\Exceptions\DocumentPeriodLockedException` —
   structurally identical to the pre-existing, gate-approved violations from
   `DocumentGlPreflightInterface`, `DocumentGlReversalInterface` and
   `TreasuryMovementServiceInterface`. Consistency with the house contract pattern
   was chosen over avoiding the ratchet.
5. **Income resolves its period by `document_date`, but its GL entry may be
   dated `payment_date`** (GL re-gate I-5 follow-on). `createFromIncome()` stamps
   `entry_date = $metadata->payment_date ?? $income->document_date` (`:4106`) —
   the only GL entry point that does not key purely on `document_date`. When an
   Income's `payment_date` and `document_date` straddle a period boundary, the
   guard inspects the document's period rather than the entry's, so a
   FILED-period *entry* could still be withdrawn if the *document* sits in an
   open period (or vice versa, over-refusing).

   NOT fixed here: the round-3 ruling was to adopt the gate's fix as written
   (move Income into the locked set), and making the contract's date semantics
   per-type changes what `DocumentPeriodLockInterface` means — a ruling, not a
   refactor. Locking on `document_date` is strictly better than the pre-I-5
   state, where Income was not locked at all. **Needs a ruling** before an
   Income-cancel lane ships; the natural shapes are (a) resolve per type via a
   small `accountingDateFor(Document)` seam, or (b) declare `document_date`
   canonical and make `createFromIncome()` stop using `payment_date`.

6. **Posting (as opposed to cancelling) a new document into a CLOSED/FILED
   `vat_periods` row is still unguarded.** Only the GL side has a posting guard,
   and it keys on the separate `fiscal_periods` table. Out of F1's scope — the
   ticket asked only about cancel — but the asymmetry is real.
7. **Gate item 6d could not be applied as written.** The gate asked to drop the
   `use ...VatPeriodCancellationGuard` import from `DocumentPeriodLockInterface`
   and inline the FQCN in the `@see`. Pint's `fully_qualified_strict_types` fixer
   does the OPPOSITE — it rewrites docblock FQCNs back into imports — so the edit
   is reverted by every `pint` run. The import is used (by the `@see`) and costs
   nothing in deptrac terms (only the two ModuleDomain edges above are counted).
   Kept as Pint produces it.

## Related

- `docs/superpowers/tickets/2026-08-07-cancel-reversal-bypasses-closed-fiscal-period.md`
  (GL gate I-1, assigned to F2)
- `docs/superpowers/tickets/2026-08-06-l2-gl-vat-declaration-desync.md` (ruling 6a)
- `docs/superpowers/tickets/2026-08-07-round2-rulings-record.md` (R-c + F1's relay notes)
