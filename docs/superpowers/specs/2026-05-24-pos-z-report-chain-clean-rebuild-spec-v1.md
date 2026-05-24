# POS Z-Report Chain Clean Rebuild Spec (v1)

**Date:** 2026-05-24  
**Branch:** `feat/fiscal-z-report-chain-clean-rebuild`  
**Roadmap:** `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md` §"Parallel / Separate Workstream — Z-Report Chain Clean Rebuild"  
**Primary sources:** Source-of-truth v3 §1, §6, §7, §12, §13.6/D16; Phase 1 foundation spec v7 §6, §11, §14.3, Appendix A; POS fiscal codebase reality §3; multi-country fiscal research §1, §3, §4; owner strategy doc §19-§21 and §27; Phase 4 spec v3 §3.

## 1. Decision Summary

This workstream rebuilds Z/session closure authoring on the same device-authority model as `SALE_RECEIPT`: the POS device authors and seals session/closure events locally; the server verifies canonical bytes and projects mirrors. The current legacy Z-report flow remains a migration source and short-term read fallback, but it stops being the fiscal authority for new terminals after cutover.

Locked v1 decisions:

- `SESSION_OPEN`, `SESSION_CLOSE`, `X_REPORT`, and `Z_REPORT` become device-authored fiscal events.
- Z/session events use a **separate Z-chain head** from receipt/operational fiscal events. This is not currently supported by `FiscalEventEngine.append()`; the engine must be extended with an explicit chain context.
- Cash drawer movement fiscal events are owned by this workstream as session-lifecycle children: `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION`.
- The server-side `ReportGenerationService::generateZReport()` and local `zReportService.generateZReport()` stop computing independent Z hashes for new fiscal-authority paths.
- `Nf525DataProvider` reads canonical `Z_REPORT` bytes for GRANDTOTAL exports where a fiscal event exists, with legacy fallback only for pre-cutover rows.

## 2. D-Q1 Owner Decision Surfaced

**D-Q1 — Cash drawer event ownership.**

The handover brief asks whether cash drawer event types belong to Phase 4 ad-hoc controls or the Z-report/session rebuild. Phase 4 v3 explicitly deferred fiscal cash movement events and kept only approval evidence on legacy drawer rows.

**Recommendation locked in v1 unless owner overrides:** Z-report/session chain owns cash drawer movement fiscal events.

Rationale:

- Owner strategy §27 models cash movements inside `SESSION_OPEN -> transactions/refunds/cash movements -> SESSION_CLOSE -> Z_REPORT`.
- NF525 grand totals and DSFinV-K closure/accounting-circle exports need cash drawer totals at closure, not as unscoped receipt-chain side effects.
- Phase 4 already merged with legacy `DEPOSIT`/`PAYOUT` approval controls but without movement-event semantics; implementing the fiscal event types here avoids split ownership.
- A `Z_REPORT` canonical payload can summarize movement event ranges and amounts with immutable linkage to the session chain.

Resulting ownership:

- Phase 4 keeps approval primitive and legacy drawer operation controls.
- Z-report rebuild implements fiscal movement authoring, chain membership, projection, and Z-report aggregation.

## 3. Current System Reality

### 3.1 Device Z-report path

`apps/pos/src/lib/offline/zReportService.ts` currently:

- aggregates `offline_receipts` for a shift;
- calculates expected cash and optional cash-count blocks;
- computes a legacy Z hash via `computeZReportHash()`;
- writes `z_reports`;
- advances `terminal_state.z_last_hash`, `z_hash_sequence`, and `z_number`;
- updates cumulative grand totals.

This is closer to device-authority than the server path, but it is not the Phase 1 fiscal-event engine. It has its own payload shape, canonical/hash rules, chain state, and sync/projection path.

### 3.2 Server Z-report path

`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php::generateZReport()` currently:

- recomputes shift totals server-side;
- computes `ZReportHashService` hashes over mutable model data;
- writes `pos_z_reports`;
- creates a legacy `GRANDTOTAL_DAILY` event through `GrandtotalService`;
- leaves shift closing as a separate operation.

This violates SoT v3 §12 for the clean rebuild: the server must verify device-authored canonical bytes, not author/recompute the fiscal closure.

### 3.3 Fiscal event engine mismatch

The handover hypothesized per-event-type chain partitioning. Current code contradicts that:

- Phase 1 spec v7 §6.2 says there is one `terminal_state` fiscal-event chain head.
- `FiscalEventEngine.ts` reads and advances only `fiscal_event_genesis_seed`, `fiscal_event_last_hash`, and `fiscal_event_sequence`.
- The engine comments state "one chain head per terminal."

