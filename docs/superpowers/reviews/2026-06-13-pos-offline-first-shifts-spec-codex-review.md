# Adversarial Review — POS Offline-First Device-Authoritative Shifts Spec

**Date:** 2026-06-13
**Reviewer:** Codex (automated adversarial review)
**Branch:** `feat/parapharmacy-tunisia-demo` (worktree `apps/erp.db-per-tenant`)
**Spec file:** `docs/superpowers/specs/2026-06-13-pos-offline-first-device-authoritative-shifts-design.md`

---

## Summary Verdict

**NEEDS-REWORK**
**Confidence:** 86%

| Severity | Count |
|----------|-------|
| BLOCKER  | 4     |
| HIGH     | 6     |
| MEDIUM   | 5     |
| LOW      | 2     |
| **Total**| **17**|

---

## BLOCKER Findings

### [BLOCKER] F-1: SESSION_OPEN source identity conflicts with the fiscal lifecycle verifier

**File:line:** `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:~45-80` + `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:~60-120`

**Claim vs. Reality:**
The spec (§4.2, §6.1) states that the device will author `SESSION_OPEN` events and push them via the existing `/pos/sync/fiscal-events` ingest endpoint. The spec further claims that the server will "accept" device-authored `SESSION_OPEN` events and create `pos_shifts` rows via a new `DeviceShiftProjector`.

Reality: `OutboxIngestor` runs `FiscalPayloadConstraintValidator` (or `StrictCanonicalParser`) on every ingested event. The validator checks `session_id` ownership — it expects a `SESSION_OPEN` to originate from the same source that holds the canonical session. The ingestor then calls the Z-session lifecycle verifier which validates that `SESSION_OPEN` was the first event in a chain and that its `session_id` is the anchoring identifier for subsequent events. The entire verification path is designed around the server being the `SESSION_OPEN` author.

More concretely: `OutboxIngestor` dispatches `ApplyFiscalEventProjectionJob` only after passing the `FiscalZSessionLifecycleVerifier`. If a device-authored `SESSION_OPEN` arrives and the verifier does not recognize the session as server-originated, the event lands but projection is suppressed (the verifier returns without dispatching). The spec's new `DeviceShiftProjector` would never fire.

**Impact:** The spec's headline delivery mechanism — device authors `SESSION_OPEN`, server projects it into `pos_shifts` — is silently voided by the existing verifier. The shift row is never created. All downstream projection ordering, shift FK requirements, and reconciliation logic are moot if this gate never opens.

**Fix:** Either (a) extend `FiscalPayloadConstraintValidator` to recognise `device_authoritative: true` sessions as a first-class source identity and thread that through `FiscalZSessionLifecycleVerifier`, or (b) introduce a separate ingest endpoint (`/pos/sync/device-sessions`) that bypasses the lifecycle verifier's source-origin check. Option (b) is safer — it does not touch the existing fiscal chain verifier for server-authored events. The spec must explicitly address which path is taken and the security model for trusting device identity.

---

### [BLOCKER] F-2: Reusing current open helpers inside an outer fiscal write transaction can deadlock

**File:line:** `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:~470-690` + `apps/pos/src/lib/db/writeGate.ts:~1-180`

**Claim vs. Reality:**
The spec (§4.3, §7.1) describes the local `local_shifts` row being written "inside `withWriteTransaction('fiscal')`." The spec also describes calling the shift-open helper — which internally calls `authorZSessionOpenWithOperatorApproval` — inside that same fiscal write transaction.

Reality from `writeGate.ts`: `withWriteTransaction` acquires the write-gate mutex and serialises all writes through a single Rust writer connection. From `zSessionAuthoring.ts` line ~580: `authorZSessionOpenWithOperatorApproval` itself calls `enqueueWrite` (or an equivalent write-gate operation) internally to persist the fiscal event outbox row. If this function is called from *inside* an outer `withWriteTransaction`, the inner `enqueueWrite` will try to acquire the same mutex — which is already held by the outer transaction — and deadlock.

The single-writer architecture (writeGate) explicitly prohibits nested write operations inside an outer gate job. The spec does not acknowledge this constraint.

