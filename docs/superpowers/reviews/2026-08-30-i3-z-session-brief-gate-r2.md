# I-3 brief gate r2 — Codex (HIGH effort, read-only), 2026-08-30

Saved verbatim from stdout by the orchestrator (read-only sandbox). Target: brief r2.

# I-3 brief gate r2 — Codex adversarial review

## Findings

### I3-R2-01 — BLOCKER · the acceptance matrix reverses server result semantics and can poison the main chain

The matrix at `LANE-I3-z-session-leg-BRIEF.md:31` contains two false result shapes:

- An in-table quarantine caused by canonical parse, hash, linkage, clock, or lifecycle failure is a newly inserted `fiscal_events` row, so it returns `stored=true`, a fiscal event ID, `sequence_conflict=false`, and a non-null `exception_class` (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:258-274`; `apps/api/app/Modules/Fiscal/Application/DTOs/IngestionResult.php:60-72`).
- An exact idempotent replay is not newly inserted, so it returns `stored=false`, the existing fiscal event ID, `sequence_conflict=false`, and `exception_class=null` (`apps/api/app/Modules/Fiscal/Application/DTOs/IngestionResult.php:75-86`; `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:1097-1113`).

The controller exposes those fields without transformation (`apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:167-182`).

There is a second lane-burning issue: a parse/linkage quarantine is inserted into `fiscal_events`, and chain-head lookup does not exclude quarantined rows (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:205-215`). Running that negative probe on the campaign terminal before close/Z consumes a chain sequence and makes the prescribed sequence-2 close fail.

Required change: replace the matrix with these exact outcomes:

| Outcome | HTTP | `stored` | event ID | conflict | exception |
|---|---:|---:|---|---:|---|
| malformed DTO/wire shape | 422 | no result | — | — | — |
| tenant mismatch | 403 | no result | — | — | — |
| verified new insert | 200 | true | new ID | false | null |
| in-table quarantine | 200 | true | new ID | false | non-null class |
| occupied-slot conflict | 200 | false | null | true | `sequence_conflict` |
| exact replay | 200 | false | existing ID | false | null |

Move live quarantine/conflict probes to an isolated throwaway terminal, or make them network-free/unit-contract tests. They must not run on the L5b–L9 terminal.

---

### I3-R2-02 — BLOCKER · the semantic vector still does not pin the device-derived VAT/payment shape

The headline and grand-total values at brief line 29 are correct:

- Sale-only headline: count 1, gross `23.800`, net `20.000`, tax `3.800`.
- Refunds: count 1, positive magnitude `23.800`.
- Grand totals after: sales `23.800`, tax `3.800`, refunds `23.800`, perpetual `0.000`, lifetime sale receipt count 1.

Those follow from the campaign receipt amounts (`apps/web/e2e/campaign/fiscal/events.ts:292-302`), the device’s separate sale/refund accumulation (`apps/pos/src/lib/offline/zReportService.ts:895-902`, `:951-1034`, `:1173-1183`), and cumulative formulas (`:469-482`).

But brief line 32 says “VAT rate 19 rows for sale and refund.” The device does not emit separate sale and refund VAT rows in canonical Z data. It groups by rate, adds the sale, subtracts the refund into the same bucket, then emits one row (`zReportService.ts:889-890`, `:917-928`, `:1036-1084`, `:1151-1162`). For this journey the canonical vector is:

- `vat_breakdown`: one rate-19 row with `net_amount=0.000`, `vat_amount=0.000`, `gross_amount=0.000`.
- `payment_method_totals`: one campaign cash-method row with `total_amount=0.000`, `transaction_count=2`; refund payment is subtracted and sale payment added (`zReportService.ts:931-946`, `:1087-1136`, `:1164-1170`).
- `operational_event_range`: sale hash/sequence 1 through refund hash/sequence 2, count 2 (`zReportService.ts:664-665`, `:748-754`).
- `session_event_range`: open sequence 1 through close sequence 2, with the respective IDs and hashes (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:689-696`).

Required change: pin these nested values explicitly in the brief and semantic JSON. If “sale and refund VAT rows” refers to two different response paths—for example canonical net VAT plus `refund_vat_disclosure`—name both JSON paths and their expected values. Do not permit two canonical rate-19 rows as “device parity.”

---

### I3-R2-03 — MAJOR · the Z reporting window is not locked around L6/L7

L7 deliberately timestamps its refund five minutes after the current time (`apps/web/e2e/campaign/onboarding.campaign.ts:561-579`; `:870-872`). If L9 uses the current time for `period_end`, the refund will lie outside the Z period even though the hand-authored totals and operational range include it.

This matters because the Z projector uses `period_start`/`period_end` to determine which fiscal receipts must already be projected and to derive rounding data (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:208-220`, `:305-335`). The current server does not reconcile that window against the hand-authored totals, so the campaign could pass with semantically contradictory data.

Required change: retain the exact L6 and L7 outer event timestamps in journey state and require:

- `period_start <= L6.event_time_device`
- `period_end >= L7.event_time_device`
- open timestamp ≤ close timestamp
- close/Z outer timestamp is second-precision, while nested close timestamps retain milliseconds.

Using an explicit later campaign timestamp such as `eventTime(10)` is sufficient.

---

### I3-R2-04 — MAJOR · the claimed 13-vs-14 `SESSION_OPEN` discrepancy does not exist

Brief line 28 says the server has 13 keys but the device list at `FiscalEventEngine.ts:1317` has 14. Both current definitions have exactly the same 13 keys:

- Server: `apps/api/app/Modules/Fiscal/Domain/DTOs/SessionOpenPayload.php:9-23`.
- Device: `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1317-1331`.
- Device builder: `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:291-310`.