Therefore this rebuild must add first-class chain context support. Reusing the receipt chain for `Z_REPORT` would make the session closure sequence interleave with sales, account events, overrides, and cash movements, contradicting SoT §12's independent protocol-level Z chain.

## 4. Scope

### 4.1 In scope

1. **Device-authored session lifecycle.**
   - `SESSION_OPEN` starts a fiscal session and records opening float reference.
   - `SESSION_CLOSE` records close intent, counted-cash snapshot, variance evidence, and final movement snapshot.
   - `X_REPORT` authors a non-closing informational snapshot.
   - `Z_REPORT` closes the session, freezes totals, archives event ranges, and advances the Z-chain.

2. **Cash drawer movement fiscal events.**
   - Implement `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION`.
   - Each event is session-scoped and device-authored.
   - Approval evidence from Phase 4 is included when movement policy requires it.

3. **Z-chain engine support.**
   - Extend device fiscal authoring with explicit chain context, at minimum `operational` and `z_session`.
   - Add/normalize SQLite `terminal_state` Z-chain columns.
   - Add server mirror columns/tables needed to verify the Z chain without recomputing payloads.

4. **Canonical payload DTOs and validators.**
   - Add strict PHP and TS payload contracts for session events, cash movements, and `Z_REPORT`.
   - Add cross-language drift gates matching the Phase 1 pattern.

5. **Server ingest verification.**
   - Server verifies canonical bytes, hashes, sequence, tenant/company/terminal, and chain continuity.
   - Server accepts/quarantines per SoT §7 policy rather than recomputing or mutating event payloads.

6. **Projection.**
   - Add POS-core session/Z-report projection tables or columns as needed for read APIs and print/export surfaces.
   - Projectors are idempotent and keyed by fiscal event id.

7. **NF525 export integration.**
   - Extend `Nf525DataProvider`/JET path to read canonical `Z_REPORT` bytes for GRANDTOTAL day/month/year sections.
   - Keep legacy fallback only for rows without `fiscal_event_id`.

8. **Chokepoint gate.**
   - Add a server-side generation gate analogous to Phase 1 §14.3 for `generateZReport`, `generateXReport`, shift-close callers, and any server-created GRANDTOTAL path.

### 4.2 Out of scope

- Country-specific TSE/ZATCA/RT signature provider integrations.
- GL closing entries for Z-report or cash movements.
- Full DSFinV-K adapter implementation. The canonical payload must support it, but export generation can remain deferred.
- Removing all legacy `z_reports` and `pos_z_reports` fields immediately.
- Web POS as fiscal device-authoring parity unless a virtual/admin terminal path is explicitly needed for migration tooling.
- Remote push approvals.

## 5. Chain Model

### 5.1 Chain contexts

Add explicit fiscal chain contexts:

- `operational`: existing Phase 1 chain for `SALE_RECEIPT`, account events, overrides, account status, and Phase 4 approval events.
- `z_session`: new chain for `SESSION_OPEN`, cash drawer movement fiscal events, `SESSION_CLOSE`, `X_REPORT`, and `Z_REPORT`.

The event row still lives in `fiscal_events`, but sequence continuity is evaluated against `(tenant_id, company_id, terminal_id, chain_context)`, not only the terminal's operational head.

### 5.2 Device state

`terminal_state` must hold:

- existing operational head: `fiscal_event_genesis_seed`, `fiscal_event_last_hash`, `fiscal_event_sequence`;
- Z-chain head: `z_chain_genesis_seed`, `z_chain_last_hash`, `z_chain_sequence`;
- Z numbering: existing `z_number` may remain but must be validated against Z-chain sequence semantics;
- cumulative grand totals, migrated to the canonical payload/projection rules.

Existing local columns `z_last_hash` / `z_hash_sequence` may either be renamed or bridged to the new names. The plan must choose the least risky migration after reading current SQLite/server column names.

### 5.3 Event ordering

Within `z_session`:

- `SESSION_OPEN` is required before fiscal movement events, `X_REPORT`, `SESSION_CLOSE`, or `Z_REPORT`.
- `X_REPORT` does not close a session and can be repeated.
- `SESSION_CLOSE` freezes counted-cash evidence but does not replace `Z_REPORT`.
- `Z_REPORT` is terminal for the session and prevents retroactive session modification.
- A second `Z_REPORT` for the same shift/session is idempotent only if it references the same fiscal event id/hash; otherwise it is rejected/quarantined.

Operational events retain their existing operational chain sequence. The `Z_REPORT` payload records the operational event range it closes: first/last sequence and first/last event ids per included event class.