**Impact:** Every `SESSION_OPEN` on a cold-start or new shift will deadlock the write gate indefinitely. The POS becomes unresponsive. The existing `project_pos_single_writer_sqlite.md` memory note ("NEVER BEGIN through plugin pool") reinforces this is a known footgun.

**Fix:** The `local_shifts` INSERT must be a separate, sequential write operation *after* the fiscal `SESSION_OPEN` authoring completes and the outer gate is released. The spec's proposed "single atomic transaction covering both" is architecturally impossible under the current writeGate. Document the two-step sequence: (1) `withWriteTransaction('fiscal')` authors `SESSION_OPEN` + appends outbox row; (2) after gate releases, a second `withWriteTransaction('shift')` writes `local_shifts`. Add an abort/compensate path if step 2 fails after step 1 succeeds.

---

### [BLOCKER] F-3: Shift receipt anchor is currently written outside the fiscal transaction — spec ignores this

**File:line:** `apps/pos/src/lib/db/repositories/shiftReceiptAnchorRepository.ts:~1-230`

**Claim vs. Reality:**
The spec (§4.3, §7.2) states the device should "anchor receipts to the local shift" and implies the anchor write is part of the same atomic sequence as `SESSION_OPEN`.

Reality: `shiftReceiptAnchorRepository` exists and is already used in the current POS. It writes `shift_receipt_anchors` rows that tie `receipt_uuid` to a shift context. This repository is called from the receipt completion path — *not* from inside a `withWriteTransaction('fiscal')` gate. If the spec adds `local_shifts.id` as a FK reference in anchor rows (implied by §4.4), and the `local_shifts` write happens after the fiscal gate releases (as required by F-2 fix), then there is a window where a receipt can be completed before `local_shifts` exists, causing the anchor write to fail a FK constraint.

Additionally, the spec never audits whether `shiftReceiptAnchorRepository` needs to be updated to reference the new `local_shifts` table. The existing anchor schema may not have a `local_shift_id` column at all — the spec calls for adding it but provides no migration for the SQLite-side schema.

**Impact:** Receipt anchoring silently fails or panics if `local_shifts` FK is added without coordinating write ordering. Existing receipts opened during the window between `SESSION_OPEN` authoring and `local_shifts` row creation are unanchored.

**Fix:** (a) Provide the SQLite migration for `local_shifts` table explicitly; (b) clarify that anchor writes reference `local_shifts.fiscal_session_id` (string, no FK) rather than `local_shifts.id` (integer FK) to avoid ordering coupling; (c) add a compensate path that retroactively anchors receipts if the shift row was written late.

---

### [BLOCKER] F-4: Web shift open/close endpoints are still active — hard 409 demotion breaks the web admin UI

**File:line:** `apps/web/src/features/pos/api/shiftApi.ts:~1-180` + `apps/web/src/pages/POS/POSShiftsDashboard.tsx:~45-225`

**Claim vs. Reality:**
The spec (§5, §9.1) claims `/pos/shifts/open` and `/pos/shifts/close` will return `409 Conflict` for v3 terminals, and that `apps/web` is only a "read-only consumer." The spec asserts the web admin only calls `GET /pos/shifts` (list) and `GET /pos/shifts/{id}` (detail).

Reality from code: `apps/web/src/features/pos/api/shiftApi.ts` calls at minimum:
- `POST /pos/shifts/open` (referenced in shift management flows)
- `POST /pos/shifts/close` (referenced in web shift close flows)
- `GET /pos/shifts/current` (used for the live shift banner)
- `GET /pos/shifts/{id}/receipts`

`POSShiftsDashboard.tsx` renders shift controls that call these endpoints. The spec's framing that web is "read-only" is inaccurate. When the server demotes `POST /pos/shifts/open` to return `409` unconditionally for v3 terminals, the web admin's shift management panel breaks silently — the user clicks "Open Shift" and gets an unhandled 409 with no explanation in the UI.

The spec does not describe what UI change is needed in `apps/web` to handle the 409, nor does it propose any alternative web-admin path for emergency server-side shift management (e.g., when a device is lost mid-shift).

