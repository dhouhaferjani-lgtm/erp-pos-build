# POS Offline-First, Device-Authoritative Shifts — Design Spec

**Date:** 2026-06-13
**Branch:** `feat/parapharmacy-tunisia-demo` (worktree `apps/erp.db-per-tenant`)
**Scope:** Tauri POS (`apps/pos`) + Laravel API (`apps/api`). Web admin (`apps/web`) read-only consumer impact assessed (§5, §9).
**Status:** Synthesis of six investigation agents (online research, current shift lifecycle, receipt-model template, shift-identity consumers, server-side shift/projection state, online touchpoints). Pending owner sign-off on OPEN DECISIONS (§11) then Codex adversarial review.
**Predecessors:** `2026-06-12-pos-offline-single-writer-design.md` (the single-writer `writeGate` this design builds on), `2026-06-11-pos-location-aware-stock-design.md` (terminal payload + `fiscal_schema_version` v3 gating precedent).

---

## 1. Problem & Goal

### 1.1 North star

The owner's target: **the only thing that should require a network connection is the very first manager/owner login (email + password) and the bootstrap it triggers.** Everything after that — including **shift OPEN / CLOSE** — must work offline-first with excellent performance. Receipts already meet this bar; shifts do not.

### 1.2 Motivating evidence — the server-first shift seam

Receipts are device-authoritative (device mints the receipt UUID, authors a `SALE_RECEIPT` fiscal event into SQLite via `withWriteTransaction('fiscal', …)`, and the server `pos_receipts` table is a **projection** of the synced fiscal event). Shifts are not: `terminalStore.openShift()` (`apps/pos/src/stores/terminalStore.ts:755`) calls `POST /pos/shifts/open` **first**, and only forks a local `offline-<uuid>` shift on failure (`:793-812`); `fetchCurrentShift()` calls `GET /pos/shifts/current/{code}` **first** (`:726`); `closeShift()` calls `POST /pos/shifts/{id}/close` **first** (`:854`). The server assigns `shift.id` (UUID) and `shift_number` (sequential).

This seam produced the **2026-06-12/13 shift bugs** captured in the live code comments at `terminalStore.ts:771-779`:
- An offline-opened shift gets `id: 'offline-<uuid>'` (not a UUID) and `shift_number: 0`.
- That non-UUID id leaks into server routes: `GET /pos/shifts/offline-<uuid>/receipts` 404s (Today Sales), `POST /pos/shifts/offline-<uuid>/close` 404s silently (server never learns the shift closed), and earlier builds put a non-UUID `shift_id` into fiscal payloads.
- The `SHIFT_ALREADY_OPEN` adoption branch (`:778`) makes a **second** network call to adopt the server shift, and if that call fails the open throws entirely (`:788-790`) — a reachable-but-flaky server can block shift open with no local fallback (online-touchpoints B4).
- On reconnect the server's view silently wins (`fetchCurrentShift` is server-first), with no reconciliation — a shift closed from web admin re-appears as open on the device, and an offline-opened shift is invisible server-side until/unless reconciled.

### 1.3 Goal

Make the shift lifecycle **device-authoritative and a server projection**, mirroring the receipt model exactly:

> The device mints the shift (UUIDv7 id + device-assigned `shift_number` from a local per-terminal counter), authors `SESSION_OPEN` + `OPENING_FLOAT` (and at close `SESSION_CLOSE` + `Z_REPORT`) locally through the single-writer `writeGate`, and `pos_shifts` becomes a **projection** of those fiscal events. The REST shift endpoints stop being the authority.

Performance target: shift open/close completes at device-local SQLite speed (target **< 150 ms**, same envelope as the receipt tx), with zero network in the path.

---

## 2. Current-state map

### 2.1 Already device-authoritative (reuse as-is)

| Capability | Where | Status |
|---|---|---|
| Receipt authoring (mint UUID → `withWriteTransaction('fiscal')` → `fiscal_events` + `offline_receipts` + chain-head advance, atomic) | `receiptService.ts:243-567` | DONE |
| Fiscal event sync (push `WHERE sync_status IN ('pending','failed') ORDER BY chain_context, sequence_number`; stranded-`syncing` recovery; idempotency key `terminal_id:chain_context:sequence_number`) | `syncService.ts:229-346` | DONE — pushes **all** pending `fiscal_events` regardless of `event_type`, so z-session events ride the same rail |
| Server ingest (`OutboxIngestor`: shape + tenant pre-flight → verify hash/linkage/clock → `INSERT … ON CONFLICT DO NOTHING` → quarantine on conflict → dispatch projections via `DB::afterCommit()`) | `FiscalEventIngestionController.php`, `OutboxIngestor.php:143-284` | DONE |
| Device authoring of `SESSION_OPEN` + `OPENING_FLOAT` at shift open, on the z_session chain, via `withWriteTransaction('fiscal')` | `terminalStore.ts:241` (`authorShiftOpenFiscalEvents`) → `authorZSessionOpenWithOpeningFloat` → `zSessionAuthoring.ts` | DONE — but only runs for `fiscal_schema_version === 3` terminals (`terminalStore.ts:846`) and currently runs **after** the REST open |
| Device authoring of `SESSION_CLOSE` + `Z_REPORT` at close | `zSessionAuthoring.ts:596` (`appendZSessionCloseAndZReport`), `zReportService.ts` | DONE |
| Device-minted `fiscal_shift_id` + `fiscal_session_id` (UUIDs) | minted at `terminalStore.ts:804-805` (offline) and via `authorShiftOpenFiscalEvents` (online) | DONE |
| Receipt→shift linkage helper (resolves `fiscal_shift_id`, strips `offline-` prefix, fail-loud) | `fiscalShiftIdForReceipt()` `terminalStore.ts:206` | DONE — already UUID-aware; consumed by `paymentStore.createReceiptLocalFirst` etc. (no rewire needed) |

