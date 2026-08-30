# I-3 brief gate r1 — Codex (HIGH effort, read-only), 2026-08-30

Reviewer: Codex `codex exec --sandbox read-only`; the sandbox could not write this file, so the orchestrator saved the stdout verbatim. Target: `docs/sessions/session-I-process-hardening-2026-08-29/LANE-I3-z-session-leg-BRIEF.md` r1.


## Findings

### I3-R1-01 — BLOCKER · the chain/order decision is already answerable, but the brief leaves it open

The server maintains independent operational and Z-session chains:

- `SALE_RECEIPT` belongs to the operational set; `SESSION_OPEN`, `SESSION_CLOSE`, and `Z_REPORT` belong to the Z-session set (`apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:96-124`).
- Chain heads are keyed by tenant, company, terminal, and `chain_context` (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:205-212`).
- Z lifecycle validation exits immediately for operational events (`OutboxIngestor.php:552-555`).

Therefore, the server does **not** require an open session for receipts to project, and `SESSION_OPEN` does not need to precede L6 for server acceptance. Within `z_session`, however, open must precede close/Z, and Z must reference an existing matching close through `session_event_range.session_close_event_id` (`OutboxIngestor.php:566-594`).

Device semantics differ: the device authors `SESSION_OPEN` and `OPENING_FLOAT` together (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:539-566`) and uses one UUID for session, shift, and receipt attribution (`apps/pos/src/stores/terminalStore.ts:231-253`, `:875-893`). The campaign currently creates the terminal inside L6 (`apps/web/e2e/campaign/onboarding.campaign.ts:477-491`) and gives L6/L7 independent random `shift_id` values (`apps/web/e2e/campaign/fiscal/events.ts:84-86`, `:153-158`).

**Required brief change:** lock the model. For a truthful “session’s L6/L7 events” campaign, require L5b before L6, move terminal creation into L5b, and thread one `session_id == shift_id` through open, both receipts, close, and Z. Explicitly decide whether `OPENING_FLOAT` is included; including it changes close/Z from sequences 2/3 to 3/4. If omitted, call this a server-minimal synthetic lifecycle, not device-faithful authoring.

### I3-R1-02 — BLOCKER · the declared builder inputs cannot populate a correct Z payload

`operational_event_range` requires:

- first/last receipt hashes;
- first/last receipt hash sequences;
- operational receipt-row count.

Evidence: `apps/pos/src/lib/offline/zReportService.ts:748-754`.

The brief supplies only L6/L7 IDs and totals. Journey state retains the sale hash but not the refund hash or receipt hash sequences (`apps/web/e2e/campaign/onboarding.campaign.ts:493-516`). `session_event_range` is a separate six-field range containing open/close IDs, hashes, and Z-chain sequences (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:689-696`).

Grand totals are cumulative state (`zReportService.ts:469-482`). For a proven-new terminal’s first Z, the correct values are:

| Field | Value |
|---|---:|
| `grand_totals_before` | five zero values |
| cumulative sales after | `23.800` |
| cumulative tax after | `3.800` |
| cumulative refunds after | `23.800` |
| perpetual total after | `0.000` |
| lifetime receipt count after | `1` |
| `receipt_totals.count` | `1` |
| `refunds_totals.count` | `1` |
| `operational_event_range.receipt_count` | `2` |

The headline is sale-only; refunds remain a separate positive magnitude (`zReportService.ts:852-901`, `:1173-1183`). V4 refund rows are included in operational snapshots (`zReportService.ts:198-203`).

**Required brief change:** retain refund ID/hash and both receipt hash sequences; pin the six session-range fields; assert virgin Z-chain state before assuming zero prior totals; prescribe the values above; require a device-code-derived semantic vector. A self-generated encoder golden plus live HTTP 200 cannot prove device parity because most nested values are not server-validated.

### I3-R1-03 — MAJOR · the brief omits the constraints governing 422, quarantine, conflicts, and projection

A naive builder can fail through several distinct channels.

**HTTP 422/pre-flight**

- Batch must contain 1–100 envelopes.
- Each needs `envelope_id`, `type = FISCAL_EVENT`, integer `payload_version >= 1`, string `idempotency_key`, and payload object (`apps/api/app/Modules/Fiscal/Presentation/Requests/IngestFiscalEventsRequest.php:42-56`).
- IDs must be canonical lowercase UUIDs; hashes lowercase 64-hex; sequence positive; outer event time UTC-second precision; optional reference/source IDs must also be UUIDs (`apps/api/app/Modules/Fiscal/Application/DTOs/FiscalEventEnvelope.php:201-238`).
- One malformed batch member returns 422 before inserts; tenant mismatch returns 403 (`apps/api/app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:70-104`).

**HTTP 200 with quarantine/conflict**

- Canonical envelope has exactly **15** keys despite the parser comment saying 14 (`apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:77-94`).
- No missing/extra envelope fields; correct version, context/type pairing, training-context coupling, hash, linkage, and sealed-coordinate equality (`StrictCanonicalParser.php:637-710`, `:271-302`; `OutboxIngestor.php:179-184`, `:421-461`).
- These parse/linkage failures generally appear in per-envelope results, not HTTP status (`FiscalEventIngestionController.php:107-141`, `:175-182`).

**Payload constraints**

- Exact top-level key sets; missing/extras quarantine (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:537-573`).
- `SESSION_OPEN` has **13**, not 14, keys (`apps/api/app/Modules/Fiscal/Domain/DTOs/SessionOpenPayload.php:9-23`).
- Open requires UUIDs, positive shift number, date, millisecond timestamp, nonempty names/label, currency code/scale, exact-scale money, and boolean training flag (`FiscalPayloadConstraintValidator.php:811-825`).
- Close/Z validate four UUIDs, date, training flag, and VAT consistency (`:852-860`).
- VAT must be a list of object rows with signed decimal money, maximum three fractional digits, and `gross = net + VAT`; refund count must be a non-negative integer (`:962-1085`, `:1087-1121`).
- When refund count is nonzero, headline-to-VAT sum validation is skipped (`:1071-1073`).
- Cash count, payment totals, ranges, grand totals, Z number, and most nested objects have no semantic server validation.