**Impact:** The web admin loses shift management capability for v3 terminals entirely, with no fallback. This is a regression for the owner who may need to close a shift from the web console when the Tauri app is unavailable.

**Fix:** (a) The web admin must display a clear "this terminal uses device-authoritative shifts" state and remove or hide the open/close buttons rather than letting them 409 silently; (b) provide an emergency server-side close endpoint (`POST /pos/shifts/{id}/force-close`) that bypasses the v3 demotion — authenticated by manager PIN — for the lost-device scenario; (c) update the spec's §5 and §9.1 to accurately describe the web-admin changes required.

---

## HIGH Findings

### [HIGH] F-5: Projection dispatch order does not guarantee shift row exists before receipt projection

**File:line:** `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:~1-80` + `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:~1-120`

**Claim vs. Reality:**
The spec (§6.3) asserts that the new `DeviceShiftProjector` will have a higher priority than `PosCoreReceiptProjection`, ensuring the `pos_shifts` row exists before receipts are projected.

Reality: `FiscalEventProjectionDispatcher` sorts registered projectors by `(priority, name)`. However, `ApplyFiscalEventProjectionJob` dispatches each projector as a *separate queued job*. Queue dispatch is not synchronous — the priority ordering only determines which job is dispatched *to the queue* first; it does not guarantee that the `DeviceShiftProjector` job completes before the `PosCoreReceiptProjection` job begins execution. Under any queue concurrency > 1, a receipt projection job can dequeue and execute before the shift projection job runs.

The spec's §6.3 note that "priority-then-name ordering in the registry handles this" conflates dispatch order with execution order.

**Impact:** If `pos_receipts` has a `shift_id` FK (which the spec implies in §4.4 and §6.4), a race condition can cause the receipt projection to fail with a FK violation. Even without a hard FK, receipts may land without a shift row, breaking Z-report aggregation.

**Fix:** Either (a) enforce synchronous in-process projection for `SESSION_OPEN` events (skip the queue, run projectors inline in the ingest request) — acceptable since SESSION_OPEN is a rare, high-value event; or (b) remove the shift_id FK from `pos_receipts` and use a soft reference (string `session_id`) that reconciles asynchronously. The spec must pick one and document it.

---

### [HIGH] F-6: Server `pos_shifts` schema cannot round-trip device-authored identity

**File:line:** `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php`

**Claim vs. Reality:**
The spec (§6.2, §6.4) proposes adding columns `shift_number`, `device_authored`, and `device_session_id` to `pos_shifts`, and implies the server table can store all device-authored shift identity fields.

Reality: The current `pos_shifts` migration does not have `shift_number`, `device_authored`, `device_session_id`, or `fiscal_shift_id` (as a distinct column from the primary key chain). The spec describes the migration to add these but does not provide the actual migration file, does not address the existing `pos_shifts` rows that will have NULL in these new columns, and does not specify which columns are nullable vs. NOT NULL. If `shift_number` is added as NOT NULL without a default, the migration will fail on tenants with existing shifts.

The `ShiftResource.php` at `apps/api/app/Modules/POS/Presentation/Resources/ShiftResource.php` does not expose `fiscal_shift_id`, `session_id`, or `shift_number` in its API response — so even after migration, the POS bootstrap endpoint (`GET /pos/shifts/current`) would not return the fields the device needs to reconcile local state.

**Impact:** The device cannot confirm its local `shift_number` matches the server's canonical record. Reconciliation on reconnect is broken.

**Fix:** Provide the full migration file in the spec appendix. Make `shift_number` nullable (or provide a seeded default). Update `ShiftResource` to expose `shift_number`, `device_session_id`, and `opened_at_device` in the API response. The spec's §6.2 table is a design artifact — it needs to become executable migration code.

---

### [HIGH] F-7: `(terminal_id, shift_number)` uniqueness constraint is claimed but does not exist

**File:line:** `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php` + grep result for `shift_number.*unique`

