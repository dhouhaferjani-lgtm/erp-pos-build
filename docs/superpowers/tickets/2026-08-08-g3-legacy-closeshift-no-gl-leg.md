# Ticket: legacy ShiftManagementService::closeShift() branch books no GL leg (gated on the count-semantics ruling)

Found by the treasury gate of DPA lane G3 (finding I8), 2026-08-08. Documented as a deliberate
carve-out in the lane report; filed here because the reviewer is right that silence is the wrong
outcome for a remediation-register entry.

`ShiftManagementService::closeShift()` (apps/api/app/Modules/POS/Domain/Services
/ShiftManagementService.php:168-185) has two branches. The cash-count branch
(`$cashCountAlreadyApplied === true`) preserves values written by the Z-report path, which is the
path that raises `CashCountRecorded` and therefore the path lane G3 wired. The **legacy** branch
computes a variance from `CashDrawerService::calculateExpectedCash()` and persists it to
`pos_shifts.variance` at `:181` **without raising the event** — so that variance still has no
658/758 leg, no justifying document and no movement.

Note also that the offline producer only fires when the payload actually carries counts —
`ZReportSyncController.php:256` guards on `$rawCashCounts !== null && $rawCashCounts !== []` — so a
count-less legacy close is doubly silent.

## Why it was NOT wired in G3 (and why that was the right call)

The two branches use **different expected-cash formulas**:

- the cash-count branch's basis is `ReportGenerationService::buildExpectedPerMethod()` — receipt
  payments only, i.e. the shift's takings;
- the legacy branch's basis is `CashDrawerService::calculateExpectedCash()` (`:387-413`) —
  `opening + sales − refunds − deposits − payouts`, i.e. the whole drawer **including the opening
  float**.

Wiring the legacy branch would therefore book exactly the number the outstanding owner ruling is
about (takings vs whole drawer). Doing it before the ruling would post the opening float to
658/758 on every legacy close, permanently — the failure mode the lane's kill switch exists to
prevent.

See also `docs/superpowers/tickets/2026-07-31-cashdrawer-v3-expected-cash-blind.md`, which records
that `calculateExpectedCash()` is additionally blind to v3 activity (no v3 path writes
`pos_cash_drawer_operations`) and that its `scale()` uses a no-arg `getScale()` — fatal if ever
queued (rule 19). Any fix here should absorb that ticket rather than race it.

## Fix shape

Sequenced strictly AFTER the owner ruling on count semantics:

1. If the ruling is **takings**: the legacy branch's basis is wrong for this purpose regardless of
   G3, and the honest fix is to stop persisting a server-computed variance for terminals that are
   device-authoritative (the disposition `2026-07-31-cashdrawer-v3-expected-cash-blind.md` already
   proposes), rather than to book it.
2. If the ruling is **whole drawer**: reconcile the two formulas first — they must not both remain
   authoritative for the same column — then raise `CashCountRecorded` (or the V2 successor, rule 8)
   from the legacy branch so the existing Treasury listener covers it unchanged.

Either way, do not wire this branch and the v3 branch independently: `pos_shifts.variance` must end
up with ONE definition across all three close paths, which is the invariant lane G3's C1 fix
established for the two it touched.