### 2.2 Still server-first (must change)

| Path | Where | Behavior today |
|---|---|---|
| Shift open | `terminalStore.openShift()` `:755` | `POST /pos/shifts/open` first; offline fork creates `offline-<uuid>` + `shift_number: 0` |
| `SHIFT_ALREADY_OPEN` adoption | `:778` | second network call, no offline fallback (B4) |
| Current shift on boot | `fetchCurrentShift()` `:726` | `GET /pos/shifts/current/{code}` first; localStorage fallback only on catch; server silently wins on reconnect |
| Shift close | `closeShift()` `:854` | `POST /pos/shifts/{id}/close` first; 404s silently for offline shifts; server keeps the shift open |
| `shift_number` | server `ShiftManagementService::openShift` (`MAX(shift_number)+1` per terminal) | server-authoritative; `0` offline |
| Today Sales / cash-drawer ops fetch | `reportApi.fetchShiftReceipts/fetchCashDrawerOps`, `cashDrawerApi.cashDrawerPayload`, web `shiftApi` | send raw `shift.id` to routes that `Shift::findOrFail($id)` → 404 on offline ids |

### 2.3 CRITICAL: how much of the SESSION_OPEN/CLOSE → `pos_shifts` projection already exists

**Headline finding: ZERO of `pos_shifts` is projected from fiscal events today. The work is BUILD a new projector, not rewire an existing one — but every input it needs already exists in the synced payloads.**

Precisely:
- `ZSessionLifecycleProjection::apply()` handles `SESSION_OPEN`, `OPENING_FLOAT`, `CASH_IN/OUT`, `SAFE_DROP`, `CASH_CORRECTION`, `SESSION_CLOSE`, `X_REPORT` — and writes **only** to `pos_z_session_events` (one audit/mirror row per fiscal event, keyed on `fiscal_event_id`). It contains **zero** references to `Shift` / `pos_shifts`. `grep "pos_shifts|Shift::" app/Modules/POS/Application/Projections/` is empty.
- `ZReportProjection::apply()` handles `Z_REPORT` → writes **only** `pos_z_reports`. No `pos_shifts` write.
- **No fiscal-event ingest path writes `pos_shifts` at all.** Every `pos_shifts` mutation today is REST-driven (`ShiftController::open/close`, `SyncController::syncCloseShift`, `ZReportSyncController::sync`, `ReportController::generateZReport`).
- Both projectors are registered via `$this->app->tag([...], FiscalEventProjector::class)` in `POSServiceProvider::register()` (`apps/api/app/Modules/POS/Providers/POSServiceProvider.php:50-58`) — the exact insertion point for a new `ShiftLifecycleProjection`.

**What already exists to build on:**
- `SESSION_OPEN` payload (`SessionOpenPayload.php`) carries `shift_id` (device UUID), `session_id`, `terminal_id`, `operator_id` (→ `cashier_id`), `operator_name`, `opening_float_amount` (→ `opening_cash`), `business_date`, `opened_at_device` (→ `opened_at`), `currency_code`. **Gap: no `shift_number`** (server assigns it today).
- `SESSION_CLOSE` payload (`SessionClosePayload.php`) carries `shift_id`, `counted_cash` (→ `actual_cash`), `expected_cash`, `variance_amount/direction/severity/reason`, `manager_approval` (→ `manager_override_by`), `operator_id` (→ `closed_by`), plus full cash-count/payment-method/VAT breakdowns. **Everything `pos_shifts` close needs is present.**
- `pos_shifts` has the right guard rails: partial unique index `pos_shifts_one_open_per_terminal ON (terminal_id) WHERE status='OPEN'` (`2026_01_08_190641_create_pos_shifts_table.php:69`), `pos_shifts_closed_logic` CHECK, variance CHECK. **No append-only trigger** (rows are mutable — fine, a projection updates them).
- The `ZReportSyncController` already implements the exact v3-gating pattern we will reuse: `if ((int)($terminal->fiscal_schema_version ?? 2) >= 3) { return 409 Z_SESSION_DEVICE_AUTHORITY_REQUIRED; }` (`ZReportSyncController.php:72-80`).

---

## 3. Target architecture — device-authoritative shift lifecycle as a projection

### 3.1 The recipe (the receipt model, applied to shifts)

The abstract pattern distilled from the receipt path, applied step-by-step to shift open:

1. **Mint all identifiers device-side, unconditionally.** `shiftId` = UUIDv7 (machine id; FK target for all shift-scoped rows). `sessionId` = UUIDv7. `shift_number` = device-local per-terminal monotone counter (human id). No identifier the device depends on is ever assigned by the server. `fiscal_shift_id` collapses to **be** `shiftId` (one UUID, not two) — see §4.f.
2. **Author the canonical fiscal event(s) via the single-writer transaction.** Inside `withWriteTransaction('fiscal', tx => …)`: `engine.append(tx, { event_type:'SESSION_OPEN', chain_context:'z_session', source_event_class:'local_shifts', source_event_id: shiftId, payload })` then `engine.append(tx, { event_type:'OPENING_FLOAT', … })`. `source_event_id = shiftId` makes the device-side idempotency probe dedupe retries before any mutation.
3. **Write the local projection row in the SAME transaction.** Insert into a **new local SQLite `local_shifts` table** (the device-side mirror of `pos_shifts`) atomically with the two fiscal-event rows and the chain-head advance. Any failure → ROLLBACK → no orphaned shift, no orphaned event, no stale chain head.
4. **Post-commit: bump pending count + schedule debounced sync** — `useSyncStore.getState().incrementPendingCount()` + `scheduleDebouncedSync()`, after the tx resolves (never inside).
5. **Sync** rides the existing `pushOfflineReceipts` rail (renamed conceptually to "push pending fiscal events" — it already pushes all event types in chain order).
6. **Stranded-`syncing` recovery** already runs at the top of every push tick (`recoverStrandedSyncingFiscalEvents`) — unchanged.
7. **Server ingests** via `OutboxIngestor` — unchanged. The `verifyZSessionLifecycle` check already enforces SESSION_OPEN-before-others and no-duplicate-SESSION_OPEN per session.
8. **Server dispatches projection jobs** via `DB::afterCommit()` — unchanged plumbing; we add a new projector to the tagged set.
9. **New `ShiftLifecycleProjection` creates/closes `pos_shifts`**, idempotent on `pos_shifts.id = shift_id` (device UUID becomes the PK, exactly as `pos_receipts` uses the device receipt UUID and `fiscal_event_id` uniqueness). `SESSION_OPEN` → insert OPEN row; `SESSION_CLOSE` → update to CLOSED with cash-count fields.
10. **Never block the business operation on network.** `withWriteTransaction` is pure SQLite; the UI returns immediately after commit. Sync is background.

### 3.2 Close lifecycle

`SESSION_CLOSE` + `Z_REPORT` are already authored on-device (`appendZSessionCloseAndZReport`). The change is purely to **stop calling `POST /pos/shifts/{id}/close` as the gate** and let the `ShiftLifecycleProjection` close the `pos_shifts` row from the synced `SESSION_CLOSE` event. `Z_REPORT` continues to project to `pos_z_reports` via the existing `ZReportProjection`.

### 3.3 "Current open shift" is answered purely from local SQLite

`fetchCurrentShift()` reads `local_shifts WHERE terminal_id = ? AND status = 'OPEN'` from SQLite — **no network**. The server is consulted only by a background reconciliation pass (§4.c), never as the source of truth for "is there an open shift on this terminal right now."

---

## 4. Specific design decisions

### (a) `shift_number` assignment & reconciliation offline — **RECOMMENDATION: device-local per-terminal counter, two-tier numbering, NO server renumber**

The device maintains a monotone `shift_number` per terminal in `local_shifts` (`SELECT MAX(shift_number)+1 FROM local_shifts WHERE terminal_id = ?`, computed inside the open transaction). This is the **human-facing** number printed on Z-reports and shown in the header. It is carried in the `SESSION_OPEN` payload as a **first-class field** (`shift_number` added to `SessionOpenPayload`, no `SessionOpenV2` — the system is pre-live, see Resolved Decision 2) so the server stores the device's value verbatim — `pos_shifts.shift_number` becomes device-authored, exactly as `shift_id` does.

Rationale (online research §3): the **terminal id is the namespace**, so per-terminal counters never collide across devices by construction (single-device-per-terminal is the deployment model; a uniqueness constraint on `(terminal_id, shift_number)` makes the rare double-device case fail loud rather than silently merge). NF525 chains **per register**, so a per-terminal sequence satisfies "sans rupture de séquence." A server-side global renumber is explicitly **rejected**: it would require the device to know the server's last number (defeats offline-first) and would make the printed number differ from the synced number.

**Migration of the existing seed:** on first boot after rollout, seed the `local_shifts` counter from the server's current `MAX(shift_number)` for the terminal (one-time, online; if offline, seed from the last cached shift's number) so numbering continues monotonically rather than restarting at 1. Add `pos_shifts (terminal_id, shift_number)` unique constraint server-side.

### (b) Disposition of the REST shift endpoints — **RECOMMENDATION: demote to v3-gated reconcile/read; keep for legacy (v<3) + web admin reads**

Cross-app check (consumers findings): **`apps/web` DOES depend on these.** `apps/web/src/features/pos/api/shiftApi.ts` calls `GET /pos/shifts/current/{terminalId}` (`:75`), `closeShift` → `POST /pos/shifts/{id}/close` (`:94`), `getShiftBalance` → `GET /pos/cash-drawer/{id}/balance` (`:129`), `getShiftReceipts` → `GET /pos/shifts/{id}/receipts` (`:155`), `recordCashDeposit/Payout` (`:115/:123`). So we cannot delete them.

