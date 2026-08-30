# I-3 brief gate r3 — Codex (resolution check), 2026-08-30

Saved verbatim from stdout by the orchestrator. Target: brief r3. Residual I3-R3-01 fixed in brief r4 (line 44 VAT phrase → the single netted row); dispatched on that basis.

# I-3 brief gate r3 — resolution check

## I3-R3-01 — BLOCKER · VAT acceptance remains internally contradictory

The semantic vector correctly requires exactly one netted rate-19 row:

- `vat_breakdown = [{net_amount: "0.000", vat_amount: "0.000", gross_amount: "0.000"}]` at `LANE-I3-z-session-leg-BRIEF.md:29`.

But the assertion contract still requires “VAT rate 19 rows for sale and refund” at `LANE-I3-z-session-leg-BRIEF.md:44`.

The device accumulates sale and refund into the same rate-keyed map, subtracting the refund and adding the sale, then emits one row per map entry (`apps/pos/src/lib/offline/zReportService.ts:889-890,917-928,1063-1084,1153-1162`). Payment totals are likewise netted into one campaign-method row with two transactions (`apps/pos/src/lib/offline/zReportService.ts:931-946,1131-1136,1164-1170`).

The detail response exposes canonical VAT under `report_data` and the separately derived refund disclosure under `refund_vat_disclosure` (`apps/api/app/Modules/POS/Presentation/Resources/ZReportResource.php:50-57`). If line 44 intends both paths, it still fails I3-R2-02’s requirement to name each JSON path and expected value.

Required correction: replace line 44’s phrase with the exact single `report_data.vat_breakdown` row from line 29. Any additional refund-disclosure assertion must explicitly name `refund_vat_disclosure` and its expected values.

## I3-R2 resolution check

| Round 2 finding | Status | Resolving r3 line and code verification |
|---|---|---|
| I3-R2-01 | RESOLVED | Brief lines 31–42 now give the correct matrix and forbid negative probes on the campaign terminal. Malformed/tenant failures return 422/403 before results (`FiscalEventIngestionController.php:70-104`); verified/quarantined/replayed/conflict shapes match `IngestionResult.php:48-99`; wire fields are copied directly at `FiscalEventIngestionController.php:175-182`. Chain-head lookup has no quarantine filter (`OutboxIngestor.php:205-212`), supporting the no-negative-probe rule. |
| I3-R2-02 | PARTIAL | Brief line 29 correctly pins the headline, grand totals, one netted VAT row, one netted payment row, and both event ranges. However, brief line 44 contradicts it by requesting separate sale/refund VAT rows. See I3-R3-01. |
| I3-R2-03 | RESOLVED | Brief line 29 retains both outer receipt timestamps and requires `period_start ≤ L6`, `period_end ≥ L7`, open ≤ close, and later second-precision close/Z timestamps. Current L6 uses `eventTime()`, L7 uses `eventTime(5)`, and the helper’s argument is minutes (`onboarding.campaign.ts:499,567,870-872`), so `eventTime(10)` is later. The server selects receipt events using the supplied window (`ZReportProjection.php:208-220,305-319`). |
| I3-R2-04 | RESOLVED | Brief line 28 now states that device and server agree on 13 `SESSION_OPEN` keys and removes the impossible discrepancy report. The identical lists are at `SessionOpenPayload.php:9-23` and `FiscalEventEngine.ts:1317-1331`; the device builder emits them at `zSessionAuthoring.ts:291-310`. |
| I3-R2-05 | RESOLVED | Brief line 44 explicitly pins `is_first_z_report === false` as the known legacy defect. Projection stores the Z event’s non-genesis previous hash (`ZReportProjection.php:107-124`), first-ness tests for null (`ZReport.php:163-168`), and the resource exposes that result (`ZReportResource.php:30-38`). |
| I3-R2-06 | RESOLVED | Brief line 30 names all permissions, proves fresh-registration coverage, and requires a clear 200-vs-403 preflight in reuse mode before ingestion. Shift reads require `pos.operate_terminal` (`ShiftController.php:174-229`), Z reads require `pos.view_reports` (`ReportController.php:216-264`), and chain state accepts manage-or-operate (`TerminalController.php:918-925`). Fresh users receive admin/all permissions (`TenantInitializationService.php:177-191`; `RolesAndPermissionsSeeder.php:541-545`), while reuse mode accepts supplied credentials (`journey.ts:266-278`). |

## VERDICT: CHANGES-REQUIRED

The r3 brief is not dispatchable until the line 44 VAT assertion is reconciled with the exact one-row netted vector at line 29.