**Lifecycle/idempotency**

- First Z-context event must use sequence 1 and the terminal genesis seed; later events must increment and link the prior hash (`OutboxIngestor.php:481-550`).
- Open requires `source_event_class = pos_session` and `source_event_id = session_id` (`:566-575`).
- Match device source tuples for close and Z, and set Z `reference_event_id` to the close (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:675-710`).
- Slot uniqueness is `(tenant, company, terminal, context, sequence)`, not merely terminal/sequence (`OutboxIngestor.php:905-923`).
- Exact redelivery compares event ID, hash, canonical bytes, and source class/ID. `idempotency_key` is unused (`:1097-1113`).

**Required brief change:** add an explicit acceptance matrix distinguishing 422, 403, per-envelope quarantine, conflict, idempotent replay, and successful projection. Correct the open key count and require assertions on `stored`, `sequence_conflict`, and `exception_class`.

### I3-R1-04 — MAJOR · public projection reads exist and should be prescribed

Available reads are:

- `GET /api/v1/pos/shifts/{id}` — projected close.
- `GET /api/v1/pos/shifts` — filtered shift list.
- `GET /api/v1/pos/shifts/current/{terminalCode}` — null after close.

Routes: `apps/api/app/Modules/POS/routes.php:108-124`; permission: `pos.operate_terminal` at `apps/api/app/Modules/POS/Presentation/Controllers/ShiftController.php:174-229`.

- `GET /api/v1/pos/reports/z/{zNumber}?terminal_id=<uuid>` — Z detail.
- `GET /api/v1/pos/reports/z?terminal_id=<uuid>` — Z list/count.

Routes: `apps/api/app/Modules/POS/routes.php:130-135`; permission: `pos.view_reports` at `apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php:216-269`.

- `GET /api/v1/pos/terminals/{id}/z-chain-state` — prior/latest Z hash, number, and grand totals; returns genesis state before the first Z (`apps/api/app/Modules/POS/routes.php:91`; `TerminalController.php:918-963`).
- `GET /api/v1/pos/shifts/{id}/receipts` exists at `apps/api/app/Modules/POS/routes.php:247-248`, but requires fixing receipt shift attribution.

The shift resource exposes expected, actual, variance, and status (`apps/api/app/Modules/POS/Presentation/Resources/ShiftResource.php:23-45`) but not variance reason. Reason remains in canonical Z `report_data.cash_count`; the close projection does not write shift notes (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:284-330`).

**Required brief change:** name these routes, permissions, and parameters. Assert variance reason from Z canonical report data, not the shift resource.

### I3-R1-05 — BLOCKER · B1–B6 contain four wrong or overstated cells

