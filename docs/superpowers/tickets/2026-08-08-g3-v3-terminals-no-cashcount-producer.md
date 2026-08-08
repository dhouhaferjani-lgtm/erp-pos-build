# Ticket: v3 / cutover terminals raise no CashCountRecorded — shift-variance GL leg is unwired there (PRE-ENABLE)

Found by the fiscal-pos gate of DPA lane G3 (first review I1, carried to the re-review), 2026-08-08.
Accepted as an in-spec carve-out for the lane — the G3 brief named exactly two trigger paths — but
recorded here because the lane's stated purpose is "the wire", and it is unwired on the
architecture the program is cutting over to.

`CashCountRecorded` has exactly two producers across `app/` and both are LEGACY-gated:

- `ReportGenerationService.php:415` — the live server-Z path.
- `ZReportSyncController.php:451` — the offline Z-report mirror, which hard-409s any terminal with
  `fiscal_schema_version >= 3` at `:75-84` (`Z_SESSION_DEVICE_AUTHORITY_REQUIRED`).
- `SyncController.php:53-63` does the same for shift close.

The v3 replacement, `POS/Application/Projections/ZReportProjection.php:158-161`, projects
`cash_count.variance_amount` onto `report_data` and raises **no** `CashCountRecorded`. So for a
device-authoritative terminal, shift-close cash variance still books nothing — the exact register
gap G3 exists to close.

Commercially decisive open question the reviewer could not settle (their CV3, and it is worth
answering FIRST): **does any tenant actually run a `fiscal_schema_version < 3` terminal today?** If
none does, this lane is currently inert in production regardless of the kill switch, and this
ticket is not a follow-up — it is the whole deliverable.

## Fix shape

Raise a cash-count/variance domain event from the v3 Z-session projection so the existing Treasury
listener covers cutover terminals unchanged. The listener needs nothing new: it already takes its
amount from `CashCountRecorded::$aggregateVariance` and its attribution from
`$tenderBreakdown[].paymentMethodId`, both of which the v3 `cash_count` payload can supply.

Two constraints that must not be lost:

1. **The projection runs in a queue worker with NO CompanyContext** (CLAUDE.md rule 20). The
   listener is already written for that reality (explicit currency everywhere, no
   `CompanyContext` read), so the event must carry `tenantId` / `companyId` / `currencyCode`
   explicitly — as `CashCountRecorded` already does.
2. **Events are immutable (rule 8).** If the v3 payload cannot populate `CashCountRecorded`'s
   existing constructor faithfully, mint `CashCountRecordedV2` rather than reshaping it, and
   register the listener for both.

Also decide, at the same time, whether the v3 path should stamp `pos_shifts.variance` the way the
C1 fix made the legacy sync path do — otherwise cutover terminals inherit the *other* half of the
defect this lane just fixed (a variance the alert surface and the ledger disagree about).

Gated on the same owner ruling as the rest of the lane (see the deploy note), and sequenced after
`2026-08-08-g3-kill-switch-window-no-backfill.md` — a backfill command that only understands the
legacy shape would need extending again.
