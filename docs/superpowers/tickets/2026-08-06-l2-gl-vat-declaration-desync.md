# Ticket: GL cancellation reversal and the VAT declaration silently disagree

**Filed:** 2026-08-06, by the L2 AR-integrity fix lane's dual-gate round, from
`docs/superpowers/reviews/2026-08-06-l2-gl-gate.md` finding I-3 and ruling 6a.
**Status:** OPEN — required ticket before merge per the GL gate's ruling 6a.

## What happens

`EloquentVatDataRepository::aggregateByRateAndDirection()`
(`apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:26-44`)
aggregates the VAT declaration from `document_tax_details` joined to `documents`,
filtered on **`d.document_date` between the requested range**, with **no status
filter** — a cancelled document's tax-detail rows are still summed as if the
document were live. Journal entries never enter this aggregation at all.

The L2 lane's `AccountingService::reverseDocumentGl()` mirrors a cancelled
document's GL legs (including the VAT credit) into a NEW entry dated
`entry_date = now()` (the cancellation's own date — ruled correct in gate ruling
6a, see below), keyed on `source_type = 'DocumentCancellation'`.

Put together: cancelling a January invoice in March

- debits `4457` (VAT collected) in the GL, dated **March** (the reversal's
  `entry_date`), and
- leaves **January's** declared output VAT in `document_tax_details`
  **completely unchanged** (the aggregation keys on the document's own
  `document_date`, and the row itself is never modified by cancellation), and
- **March's** declaration also sees nothing, because the aggregation reads
  `document_tax_details`, not `journal_entries`, and no tax-detail row was
  written for the cancellation.

Cumulative GL VAT-collected and cumulative declared VAT now permanently disagree
by the cancelled amount, with no reconciliation surface anywhere in the codebase.

**Before this lane**, cancel wrote no GL at all, so the two numbers agreed (both
wrong in the same direction — the cancelled invoice's VAT stayed in both the GL
and the declaration forever). This lane's F-6/(c) fix (GL reversal on cancel) is
still net-positive — it closes off unreversed-revenue-in-the-ledger, a worse
defect — but it opens this new, narrower one.

## Why this ships without a fix

Reconciling the GL and the VAT-declaration aggregation is a cross-cutting design
question (does the declaration start reading `journal_entries` instead of
`document_tax_details`? does cancellation write a negative `document_tax_details`
row? does the reversal's `entry_date` change?) that the L2 lane's dual-gate scope
does not cover, and the GL gate's ruling 6a is explicit that changing the
reversal's `entry_date` to the original document's date is NOT the fix (see
below) — so this cannot be closed as a one-line change alongside the merge gate.

## Ruling on `entry_date` (gate ruling 6a, already decided — do not re-litigate)

**`entry_date = now()` is correct; keep it.** `vat_periods` carries
`OPEN → CLOSED → FILED` (`database/migrations/tenant/2026_03_23_200000_create_vat_periods_table.php:21,33-36`)
and every GL report slices on `journal_entries.entry_date`
(`GeneralLedgerReportService.php:208-211,269,296`). Back-dating the reversal to
the invoice's original date would retroactively rewrite a period that may
already be CLOSED or FILED — changing a trial balance, P&L or VAT-payable GL
balance under a filed declaration, which a compliance-ready ledger must never
do. `now()` puts the correction in the period the withdrawal actually happened
in, matching the house pattern (`GeneralLedgerService::reverseInventoryWriteOffEntry`).

## Second condition attached to ruling 6a — also unresolved

In FR/TN, an invoice whose VAT period is already CLOSED/FILED is not
*cancelled* at all in real accounting practice — it is credited (avoir). Today
nothing stops `DocumentPostingService::cancel()` from cancelling a document
whose `document_date` falls in a CLOSED/FILED `vat_periods` row; no period-lock
infrastructure exists on the cancel path (`DocumentPostingService.php:143-145`
checks only `hasBlockingAllocations()`).

## Suggested fix (two parts, either can land independently)

1. **Reconciliation:** either extend `aggregateByRateAndDirection()` to also
   read cancellation-reversal journal entries for the requested `entry_date`
   window (parallel structure, not touching the `document_tax_details` path),
   or write a `document_tax_details` row for the reversal itself so the existing
   aggregation picks it up automatically at the reversal's own date. The Iatter
   keeps one aggregation path but couples `document_tax_details` semantics to a
   cancellation event they were never designed to describe (currently one row
   per document, immutable snapshot) — needs a fiscal/product ruling.
2. **Period lock:** add a guard to `DocumentPostingService::cancel()` (or to
   `AccountingService::reverseDocumentGl()`) that refuses cancellation once
   `vat_periods.status != OPEN` for the document's `document_date`, with the
   error pointing the user at "issue a credit note instead." This makes the
   `now()` dating decision unambiguously safe (a cancel can then only ever
   reverse VAT that is still in an OPEN period) and is the more urgent of the
   two — it is a correctness/compliance gap independent of whether part 1 ever
   lands.

## Related

- `docs/superpowers/reviews/2026-08-06-l2-gl-gate.md` (I-3, ruling 6a)
- `docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md` (F-6, the
  cancel-GL-reversal fix this ticket is downstream of)