| Cell | Code verdict |
|---|---|
| B1 | Overstated. Close copies expected/count and recomputes `counted - expected`; it does not derive expected cash from session activity (`ZSessionLifecycleProjection.php:284-320`). |
| B2 | False as enforcement. Projection copies the hand-authored totals; validator does not reconcile ranges, payment totals, grand totals, or refunds to receipts (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:107-164`; `FiscalPayloadConstraintValidator.php:1038-1084`). |
| B3 | False. Operational receipts bypass Z lifecycle (`OutboxIngestor.php:552-555`). |
| B4 | Outcome true, rationale false. Exact duplicate replay is idempotent, but the slot includes tenant/company/context and `idempotency_key` is unused (`OutboxIngestor.php:1073-1113`). |
| B5 | True for close itself; the close projector only updates `pos_shifts` (`ZSessionLifecycleProjection.php:305-330`). |
| B6 | Partially false. Reason remains in Z canonical data, is not projected onto shift, and balanced severity maps to null (`ZSessionLifecycleProjection.php:289-330`). |

**Required brief change:** rewrite the baseline table accordingly. B3 must be a confirmed product gap, not conditional discovery.

### I3-R1-06 — BLOCKER · the L9 `drawer = 1000.000` assertion conflicts with L8

L7 restores the repository to `1000.000` (`apps/web/e2e/campaign/onboarding.campaign.ts:624-628`). L8 then posts `1250.500` into the same repository and asserts `2250.500` (`:631-666`). L9 runs after L8 (`apps/web/e2e/campaign/journey.ts:73-85`).

**Required brief change:** capture the repository balance immediately before L9 and assert equality afterward—currently `2250.500`. Keep the session cash-count expectation of `1000.000` separate from the Treasury repository’s post-L8 balance.

### I3-R1-07 — BLOCKER · the post-Z sale probe is guaranteed to find a gap and corrupts the promoted journey

A valid operational sequence-3 sale after Z is accepted. Its projection creates receipt/payment/VAT/stock effects; Treasury may add GL and repository effects (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:70-103`). Existing L6 demonstrates the stock, lot, GL, and drawer mutations (`apps/web/e2e/campaign/onboarding.campaign.ts:518-554`).

Recording the accepted-sale finding also contradicts L10, which requires an empty findings list (`onboarding.campaign.ts:673-678`).

**Required brief change:** remove B3 from main-terminal L9 acceptance, or run it on an isolated throwaway terminal/product/repository with an explicit expected-finding policy. Alternatively, authorize a separate server fix before expecting green. The sequence-3 builder must also accept the L7 hash; the current sale path assumes operational genesis sequence 1.

### I3-R1-08 — MAJOR · unordered projection jobs can exceed the campaign poll window

Close throws and retries if open has not projected (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:235-255`). Z waits for all verified receipts in its period window to project (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:305-336`).

Projection retries use `[10, 30, 60, 300, 900]`, about 21 minutes (`apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:138-180`). Campaign polling defaults to 45 seconds (`apps/web/e2e/campaign/journey.ts:547-552`).

**Required brief change:** prescribe staged ingestion:

1. Ingest open; poll shift open.
2. Ensure L6/L7 receipts have projected.
3. Ingest close; poll shift closed.
4. Ingest Z; poll Z detail/list.

If close and Z must be sent together, extend timeout and require projection/dead-letter diagnostics.

### I3-R1-09 — MAJOR · the two-hour budget and L9a fallback are not credible acceptance contracts

The lane also requires terminal relocation, common identity plumbing, additional journey state, two ranges, cumulative totals, staged polling, route reads, golden vectors, dry construction, documentation, lint/typecheck, replay, and safe handling of B3.

Open+close as “L9a PASS” leaves a closed shift without the declared Z and proves none of B2, B4, or the Z read contract. Both close and Z are projected coverage (`apps/api/app/Modules/Fiscal/Application/Services/FiscalEventCoveragePolicy.php:58-61`).

**Required brief change:** split prerequisite session/identity plumbing from close+Z execution or provide a realistic budget. If Z remains incomplete, keep L9 `NOT_SCRIPTABLE`/FAIL; open+close may be diagnostic progress, not PASS.

### I3-R1-10 — MAJOR · canonical Z projection misaligns with the legacy `previous_z_hash` meaning

Z’s envelope `previous_hash` is the immediately preceding `SESSION_CLOSE` hash. `ZReportProjection` writes that into `pos_z_reports.previous_z_hash` (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:107-124`). Consequently, the first canonical Z has a non-null “previous Z” hash, and later Z rows point at close events rather than previous Z rows.

The public resource exposes `is_first_z_report` from that legacy surface (`apps/api/app/Modules/POS/Presentation/Resources/ZReportResource.php:35-39`).

**Required brief change:** limit L9 chain assertions to the authoritative fiscal-event `z_session` chain and Z state/count fields. Record the `previous_z_hash` mismatch separately; do not require legacy Z-chain verification until the authoritative hash contract is resolved.

## VERDICT: CHANGES-REQUIRED

The brief is not dispatchable until it locks the chain/session model, supplies the missing hash/sequence/cumulative inputs, corrects B1–B6 and the L8 balance assertion, isolates or removes the guaranteed post-Z mutation, names the existing public reads, and replaces the partial-PASS fallback.
