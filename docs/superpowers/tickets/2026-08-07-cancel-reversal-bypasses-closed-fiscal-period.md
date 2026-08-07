# Ticket: a cancellation reversal can seal into a CLOSED fiscal period

**Filed:** 2026-08-07, by the R2-F1 lane's dual-gate round (GL gate finding I-1,
confirmed by probe during the consolidated fix round).
**Status:** OPEN. **Assigned to: R2-F2** (joins the reversal audit/ordering
follow-up family that lane already owns).

## What happens

`GeneralLedgerService::postEntryNow()` refuses to post a journal entry whose
`entry_date` falls inside a `fiscal_periods` row that exists and is
CLOSED or LOCKED
(`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3265-3267`,
via `FiscalPeriodResolverService::isDateInClosedPeriod():278-291`, throwing
`ClosedFiscalPeriodException`). That is the Treasury spine's BLOCKER-2 guard.

The L2 lane's cancellation reversal **does not go through it**.
`AccountingService::reverseDocumentGl()` builds and seals the REVCAN entry with
`JournalEntry::create()` directly
(`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:933-947`),
allocating `chain_sequence` and `previous_hash` itself and never calling
`postEntryNow()`. The entry is written with `'entry_date' => now()`.

So: if the CURRENT fiscal period is CLOSED or LOCKED, cancelling a posted
invoice still seals a `REVCAN-…` entry into it. Every GL report slices on
`journal_entries.entry_date`, so a closed period's trial balance, P&L and
VAT-payable balance can still move after closing — which is precisely what the
BLOCKER-2 guard exists to prevent on every other posting path.

## Why this is NOT R2-F1's to fix

R2-F1 implements GL gate ruling 6a's second condition: refuse a cancellation
whose ORIGINAL document sits in a non-OPEN **`vat_periods`** row. That guard
(`VatPeriodCancellationGuard`) is orthogonal to this one:

| | protects | table | shipped |
|---|---|---|---|
| R2-F1's guard | the period the ORIGINAL document sits in (`document_date`) | `vat_periods` | yes |
| BLOCKER-2 guard | the period the ENTRY is dated into (`entry_date`) | `fiscal_periods` | yes, but bypassed here |

R2-F1's docblock originally claimed the fiscal-period guard covered the
reversal's own `now()` date. It does not. The claim has been corrected in
`VatPeriodCancellationGuard`'s class docblock; this ticket carries the actual
defect.

The hole is **pre-existing** — it arrived with the L2 lane's reversal, not with
R2-F1 — and closing it means touching the reversal's write path, which is F2's
blast radius (F2 already owns the reversal audit/ordering/coupling follow-ups).

## Suggested fix

Either route `reverseDocumentGl()`'s entry through `postEntryNow()` (preferred —
one posting path, one guard, one chain allocator), or lift the
`isDateInClosedPeriod()` check into `reverseDocumentGl()` before it allocates a
`chain_sequence`. Note the second option duplicates the guard and will drift.

Whichever is chosen, decide what the refusal means for the user: a cancel that
cannot be reversed must be refused as a whole (the L2 lane already established
that shape — the reversal runs inside the cancel transaction, so throwing rolls
the cancel back cleanly), with a message pointing at a credit note.

## Related

- `docs/superpowers/tickets/2026-08-06-l2-gl-gate-minor-followups.md` — the
  reversal follow-up family this joins (§13-65, 100-129).
- `docs/superpowers/tickets/2026-08-06-l2-gl-vat-declaration-desync.md` — ruling
  6a, whose second condition R2-F1 implements.
- `docs/superpowers/tickets/2026-08-07-round2-rulings-record.md` — R-c.