| Endpoint | Disposition for v3 terminals | Kept for |
|---|---|---|
| `POST /pos/shifts/open` | **409 `SHIFT_DEVICE_AUTHORITY_REQUIRED`** (same pattern as `ZReportSyncController:72`). Device never calls it. | Legacy v<3 terminals only |
| `POST /pos/shifts/{id}/close` | **409** for v3 (close is the `SESSION_CLOSE` projection). | Legacy v<3; web-admin close of a v<3 shift |
| `POST /pos/shifts/{id}/sync-close` | **Retire for v3** (never wired in POS anyway). | Legacy |
| `GET /pos/shifts/current/{code}` | **Keep as read/reconcile** (returns the projected `pos_shifts` row). Device uses it only for background reconciliation, never as the open gate. | Web admin dashboard; device reconcile |
| `GET /pos/shifts/{id}/receipts`, `GET /pos/cash-drawer/{id}/operations`, `/balance` | **Keep**, but `Shift::findOrFail` must resolve by the device UUID (which is now `pos_shifts.id`) — once shifts are projected with the device UUID as PK, the existing `offline-<uuid>` mismatch disappears. | Web admin + POS online Today-Sales |
| Web admin cash deposit/payout (`CashDrawerController::deposit/payout`) | Out of scope for this spec; flagged as a follow-up (route web cash-drawer ops through a server-authored fiscal event, mirroring the back-office DEPOSIT_RECEIPT pattern). For now they keep working against projected v3 shift rows because the row exists once `SESSION_OPEN` projects. | — |

### (c) "Current open shift" answered purely from local SQLite — **RECOMMENDATION: SQLite-first, server reconcile is advisory + audited**

`fetchCurrentShift()` reads `local_shifts` for the OPEN row (no network). A background reconcile (in the sync scheduler) compares the local open shift against `GET /pos/shifts/current/{code}`:
- **Server has the same shift OPEN** → no-op (healthy).
- **Server has it CLOSED but local has it OPEN** → the close was authored elsewhere (web admin force-close). This is a genuine conflict; surface a banner ("This shift was closed remotely — review and reconcile") rather than silently flipping local state. Do **not** auto-close locally (a local sale may be mid-flight). Audited.
- **Server has no row but local is OPEN** → the `SESSION_OPEN` event has not yet synced/projected. Normal offline state; no action.

The reconcile is **advisory** — it never overrides the device's local truth without operator awareness. This directly fixes the "server silently wins on reconnect" bug.

### (d) Conflict / duplicate handling — **RECOMMENDATION: lean on existing idempotency + the partial unique index as the one-open invariant**

- **Idempotent SESSION_OPEN ingestion:** the `(source_event_class, source_event_id=shiftId)` device probe + the server `ON CONFLICT DO NOTHING` on the fiscal-events sequence slot + `verifyZSessionLifecycle` (no duplicate SESSION_OPEN per session) already make re-delivery a no-op. The projection is idempotent on `pos_shifts.id` (`INSERT … ON CONFLICT (id) DO NOTHING`).
- **One-open-shift-per-terminal under projection:** the existing partial unique index `pos_shifts_one_open_per_terminal` enforces it at the DB level. If two `SESSION_OPEN` events for the same terminal somehow both try to project an OPEN row (e.g., a terminal mis-provisioned to two devices), the second projection INSERT violates the partial unique index → the projection job dead-letters and is surfaced, rather than silently double-opening. The device side prevents this by refusing a local open if `local_shifts` already has an OPEN row for the terminal (fail-loud), mirroring the server invariant.
- **Shift-number collision:** `pos_shifts (terminal_id, shift_number)` unique constraint (new) → a colliding number from a rogue second device dead-letters loudly.

### (e) Cold-start bootstrap definition — the exact data cached on first login for full offline operation thereafter

After the **single online login** (email+password → token, `/user/companies`), the bootstrap MUST durably cache (SQLite unless noted) the following so that **every** subsequent operation including shift open/close works offline. Anything not listed here is a launch gap to close (cross-referenced to online-touchpoints B-class gaps):

| Datum | Source endpoint (one-time) | Cache target | Current gap |
|---|---|---|---|
| Auth token + user | `POST /auth/login` | localStorage (encrypted) | — (class A, acceptable) |
| Company list + selected company | `GET /user/companies` | localStorage | — |
| Terminal record (claimed) | `POST /pos/terminals/claim` / `GET /pos/terminals/{id}` | localStorage `TERMINAL` | — (claim is one-time A) |
| Fiscal chain genesis seed + last hash + sequence + `fiscal_schema_version` | `GET /pos/terminals/{id}` (terminal-state pull) | SQLite `terminal_state` | — |
| Z-chain state | `GET /pos/terminals/{id}/z-chain-state` | SQLite `terminal_state` | — |
| **`shift_number` counter seed** (last server shift number for terminal) | `GET /pos/shifts/current/{code}` or a new lightweight `GET /pos/shifts/last-number/{code}` | SQLite `local_shifts` / `terminal_state` | **NEW — must cache the seed so offline numbering continues monotonically** |
| Product catalog | `GET /products` (paginated) | SQLite `products` | — |
| Active menu (F&B) | `GET /active-menu` | SQLite `menu_*` | — |
| Location stock baseline | location-stock pull | SQLite `location_stock` | — |
| Payment methods + repositories | `GET /payment-methods`, `/payment-repositories` | SQLite `payment_methods`/`payment_repositories` | — |
| Operator PINs (bcrypt) | `GET /pos/auth/pin-data` | SQLite `operator_pins` | — |
| Discount permissions | `GET /pos/discount-permissions` | SQLite `operator_pins` cols | — |
| **Fraud settings** (variance thresholds, blind-count flag) | `GET /pos/fraud-settings` | **SQLite (via `refreshFraudSettingsCache`, currently NOT called on EOD path)** | **GAP B6 — EOD close offline currently has null fraud settings** |
| **Authorized managers** (for EOD manager-PIN authorization) | `GET /pos/authorized-managers` | **none today** | **GAP B6 — must cache** |
| **Manager PIN hashes** (for EOD approval above hard variance) | new `GET /pos/manager-pin-data` (bcrypt hashes, mirrors `/pos/auth/pin-data`) | **SQLite `manager_pins`** (new mirror table) | **GAP B7 → RESOLVED (Decision 1): mirror bcrypt hashes to device; verify locally** |
| `companyConfig` (modules, vertical, receipt visibility) | `GET /company/config` | **in-memory only; durable SQLite cache missing** | **GAP B5 — Menu/Standard routing undetermined on offline cold boot** |