## 6. Canonical Payloads

### 6.1 Shared envelope rules

All new payloads use the existing fiscal-event envelope:

- `event_type`
- `event_version`
- `tenant_id`
- `company_id`
- `terminal_id`
- `operator_id`
- `business_date`
- `event_time_device`
- `sequence_number`
- `previous_hash`
- `current_hash`
- `canonical_bytes`

The payload itself must include enough business identifiers to be exported without rereading mutable operational rows.

### 6.2 `SESSION_OPEN` payload

Required top-level payload keys:

- `session_id`
- `shift_id`
- `business_date`
- `opened_at_device`
- `operator_id`
- `operator_name`
- `terminal_id`
- `terminal_label`
- `currency_code`
- `currency_scale`
- `opening_float_amount`
- `opening_float_event_id` nullable until `OPENING_FLOAT` event is authored in the same transaction
- `training_flag`

### 6.3 Cash drawer movement payload

Applies to `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `CASH_CORRECTION`.

Required top-level payload keys:

- `movement_id`
- `session_id`
- `shift_id`
- `movement_type`
- `business_date`
- `event_time_device`
- `operator_id`
- `operator_name`
- `amount`
- `currency_code`
- `currency_scale`
- `reason_code`
- `reason_text`
- `cash_drawer_operation_id` nullable legacy row reference
- `approval` nullable object with `approval_event_id`, `approval_id`, `supervisor_user_id`, `scope`, `policy_version`, and `target_hash`
- `training_flag`

### 6.4 `X_REPORT` payload

Required top-level payload keys:

- `x_report_uuid`
- `session_id`
- `shift_id`
- `business_date`
- `period_start`
- `period_end`
- `generated_at_device`
- `operator_id`
- `operator_name`
- `terminal_id`
- `training_flag`
- `operational_event_range`
- `sales_totals`
- `vat_breakdown`
- `payment_method_totals`
- `cash_drawer_totals`
- `refunds_totals`
- `voids_totals`
- `receipt_count`

`X_REPORT` is explicitly non-closing and must not update `z_chain_sequence` as a closure number. It does still append to the `z_session` fiscal chain as an informational event.

### 6.5 `SESSION_CLOSE` payload

Required top-level payload keys:

- `session_close_uuid`
- all identity/period fields from `X_REPORT`
- `counted_cash`
- `expected_cash`
- `variance_amount`
- `variance_direction`
- `variance_severity`
- `variance_reason`
- `manager_approval` nullable Phase 4 approval evidence
- `cash_count_lines`
- `closure_status`

### 6.6 `Z_REPORT` payload

Required top-level payload keys:

- `z_report_uuid`
- `z_number`
- `formatted_z_number`
- `period_type` (`DAY`, `MONTH`, `YEAR`)
- `session_id`
- `shift_id`
- `business_date`
- `period_start`
- `period_end`
- `closed_at_device`
- `operator_id`
- `operator_name`
- `terminal_id`
- `terminal_label`
- `company_snapshot`
- `seller`
- `currency_code`
- `currency_scale`
- `training_flag`
- `operational_event_range`
- `session_event_range`
- `receipt_totals`
- `refunds_totals`
- `voids_totals`
- `vat_breakdown`
- `payment_method_totals`
- `cash_drawer_totals`
- `cash_count`
- `tolerance_summary`
- `grand_totals_before`
- `grand_totals_after`
- `legacy_report_reference` nullable

Compliance mapping:

- NF525 GRANDTOTAL: `period_type`, `period_start`, `period_end`, `grand_totals_after`, per-rate `vat_breakdown`, payment totals, operator/terminal identity.
- DSFinV-K future adapter: `z_number`, `business_date`, operator identity, terminal id, payment-type totals, allocation/session grouping, TSE linkage placeholders through event ids and canonical bytes.
- Italy Tipi Dati future adapter: daily XML aggregate compatibility through period timestamps, VAT rate/nature breakdown, taxable/tax totals, and refunds/returns aggregate.

## 7. Server Contract

### 7.1 Ingest

Device sync submits session/Z events through the existing fiscal event ingest path, not through `ReportGenerationService` as an authoring endpoint.

Server responsibilities:

- verify envelope canonical hash against `canonical_bytes`;
- verify chain context continuity for `z_session`;
- reject cross-tenant/company/terminal mismatches;
- strict-parse payload keys and nested shapes;
- store verified rows in `fiscal_events`;
- quarantine per-class failures without mutating canonical bytes;
- dispatch projectors after commit.

### 7.2 Legacy endpoints

Existing server generation endpoints become:

- read-only or admin fallback for pre-cutover terminals, or
- wrappers that refuse fiscal-authority generation for cutover terminals with a clear error instructing POS device authoring.

No new production `Z_REPORT` may be authored by PHP from mutable receipt/shift models after cutover.

## 8. Projection And Read Models

Add a POS-core Z projection that consumes `SESSION_OPEN`, movement events, `SESSION_CLOSE`, `X_REPORT`, and `Z_REPORT`.

Projection outputs must support:

- POS local Z-report history UI;
- server Z-report history APIs;
- printed Z-report tape;
- NF525 export provider;
- cutover gate "no un-Z-reported fiscalized receipts";
- sync replay idempotency.

Projection tables can either extend `pos_z_reports` with `fiscal_event_id`, `canonical_bytes_hash`, and parse status, or add new `pos_session_closures`/`pos_session_events` tables. The plan must decide after schema audit; the spec requires the resulting model to be unambiguous and replay-safe.

## 9. Chokepoints And Gates

Add a Z-report server-authoring chokepoint test/gate that inventories:

- `ReportGenerationService::generateZReport()`;
- `ReportGenerationService::generateXReport()`;
- controllers/routes invoking report generation;
- shift close flows that currently generate or require Z reports;
- `GrandtotalService::createGrandtotalEvent()` calls;
- sync routes that accept legacy Z reports.

Every caller must be dispositioned as:

- `DISCARDED`: no longer valid after device-authority cutover;
- `REWORK`: calls device-authored fiscal event ingestion or projection reads;
- `LEGACY_FALLBACK`: allowed only for pre-cutover terminal schema versions;
- `READ_ONLY`: no authoring.

The gate must fail if a new server authoring caller appears without disposition.

## 10. Migration And Cutover

Cutover must be reversible for operators but not fiscally ambiguous:

- Add schema/version flags for Z-chain-authority terminals.
- Existing legacy `z_reports` remain readable.
- New cutover terminals cannot generate legacy authoritative Z hashes.
- Local POS pulls Z-chain state from server for online-only tenants without clobbering stronger local state.
- Training-mode closures are excluded from production chain/export and marked as training/test where retained.

## 11. Test Matrix

Required coverage:

- Device append rejects session types before implementation, then accepts only after registry implementation.
- Z-chain head advances independently from operational chain head.
- Rollback leaves neither event row nor Z-chain head advanced.
- `SESSION_OPEN -> movement -> X_REPORT -> SESSION_CLOSE -> Z_REPORT` happy path.
- `X_REPORT` does not close the session.
- `Z_REPORT` cannot run twice for the same session with different bytes.
- Z-report cannot include receipts from another tenant/company/terminal.
- Clock anomaly does not move an event between closure periods; sequence range remains authoritative.
- Cash drawer movement requires approval evidence when policy threshold requires it.
- Legacy server `generateZReport()` blocked for cutover terminal.
- Server ingest verifies Z-chain continuity and quarantines mismatch.
- `Nf525DataProvider` uses canonical `Z_REPORT` bytes when `fiscal_event_id` is present.
- POS-only deployment works without Treasury/accounting modules.
- Future Treasury/GL projector absence does not block POS-core projection.

## 12. Verification

Pre-PR local verification must include:

- `pnpm --filter @autoerp/pos typecheck`
- `pnpm --filter @autoerp/pos lint`
- targeted POS Vitest suites for fiscal engine, terminal state, zReportService, sync, and report API
- `APP_KEY=... ./vendor/bin/phpstan --memory-limit=1536M`
- `./vendor/bin/pint --test`
- targeted API tests for fiscal payload registry/parser/validator, ingest, projection, Z-report APIs, and NF525 export
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json`
- existing §14.3 receipt chokepoint gate
- new Z-report chokepoint gate
- generated TypeScript drift guard if PHP `#[TypeScript]` DTOs/enums are touched

## 13. Open Risks For Review

1. The existing server and local Z-report shapes are not equivalent to the proposed canonical `Z_REPORT`; migration needs careful dual-read handling.
2. The existing device engine is single-chain. Extending it for chain contexts is shared infrastructure work and must not regress Phase 1 receipt/account/override authoring.
3. Phase 4 implemented `CASH_OUT`/`SAFE_DROP` DTO registration for legacy drawer approval evidence. The plan must reconcile those interim implementations with this spec's session-chain ownership.
4. NF525 month/year GRANDTOTAL generation may be export-only aggregation over day Z reports rather than a separate device-authored closure event. The plan must decide whether device-authored `Z_REPORT.period_type` supports all three immediately or starts with `DAY` and derives month/year export sections.