The summary contract at brief line 48 consequently asks the implementer to report “which SESSION_OPEN key the device list has that the server DTO lacks,” but there is no such key.

Required change: state that device and server currently agree on the 13-key set and remove the impossible routine-call requirement.

---

### I3-R2-05 — MAJOR · `is_first_z_report` remains unpinned despite the acknowledged legacy mismatch

Brief line 32 correctly records that canonical projection writes the immediately preceding close hash into `previous_z_hash` (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:107-124`). The model defines “first” solely as `previous_z_hash === null` (`apps/api/app/Modules/POS/Domain/ZReport.php:163-168`), and the resource exposes that value (`apps/api/app/Modules/POS/Presentation/Resources/ZReportResource.php:30-39`).

Therefore, the first canonical Z in this lane will return:

- `z_number = 1`
- non-null `previous_z_hash` equal to the close event hash
- `is_first_z_report = false`

The phrase “`is_first_z_report` per resource” at line 32 is not a deterministic acceptance value and invites an implementer to assert the intuitive but false value `true`.

Required change: either explicitly assert `false` and identify it as the known legacy-surface defect, or exclude `is_first_z_report` from acceptance. Continue limiting actual chain assertions to fiscal-event `z_session` linkage and Z state/count.

---

### I3-R2-06 — MINOR · the Z-chain-state permission and campaign-principal precondition are omitted

Brief line 30 names permissions for shift and report reads, but not for:

`GET /api/v1/pos/terminals/{id}/z-chain-state`

That endpoint accepts either `pos.manage_terminals` or `pos.operate_terminal` (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:918-925`).

The fresh-registration campaign user does hold every required permission:

- Registration initialization assigns it the `admin` role (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:177-191`).
- The admin role is synchronized to all permissions (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:541-559`).
- Shift reads require `pos.operate_terminal` (`apps/api/app/Modules/POS/Presentation/Controllers/ShiftController.php:174-229`).
- Z reads require `pos.view_reports` (`apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php:216-264`).

However, reuse mode accepts arbitrary existing credentials (`apps/web/e2e/campaign/journey.ts:266-278`), so those permissions are not guaranteed there.

Required change: add the Z-chain-state permission and state that fresh registration satisfies all three. Either require an admin-equivalent reuse principal or add an early permission/preflight assertion for reuse mode.

## R1 resolution table

| R1 finding | Status | Resolving r2 line | Verification |
|---|---|---|---|
| I3-R1-01 | RESOLVED | Brief lines 8–13 | L5b is before L6, terminal creation moves to L5b, and one session/shift ID is threaded. Omitting `OPENING_FLOAT` is server-valid: `SESSION_OPEN` itself requires `opening_float_amount` (`SessionOpenPayload.php:9-23`; `FiscalPayloadConstraintValidator.php:811-824`), and the open projector copies it to `pos_shifts.opening_cash` (`ZSessionLifecycleProjection.php:208-220`). Lifecycle validation requires an open and matching close but never an `OPENING_FLOAT` event (`OutboxIngestor.php:566-594`). Close/Z DTOs carry no opening-float field (`SessionClosePayload.php:9-38`; `ZReportPayload.php:9-42`). |
| I3-R1-02 | PARTIAL | Brief lines 11 and 29 | IDs, hashes, sequences, ranges, virgin state, and headline/grand totals are now requested. The exact nested VAT/payment vector and reporting window remain under-specified or misleading; see I3-R2-02 and I3-R2-03. |
| I3-R1-03 | PARTIAL | Brief lines 28 and 31 | Envelope/payload constraints and response categories were added, but the matrix reverses quarantine and replay `stored` values, and the claimed device key-count discrepancy is false; see I3-R2-01 and I3-R2-04. |
| I3-R1-04 | PARTIAL | Brief line 30 | Shift and Z routes and their permissions are named. The Z-chain-state permission and reuse-principal requirement remain omitted; see I3-R2-06. |
| I3-R1-05 | RESOLVED | Brief lines 15–23 | B1–B6 now match code: copied cash figures, unenforced reconciliation, confirmed B3 gap, real replay identity, no close cash mutation, and reason retained only in canonical Z data. |
| I3-R1-06 | RESOLVED | Brief line 32 | The drawer is captured immediately before L9 and compared after L9; `2250.500` is correctly separated from the session cash count. Existing L8 establishes that balance at `onboarding.campaign.ts:631-666`. |
| I3-R1-07 | RESOLVED | Brief line 20 | B3 is explicitly code-evidenced and not probed by the campaign. |
| I3-R1-08 | RESOLVED | Brief line 13 | Open, receipts, close, and Z are staged with projection polls; close+Z batching is prohibited. This matches close retry behavior (`ZSessionLifecycleProjection.php:241-255`) and Z dependency gating (`ZReportProjection.php:305-335`). |
| I3-R1-09 | RESOLVED | Brief lines 36–39 | Plumbing and close/Z are split into phases, and open+close cannot be reported as L9 PASS. |
| I3-R1-10 | PARTIAL | Brief line 32 | The legacy mismatch is recorded and legacy hash-chain verification is excluded, but `is_first_z_report` remains unpinned even though the first canonical Z returns false; see I3-R2-05. |

## VERDICT: CHANGES-REQUIRED

The lane is not dispatchable while the acceptance matrix asserts inverted server outcomes and permits quarantine probes to consume the main terminal’s chain. The semantic vector also needs its exact netted VAT/payment values and reporting window locked. The false SESSION_OPEN key discrepancy and ambiguous first-Z assertion should be corrected before implementation.