**Definition of "cold-start bootstrap complete":** all rows above are present in SQLite/localStorage. Until B5/B6/B7 are closed, the shift-CLOSE-offline path is not fully offline-capable even though shift-OPEN-offline is. These are tracked as explicit phases (§7) because shift CLOSE is in scope.

### (f) `fiscal_shift_id` collapses into `shiftId` — **RECOMMENDATION: one UUID**

Today the device mints two UUIDs (`fiscal_shift_id` and the shift `id`), with `fiscalShiftIdForReceipt()` reconciling them and stripping the `offline-` prefix. Once the device mints the shift `id` as a real UUIDv7 unconditionally, `fiscal_shift_id === shiftId`. The `Shift` type keeps `fiscal_shift_id` as a (now redundant) alias during migration for consumer compatibility, then it is removed. `fiscal_session_id` stays distinct (a session can in principle span constructs, and the payloads already separate `shift_id`/`session_id`).

---

## 5. Consumer rewiring list

Legend: **R** = rewire required, **OK** = already device-UUID-compatible (no change), **S** = server-side projection/guard change.

### apps/pos

| Consumer (file:line) | Field | Action |
|---|---|---|
| `terminalStore.openShift()` `:755` | mints id + number | **R** — mint UUIDv7 + local `shift_number`; author fiscal events + `local_shifts` insert in one `withWriteTransaction`; drop the server-first POST + `offline-<uuid>` fork + `SHIFT_ALREADY_OPEN` branch |
| `terminalStore.fetchCurrentShift()` `:726` | server-first | **R** — read `local_shifts` OPEN row; background reconcile only |
| `terminalStore.closeShift()` `:854` | server-first POST | **R** — author `SESSION_CLOSE`+`Z_REPORT` (already exists) + update `local_shifts` to CLOSED; drop the gate POST |
| `terminalStore.fiscalShiftIdForReceipt()` `:206` | resolver | **OK** — already UUID-aware; simplifies once id==fiscal_shift_id |
| `terminalStore.authorShiftOpenFiscalEvents()` `:241` | mints fiscal UUIDs | **R (minor)** — called unconditionally as part of open (not only when server shift exists); pass device `shiftId`/`sessionId` |
| `paymentStore.createReceiptLocalFirst/AccountPayment/AccountCharge` `:565/:671/:756` | `fiscalShiftIdForReceipt` | **OK** |
| `zSessionAuthoring.ts` (all builders) | device UUIDs | **OK** |
| `zReportService.generateZReport()` `:114` | `shiftId` param | **R (consistency)** — callers must pass the device shift UUID (== `fiscal_shift_id`) so `z_reports.shift_id`, cash-drawer ops, refund/account-payment lookups all key on one id |
| `endOfDayPreview.buildEndOfDayPreview()` `:105` | `shiftId` | **R (consistency)** — same one-id rule |
| `reportApi.fetchShiftReceipts(shiftId)` `:296` | server route | **R** — pass device UUID (now valid server PK); SQLite-first when offline (already) |
| `reportApi.fetchCashDrawerOps(shiftId)` `:310` | server route | **R** — same |
| `cashDrawerApi.cashDrawerPayload()` `:198` | `shift_id: shift.id` | **R** — send device UUID; or route through fiscal event (follow-up) |
| `cashDrawerApi.saveOfflineCashDrawerOp()` `:104` | stores `shift.id` | **R (consistency)** — store device UUID |
| `cashDrawerApi.authorCashDrawerMovement()` `:153` | fiscal UUIDs | **OK** |
| `Header.tsx` X/Z opts `:191-264`, `:449` badge | fiscal UUIDs + `shift.id` + `shift_number` | **R (minor)** — pass device UUID as `shiftId`; `shift_number` now device-authored (display unchanged) |
| `TodaySalesPanel.tsx:35` | `shift?.id` | **R** — device UUID valid server-side |
| `CloseShiftModal.tsx:62`, `EndOfDayPreviewModal.tsx:221`, `SettingsPage.tsx:57` | `shift_number`/`id` display | **OK (display)** |
| EOD fraud settings / managers / manager-PIN (`Header.tsx:112-162`) | network-only | **R** — cache fraud settings + managers to SQLite (B6); manager-PIN offline (B7, see OPEN DECISION) |