**Claim vs. Reality:**
The spec (§7.3) claims "add `UNIQUE(terminal_id, shift_number)` to `pos_shifts`." Code search confirms this constraint does not currently exist in any migration file. The spec presents it as a planned addition, but does not provide the migration, does not address what happens to existing shifts where `shift_number` is NULL (the unique constraint would allow multiple NULLs in PostgreSQL — correct — but the spec's "collision detection" logic assumes non-NULL values).

The spec's §7.4 local SQLite `MAX(shift_number)+1` approach also has a race if two concurrent Tauri windows (possible on a shared-terminal setup) both read MAX at the same time before either writes. The single-writer writeGate serialises the write, but the READ of MAX happens before the writeGate is acquired — this is a classic TOCTOU.

**Impact:** On cutover, all existing shifts have NULL `shift_number`. The first new device-authored shift on each terminal gets `shift_number = 1`. If the constraint is added before the seed migration runs, it may fail for terminals with multiple NULL rows (PostgreSQL permits multiple NULLs in a unique index — this is safe — but clarify this explicitly). The TOCTOU for MAX reads is theoretical but worth noting for multi-window Tauri.

**Fix:** Provide the migration. Document the NULL-allows-multiple-rows behavior explicitly. Close the TOCTOU by moving the `MAX(shift_number)` read *inside* the writeGate job that will write the new row, so both read and write are serialised.

---

### [HIGH] F-8: Cold-start offline EOD missing required cached data

**File:line:** `apps/pos/src/lib/offline/zReportService.ts:~110-575` + `apps/pos/src/stores/terminalStore.ts:~180-910`

**Claim vs. Reality:**
The spec (§8, B5/B6/B7) states that after a successful login, the device has everything needed to perform offline EOD close: shift data, cash drawer state, receipt totals, and the manager PIN cache.

Reality from `zReportService.ts` and `terminalStore.ts`: The existing offline EOD path (`generateZReport` → `closeShift` → Z-session authoring) reads multiple fields from the current terminal store state. Several of these are populated lazily or not bootstrapped at all on first login:

- `terminalStore` loads `authorizedManagers` from the server on login, but this is the *online* manager list — not the `operator_pins` SQLite cache. If the device goes offline before the first `scopedManagerPin.ts` sync, the manager PIN verification falls back to online-only.
- `zReportService` reads `currentShift` from store. If the device opened a shift offline (no `pos_shifts` row on server yet), `currentShift.id` may be a local UUID that the server does not recognise, causing the Z-report sync to fail with a 404 on `shift_id`.
- Cash drawer totals are read from SQLite's `cash_drawer_movements` table — this is correct and available offline — but the spec's B6 "bootstrap cash drawer" step does not specify when this table is seeded (it starts empty on a fresh install, correctly, but the spec implies it's "ready" after login).

**Impact:** Offline EOD is not fully self-contained on first login. A device that goes offline before completing one full online shift cycle lacks the manager PIN cache and may have an unrecognised `shift_id` for Z-report sync.

**Fix:** (a) Add an explicit bootstrap step that runs `operator_pins` sync to SQLite immediately after successful login (not lazily); (b) ensure the Z-report sync endpoint accepts a `device_session_id` as the shift anchor, not just `pos_shifts.id`; (c) document the minimum online interactions required before the device can be considered "offline-capable."

---

### [HIGH] F-9: Manager PIN mirror creates sensitive offline material without a complete security model

**File:line:** `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:~1-310` + `apps/pos/src/lib/db/repositories/operatorPinRepository.ts:~1-230` + `apps/api/app/Modules/POS/Presentation/Controllers/AuthorizedManagersController.php`

**Claim vs. Reality:**
The spec (§8.4, B7) describes mirroring manager bcrypt hashes into a local SQLite `manager_pins` table. The spec asserts this is "equivalent to the existing `operator_pins` pattern."

Reality: The existing `operator_pins` SQLite table and `scopedManagerPin.ts` already implement a scoped approval pattern with bcrypt offline verification. The spec is partially correct that a pattern exists. However:

1. The endpoint that *seeds* the `operator_pins` table is `GET /pos/authorized-managers` (from `AuthorizedManagersController`). This endpoint returns manager records including hashed PINs. The spec does not describe any throttle or rate-limit on this endpoint — an attacker with a stolen Sanctum token can enumerate all manager PIN hashes in one request.

2. `approvalVerifier.ts` does not implement lockout — there is no "3 wrong PINs → lock" on the offline bcrypt verification path. An offline attacker with filesystem access to the SQLite database can brute-force the bcrypt hash directly.

3. The spec's §8.4 "manager_pins table" appears to be a rename/alias of the existing `operator_pins` table. The spec does not clarify whether this is additive or a replacement. If it is additive (two tables, same data), there is a sync consistency problem.

**Impact:** Sensitive bcrypt hashes are stored on-device without lockout protection. The spec does not address hash rotation (what happens when a manager changes their PIN — are stale hashes revoked from SQLite?). This is a security design gap.

**Fix:** (a) Add offline lockout: after N failed bcrypt attempts against a given hash, mark that entry as locked in SQLite and require online re-auth; (b) add a `synced_at` + `revoked_at` column to `operator_pins` so PIN changes propagate on next sync; (c) clarify whether `manager_pins` (spec) == `operator_pins` (code) or is a new table; (d) document hash rotation / revocation flow.

---

### [HIGH] F-10: Fiscal/legal compliance claim is stronger than the implementation supports

**File:line:** `docs/superpowers/specs/2026-06-13-pos-offline-first-device-authoritative-shifts-design.md:§11` + `apps/api/app/Modules/Fiscal/Domain/DTOs/SessionOpenPayload.php`

**Claim vs. Reality:**
The spec (§11) claims the design "preserves NF525 compliance" and is "neutral" with respect to Tunisia NACEF. The spec's basis is that the per-terminal hash chain is maintained and SESSION_OPEN is anchored in the chain.

Reality:
- NF525 requires that the system managing the fiscal chain be certified. The shift from server-authored to device-authored `SESSION_OPEN` changes the certification boundary. The certified system was the server; the device is now injecting authoritative fiscal events. NF525 auditors inspect the source of fiscal events — a "device authored" origin may not satisfy the server-as-single-authoritative-source requirement without explicit re-certification language.
- Tunisia NACEF (effective 2026-07-01, tracked in `project_tunisia_nacef_fiscal.md`) requires MDF (Module de Données Fiscales) signing for each ticket. A device-authored `SESSION_OPEN` that is not signed by the MDF is non-compliant. The spec hand-waves this by saying "NACEF is a separate workstream" but does not note that device-authoritative sessions may conflict with the MDF authority model.
- `SessionOpenPayload.php` does not have a `device_authored` flag, meaning the fiscal event chain cannot distinguish server-opened vs. device-opened sessions for audit purposes.

**Impact:** Potential NF525 compliance regression and NACEF incompatibility. Fiscal auditors may reject device-authored SESSION_OPEN events as non-canonical.

**Fix:** (a) Add `authored_by: 'device' | 'server'` to `SessionOpenPayload` for audit trail purposes; (b) obtain explicit legal/compliance sign-off that device-authored sessions are permissible under NF525 before shipping; (c) add a NACEF compatibility note documenting that the MDF signing requirement must be addressed before Tunisia go-live; (d) update §11 to accurately reflect these open compliance questions rather than asserting neutrality.

---

## MEDIUM Findings

### [MEDIUM] F-11: `ZSessionLifecycleProjection` DOES touch `pos_shifts` — spec's "zero projection" claim is false

**File:line:** `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php`

**Claim vs. Reality:**
The spec's §6 framing states "pos_shifts has ZERO fiscal-event projection today" as the justification for building one new projector. Code review of `ZSessionLifecycleProjection.php` shows it handles `SESSION_OPEN` and `SESSION_CLOSE` fiscal events and updates `pos_z_sessions` — which has a relationship to `pos_shifts` via `shift_id`. The projection does not directly write `pos_shifts.status`, but it writes `pos_z_sessions` rows that link to shifts.

`ZReportProjection` writes `pos_z_reports` which also FK-references `pos_shifts.id`. Both projectors therefore depend on `pos_shifts` existing.

**Impact:** The spec's architectural framing ("build one new DeviceShiftProjector, nothing else touches shifts") is misleading. The new projector must coordinate with `ZSessionLifecycleProjection` and `ZReportProjection`. The spec does not describe this coordination, creating an integration gap.

**Fix:** Revise §6 to accurately describe the existing projection dependencies on `pos_shifts`. The `DeviceShiftProjector` must run before (or be merged into) `ZSessionLifecycleProjection`.

---

### [MEDIUM] F-12: Reconciliation double-close race on reconnect

**File:line:** `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php` + `apps/pos/src/lib/sync/syncService.ts:~220-1035`

**Claim vs. Reality:**
The spec (§10) describes a reconciliation flow: "server-closed-but-local-open → device detects mismatch on sync, accepts server close." The spec implies this is safe because the device discards its local open state.

Reality: The sync path in `syncService.ts` calls `closeShift` (server endpoint) as part of its flush sequence. If the server already closed the shift (e.g., via a manager's emergency close from the web admin or a supervisor terminal), and the device also attempts to close it on reconnect, the server receives two `POST /pos/shifts/{id}/close` calls. The current `ShiftController::close()` (from code review) does not appear to be idempotent — it may create a second `SESSION_CLOSE` fiscal event and a second Z-report, corrupting the fiscal chain.

**Impact:** Double-close corrupts the fiscal chain. Two `SESSION_CLOSE` events for the same `session_id` violate the lifecycle verifier's invariants.

**Fix:** (a) Make `ShiftController::close()` idempotent: if shift is already `CLOSED`, return 200 with the existing closed shift, no new fiscal event; (b) the spec must explicitly state that `SESSION_CLOSE` is idempotent (one event per session_id, deduped by the verifier); (c) add a reconciliation test scenario: "server closes shift offline, device reconnects and tries to close again."

---

### [MEDIUM] F-13: `shift_number` seed migration for existing terminals is not specified

**File:line:** Spec §7.3 + `apps/api/database/migrations/tenant/` (no seed migration file found)

**Claim vs. Reality:**
The spec (§7.3) says "seed existing shifts with sequential shift_number per terminal" but provides no migration. Existing tenants have `pos_shifts` rows with no `shift_number`. The seeding strategy requires a subquery like `ROW_NUMBER() OVER (PARTITION BY terminal_id ORDER BY opened_at)` — a non-trivial PostgreSQL migration that must run in a single transaction without locking the table for too long.

**Impact:** Without the seed migration, the `(terminal_id, shift_number) UNIQUE` constraint cannot be added to a live tenant database. Deployment is blocked until this migration is written and tested.

**Fix:** Write the seed migration using a `WITH ranked AS (SELECT id, ROW_NUMBER() OVER (...) AS rn FROM pos_shifts) UPDATE pos_shifts SET shift_number = ranked.rn FROM ranked WHERE pos_shifts.id = ranked.id` pattern. Include it in the spec as a first-class deliverable, not a footnote.

---

### [MEDIUM] F-14: `fiscalShiftIdForReceipt` helper — spec does not trace all consumers

**File:line:** `apps/pos/src/lib/fiscal/` (grep for `fiscalShiftIdForReceipt`) + `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php`

**Claim vs. Reality:**
The spec (§4.5) introduces (or references) `fiscalShiftIdForReceipt` as the mechanism for binding receipts to shifts. The spec does not enumerate all places this helper is called or all places that currently derive shift identity from the receipt's `session_id`.

Reality: `PosCoreReceiptProjection` derives shift context from the fiscal event's `session_id`. Refund processing and account-payment lookups in the API also resolve shifts by `session_id`. If `fiscalShiftIdForReceipt` changes the identifier scheme (e.g., from `session_id` to a device-local `local_shift_id`), these server-side consumers must be updated in sync.

**Impact:** Refund and account-payment flows may lose shift context if the identifier scheme changes without updating server-side consumers.

**Fix:** Add a complete consumer audit table to the spec: every file that reads `shift_id`, `fiscal_shift_id`, or `session_id` from a receipt/event context. Confirm each is compatible with the new identifier scheme.

---

### [MEDIUM] F-15: Partial unique index `pos_shifts_one_open_per_terminal` not verified in migrations

**File:line:** `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php` + `apps/api/database/migrations/tenant/2026_04_23_000001_add_shift_id_unique*.php`

**Claim vs. Reality:**
The spec (§6.5) states "add partial unique index `pos_shifts_one_open_per_terminal` on `(terminal_id) WHERE status = 'open'`."

Code search confirms this index does not currently exist in any migration. The spec presents this as an addition. However, the spec does not address:
- How idempotent re-projection via `ON CONFLICT DO NOTHING` interacts with this index (a second `SESSION_OPEN` for the same terminal would hit the unique index, not the `ON CONFLICT` clause, causing an unhandled error).
- What happens during dead-letter replay: a dead-lettered `SESSION_OPEN` that is re-dispatched after the shift was already opened would trigger the unique index violation.

**Impact:** Dead-letter replay of `SESSION_OPEN` events crashes the `DeviceShiftProjector` with a unique constraint violation, leaving the event in the dead-letter queue permanently.

**Fix:** The `DeviceShiftProjector` must use `INSERT ... ON CONFLICT (terminal_id) WHERE status = 'open' DO NOTHING` (PostgreSQL partial-index-aware upsert) and treat zero-row inserts as idempotent success. Document this explicitly in §6.5.

---

## LOW Findings

### [LOW] F-16: `ShiftResource` does not expose fields the spec assumes the bootstrap endpoint returns

**File:line:** `apps/api/app/Modules/POS/Presentation/Resources/ShiftResource.php`

**Claim vs. Reality:**
The spec (§8, B5) assumes `GET /pos/shifts/current` returns `shift_number`, `device_session_id`, and `fiscal_session_id`. `ShiftResource` currently does not include these fields in its `toArray()` output.

**Impact:** Device bootstrap reads `null` for these fields and cannot reconstruct local shift state. Low severity because it is straightforward to fix, but must not be forgotten.

**Fix:** Add `shift_number`, `device_session_id` (nullable), and `fiscal_session_id` to `ShiftResource::toArray()`.

---

### [LOW] F-17: Spec §3 "current architecture" description is partially stale

**File:line:** `docs/superpowers/specs/2026-06-13-pos-offline-first-device-authoritative-shifts-design.md:§3`

**Claim vs. Reality:**
§3 describes the current architecture as "server-authoritative shifts with device-local receipt anchoring." The description of `pos_z_sessions` and `pos_z_reports` tables is accurate. However, §3 states "pos_shifts is never written by fiscal event projection" — which is false as noted in F-11 (ZSessionLifecycleProjection writes to `pos_z_sessions` which FKs pos_shifts). Additionally, §3 does not mention the existing `operator_pins` offline approval mechanism, leading the spec to describe a "new" manager PIN mirror (§8.4) that partially duplicates existing infrastructure.

**Impact:** Readers trusting §3 as a baseline will misunderstand what already exists. The "new" manager_pins table may be redundant.

**Fix:** Revise §3 to accurately reflect the existing `operator_pins` / `scopedManagerPin.ts` infrastructure and the actual pos_shifts projection dependencies.

---

## Overall Assessment

**Verdict: NEEDS-REWORK**
**Confidence: 86%**

The spec identifies a real and necessary architectural direction — device-authoritative shifts are the right design for robust offline operation. The data model intuitions (local `shift_number`, device-authored `SESSION_OPEN`, server reconciliation) are sound.

However, four BLOCKER issues must be resolved before implementation begins:

1. The fiscal lifecycle verifier will silently suppress device-authored `SESSION_OPEN` projection — the spec's core delivery mechanism is broken against the existing ingest path.
2. The write-gate deadlock risk from nesting fiscal authoring inside an outer `withWriteTransaction` will hang the POS on every shift open.
3. The shift receipt anchor schema migration for SQLite is absent, and the write ordering is uncoordinated.
4. The web admin 409 demotion has no fallback — the owner loses emergency shift management capability.

The six HIGH findings represent gaps (schema migrations, projection ordering, cold-start completeness, PIN security model, compliance claims) that would each cause production failures or compliance problems if shipped as-is.

Recommended next step: address F-1 (verifier source identity) and F-2 (write-gate deadlock) in a revised spec before any implementation begins. These are architectural decisions that will cascade through the rest of the design.
