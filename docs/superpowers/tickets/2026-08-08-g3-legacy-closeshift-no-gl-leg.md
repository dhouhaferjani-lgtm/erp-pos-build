# Ticket: legacy ShiftManagementService::closeShift() branch books no GL leg (gated on Treasury representation)

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

The two legacy server branches use **different expected-cash formulas**:

- the deprecated cash-count helper is takings-only and has no shipped client; it is not policy;
- the legacy branch's basis is `CashDrawerService::calculateExpectedCash()` (`:387-413`) —
  `opening + sales − refunds − deposits − payouts`, i.e. the whole drawer **including the opening
  float**.

The ruling is settled: cashiers count the whole drawer. The remaining blocker is Treasury
representation: opening float and drawer operations are still unbooked (SV-3/SV-4). Wiring this
variance leg before those flows would create a cash/GL mismatch.

See also `docs/superpowers/tickets/2026-07-31-cashdrawer-v3-expected-cash-blind.md`, which records
that `calculateExpectedCash()` is additionally blind to v3 activity (no v3 path writes
`pos_cash_drawer_operations`) and that its `scale()` uses a no-arg `getScale()` — fatal if ever
queued (rule 19). Any fix here should absorb that ticket rather than race it.

## Fix shape

Sequenced strictly AFTER SV-3/SV-4 represent the opening float and drawer operations in Treasury:

1. Keep device-authoritative terminals on the device close path; do not revive the deprecated
   takings-only server helper.
2. For the legacy whole-drawer branch, raise `CashCountRecorded` (or the V2 successor, rule 8) only
   after the cash/GL basis is represented, so the existing Treasury listener covers it unchanged.

Either way, do not wire this branch and the v3 branch independently: `pos_shifts.variance` must end
up with ONE definition across all three close paths, which is the invariant lane G3's C1 fix
established for the two it touched.