### apps/api

| Consumer (file:line) | Action |
|---|---|
| **NEW `ShiftLifecycleProjection`** (handles `SESSION_OPEN`→create OPEN row, `SESSION_CLOSE`→close row), tagged in `POSServiceProvider::register()` alongside the existing projectors (`:50-58`) | **S — BUILD (the core new piece)** |
| `ShiftController::open` / `ShiftManagementService::openShift` | **S** — 409 `SHIFT_DEVICE_AUTHORITY_REQUIRED` for v3; legacy unchanged |
| `ShiftController::close` / `closeShift` / `SyncController::syncCloseShift` | **S** — 409 for v3; close happens via projection |
| `ShiftController::current/index/receipts` | **S (minor)** — work as-is once `pos_shifts.id` == device UUID; `receipts` already filters by cashier+time |
| `ShiftResource` | **S** — echo `fiscal_shift_id`/`session_id` (now == id) for round-trip verification |
| `ZSessionLifecycleProjection` | **OK** — keeps writing `pos_z_session_events`; unchanged |
| `ZReportProjection` `:66` (`where shift_id`) | **OK** — keys on device UUID already; works once `pos_shifts.id` == that UUID |
| `CashRegisterReportService` (queries `pos_shifts` for closed rows) | **OK once** `ShiftLifecycleProjection` closes the row with `actual_cash`/`variance` from `SESSION_CLOSE` |
| `Nf525DataProvider` (`cash_drawer_operations.shift_id`, `z_session_events.shift_id`, `shift->cashier_id`) | **OK** — z_session path uses device UUID; cashier_id is in `SESSION_OPEN` payload |
| `pos_shifts` migration | **S** — add `(terminal_id, shift_number)` unique; optional `session_id` column; allow device-UUID PK insert (already `uuid` PK) |

### apps/web

| Consumer (file:line) | Action |
|---|---|
| `shiftApi.getCurrentShift/closeShift/getShiftBalance/getShiftReceipts/recordCashDeposit/Payout` `:75-155` | **OK (read)** — once shifts project to `pos_shifts` with device UUID, these resolve; web admin close of a v3 shift returns 409 with a clear message (web should not close v3 shifts — they close on-device) |
| `ShiftDashboardPage`, `ShiftHistoryPage` (`shift_number`, `cashier_name`, `expected_cash`, `key={shift.id}`) | **OK (display)** — `cashier_name` projected from `SESSION_OPEN` `operator_name`; `expected_cash` computed server-side as today |

---

## 6. Migration & rollout

1. **Gated by `fiscal_schema_version`.** Everything device-authoritative applies only to `fiscal_schema_version === 3` terminals (the precedent already in `terminalStore.ts:846` and `ZReportSyncController:72`). v<3 terminals keep the REST path untouched — zero risk to legacy.
2. **Server projector first, additively.** Ship `ShiftLifecycleProjection` registered in the tagged set **before** flipping the device. It only acts on synced `SESSION_OPEN`/`SESSION_CLOSE` events, which v3 terminals already emit — so the moment it ships, `pos_shifts` rows start being created/closed from fiscal events for shifts opened the old way too (the `SESSION_OPEN` already carries `shift_id`). Backfill: for already-open shifts that predate the projector, leave the existing REST-created `pos_shifts` row as-is (the projection's `ON CONFLICT (id) DO NOTHING` is a no-op if a row with that UUID exists; if the REST id differs from the device `shift_id`, the open shift is grandfathered and closes via its existing path).
3. **In-flight shift safety.** A shift open at flip time (device-side) keeps its cached `Shift` object; `fetchCurrentShift` SQLite-first will find it in `local_shifts` once the device writes it (a one-time migration on first v3 boot copies the cached localStorage `SHIFT` into `local_shifts` if absent). Closing an in-flight pre-flip shift still works through whichever path created it.
4. **`shift_number` seed migration.** On first v3 boot post-rollout, seed `local_shifts` counter from `GET /pos/shifts/current` (or last cached shift number) so numbering continues. Offline → seed from cached number; reconcile on next online tick.
5. **Demo stack.** The Tunisia parapharmacy demo (`feat/parapharmacy-tunisia-demo`, port 8088) runs v3 terminals; validate the full open→sell→close→reopen cycle offline there before any merge.
6. **No fiscal event reshape.** SESSION_OPEN/CLOSE byte layout changes ONLY by **adding** `shift_number` to `SESSION_OPEN` (additive field). Confirm whether this requires a payload version bump or is a non-breaking append (see OPEN DECISION).

---

## 7. Phased implementation plan (TDD; each phase independently shippable)

### Phase 0 — `local_shifts` SQLite table + `shift_number` counter (device, no server)
- New SQLite migration: `local_shifts` (id UUIDv7 PK, terminal_id, session_id, shift_number, status, opening_cash, opened_at, closed_at, cashier_id, cashier_name, fiscal_shift_id alias). Partial unique index on `(terminal_id) WHERE status='OPEN'` mirroring the server invariant.
- `nextShiftNumber(terminalId)` = `MAX(shift_number)+1`, computed inside the write tx.
- One-time copy of cached `StorageKeys.SHIFT` into `local_shifts` on first boot.
- **Tests:** counter monotonicity per terminal; one-open invariant rejects a second open; migration idempotent.
- **Reuses:** migration harness, `writeGate`.

### Phase 1 — device-authoritative shift OPEN
- Rewrite `openShift()`: mint UUIDv7 + `nextShiftNumber`; `withWriteTransaction('fiscal')` authoring `SESSION_OPEN` (+ new `shift_number` payload field) + `OPENING_FLOAT` + `local_shifts` insert atomically; post-commit pending-count + sync. Drop server-first POST, `offline-<uuid>` fork, and the `SHIFT_ALREADY_OPEN` second-call branch (B4 fixed).
- `fetchCurrentShift()` → SQLite-first read of `local_shifts`.
- **Tests:** open offline produces a UUID id + non-zero number + 2 fiscal events + 1 `local_shifts` row, all-or-nothing on a mid-tx throw; `fetchCurrentShift` returns the local open shift with no network; B4 scenario no longer throws.
- **Reuses:** `authorZSessionOpenWithOpeningFloat`, `engine.append`, `writeGate`, fiscal sync rail.

### Phase 2 — server `ShiftLifecycleProjection` (SESSION_OPEN)
- New projector creating `pos_shifts` OPEN row from `SESSION_OPEN` (PK = device `shift_id`; `shift_number` from payload; `cashier_id`/`opening_cash`/`opened_at` mapped). Idempotent `ON CONFLICT (id) DO NOTHING`. Tag in `POSServiceProvider::register()`.
- `pos_shifts (terminal_id, shift_number)` unique constraint migration.
- 409 `SHIFT_DEVICE_AUTHORITY_REQUIRED` guard on `POST /pos/shifts/open` for v3.
- **Tests:** SESSION_OPEN ingest → OPEN `pos_shifts` row with device UUID PK; re-delivery idempotent; two opens collide on the partial unique index → second dead-letters; v3 POST /open returns 409. (Scoped PHPUnit `--filter`, never the full suite.)
- **Reuses:** `OutboxIngestor`, `ApplyFiscalEventProjectionJob`, projection registry, v3-guard pattern.

### Phase 3 — device-authoritative shift CLOSE + projection
- `closeShift()` authors `SESSION_CLOSE` + `Z_REPORT` (already exist) + updates `local_shifts` to CLOSED; drop the gate POST.
- Extend `ShiftLifecycleProjection` for `SESSION_CLOSE` → close `pos_shifts` (actual_cash, variance, severity, manager_override_by, closed_by from payload). 409 on `POST /pos/shifts/{id}/close` + `sync-close` for v3.
- **Tests:** close offline updates `local_shifts`; SESSION_CLOSE ingest closes `pos_shifts` satisfying `pos_shifts_closed_logic`; `CashRegisterReportService` sees the closed row.
- **Reuses:** `appendZSessionCloseAndZReport`, `ZReportProjection`.

### Phase 4 — consumer one-id sweep + Today-Sales/cash-drawer fixes
- Make every shift-scoped lookup key on the single device UUID (zReportService, endOfDayPreview, fetchShiftReceipts, fetchCashDrawerOps, cashDrawerApi, Header, TodaySalesPanel). Collapse `fiscal_shift_id` into `id`.
- **Tests:** Today Sales + cash-drawer ops resolve online with the device UUID; offline SQLite fallbacks key on the same id.

### Phase 5 — offline EOD close completeness (B5/B6/B7)
- Durable SQLite cache for `companyConfig` (B5).
- Call `refreshFraudSettingsCache` on bootstrap + cache authorized managers (B6).
- Manager-PIN offline path (B7) — **mirror manager-PIN bcrypt hashes to SQLite** (`manager_pins` table, same pattern as `operator_pins`); above-hard-variance close verifies the PIN locally. Seed on first login, refresh on sync (Decision 1).
- **Tests:** EOD close offline computes variance thresholds from cached fraud settings; above-hard-variance manager authorization verifies against the mirrored bcrypt hash with no network.

### Phase 6 — reconciliation + rollout hardening
- Background reconcile pass (§4.c) with advisory banner on remote-close conflict; audited.
- `shift_number` seed migration; in-flight grandfather handling.
- Live offline open→sell→close→reopen cycle on the Tunisia demo stack.

---

## 8. Invariants preserved

Inherited from the receipt model (single-writer spec §I-1…I-12) — all apply unchanged to shifts: single-writer SQLite via `writeGate`; fiscal lane preempts sync; monotonic per-terminal chain; idempotency at device/wire/server/projection layers; fail-closed ROLLBACK on any pre-commit error; append-only `fiscal_events`; server never blocks the device; chain-break halts further sync; projection is a derived, idempotently re-computable concern separate from chain authority; genesis seed provisioned at registration; device time UTC-second-precision validated at both boundaries; projection jobs dispatched only `DB::afterCommit()`.

New invariants:
- **One device UUID per shift** — `shift_id` == `fiscal_shift_id` == `pos_shifts.id` == `local_shifts.id`.
- **`shift_number` is device-authored, per-terminal-monotone, immutable once assigned** — server stores verbatim, never renumbers.
- **One open shift per terminal** — enforced at device (`local_shifts` partial unique) and server (`pos_shifts_one_open_per_terminal`).

---

## 9. Risks & open questions

- **Fiscal/legal (NF525):** device-authoritative shift open/close is compliant **provided** offline signing with a local cert + an unbroken per-register chain (research §4). Our chain is per-terminal SHA-256 and already device-signed; SESSION_OPEN/CLOSE are already chained audit events. Risk is implementation (chain survives crash), not legality. **Tunisia NACEF (eff. 1 Jul 2026):** the MDF decree's offline session rules are not in public sources — does NOT block this design but must be validated with a Tunisian fiscal specialist before the Tunisia launch (already a tracked workstream).
- **Clock skew:** UUIDv7 + `shift_number` timestamps rely on device clock; the engine already validates `event_time_device` UTC-second precision and rejects >86400s drift server-side. NTP background sync recommended.
- **Two-device-per-terminal mis-provisioning:** the partial unique index + `(terminal_id, shift_number)` unique make this fail loud (dead-letter) rather than silently double-open. Acceptable.
- **Pre-flip in-flight shifts:** grandfathered; close via their original path; new opens are device-authoritative.
- **Web admin expectation shift:** web admin can no longer force-close a v3 shift (409). Reconcile banner on the device handles the remote-close intent; confirm this is the desired product behavior.

### RESOLVED DECISIONS (owner, 2026-06-13)

1. **Manager-PIN at EOD close, offline (B7) → MIRROR BCRYPT HASHES TO DEVICE.** Manager PIN bcrypt hashes are cached to SQLite (new `manager_pins` mirror table, same pattern as `operator_pins`) so an above-hard-variance close is fully authorizable offline; verification is local. Bootstrapped on first login and refreshed on sync. Implemented in Phase 5.

2. **`shift_number` carrier → ADDITIVE FIRST-CLASS FIELD IN `SESSION_OPEN`, NO `SessionOpenV2`.** The system is **pre-live with no production-sourced fiscal events**, so the events-are-immutable rule (which protects events already emitted in production) does not force a versioned replacement. `shift_number` is wired as a first-class field of the canonical `SESSION_OPEN` payload, done properly now, gated behind `fiscal_schema_version === 3`. Any `SESSION_OPEN` events on dev/demo stacks are disposable and re-seeded. This is the robust approach per owner direction.

3. **REST `/pos/shifts/open` + `/close` for v3 → HARD 409 `SHIFT_DEVICE_AUTHORITY_REQUIRED`.** Matches the existing `ZReportSyncController:72` precedent; loud over silent.

4. **Web-admin force-close of a v3 shift → DISALLOW BY DEFAULT; admin-recovery exception DEFERRED.** Default is offline-first: web admin returns `409` + "shifts close on the terminal", and the device shows a reconcile banner for any remote intent. The near-universal industry exception (Square/Lightspeed/Shopify/Odoo) is an admin force-close for **abandoned/orphaned** sessions only (lost/broken terminal, cashier left without closing) — implemented as a **server-authored `SESSION_CLOSE`-on-behalf** fiscal event flagged `closed_by_admin` (no cash count, variance unknown). That exception is **specced as a follow-up**, not in the launch path (see §10).

5. **B5/B6 (companyConfig + fraud-settings/authorized-managers offline cache) → INCLUDED THIS CYCLE (Phase 5).** Offline shift CLOSE depends on them.

---

## 10. Out of scope / non-goals

- **Fully-offline first-ever activation** (claim/request a terminal with no prior connection) — the north star explicitly permits one online bootstrap; the existing TODO at `terminalStore.ts:326-333` tracks this separately. Terminal-setup network calls (B1/B2/B3) stay class-A one-time.
- **Routing web-admin cash deposit/payout through fiscal events** — flagged as a follow-up; for now they operate against the projected v3 `pos_shifts` row.
- **Web-admin admin-recovery force-close of an abandoned/orphaned v3 shift** (server-authored `SESSION_CLOSE`-on-behalf, flagged `closed_by_admin`, no cash count) — **deferred follow-up** (Decision 4). Default launch behavior is 409 / close-on-terminal.
- **Off-loading fiscal projections off the device click path** — unnecessary (contention solved by the single-writer arch).
- **Changing the receipt path** — it is the template, not the target.
- **Multi-terminal / multi-device-per-register topologies** — single-device-per-terminal is the deployment model; collisions fail loud.
- **Tunisia NACEF MDF integration** — separate major workstream; this spec only ensures it is not foreclosed.

---

## 11. Decision summary (for Codex review)

The work is **mostly rewire on the device + one new server projector**, not a ground-up build: the fiscal authoring rails (SESSION_OPEN/OPENING_FLOAT/SESSION_CLOSE/Z_REPORT), the sync rail, the ingest/verify pipeline, and the device-minted UUIDs all already exist. The single genuinely-new server piece is `ShiftLifecycleProjection` writing `pos_shifts` from those events — and every field it needs is already in the synced payloads except `shift_number` (one additive field). The device changes are: mint the id+number locally, write a `local_shifts` row in the existing fiscal transaction, read current-shift from SQLite, and stop treating the REST endpoints as the authority (demote them to v3-gated reconcile/read, keep them for legacy + web reads).
