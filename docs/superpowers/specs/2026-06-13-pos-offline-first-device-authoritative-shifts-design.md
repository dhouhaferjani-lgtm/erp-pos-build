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

**Headline finding: ZERO of `pos_shifts` is projected from fiscal events today, and every input a projector needs already exists in the synced payloads. The cleanest delivery is to EXTEND the already-running `ZSessionLifecycleProjection` (registered as `pos_core_z_session_lifecycle`, which already handles `SESSION_OPEN`/`SESSION_CLOSE` and projects them `applied` on the live demo server today) so it ALSO upserts `pos_shifts` — not to add a separate projector. Extending the same projector means the `pos_shifts` row is created by the very projector that already fires first on `SESSION_OPEN`, which dissolves any cross-projector ordering question (see Review Reconciliation §12, F-5/F-11).**

> **Empirical confirmation (2026-06-13):** on the live Tunisia demo tenant, device-authored `SESSION_OPEN`/`OPENING_FLOAT` events are `integrity_status = verified` and were projected `applied` by `pos_core_z_session_lifecycle`. In v3 **all** fiscal events are device-authored (server fiscal authoring is retired via `ServerFiscalAuthoringRetiredException`), so device-origin is the normal accepted case — there is no "server-must-author" gate to defeat.

Precisely:
- `ZSessionLifecycleProjection::apply()` handles `SESSION_OPEN`, `OPENING_FLOAT`, `CASH_IN/OUT`, `SAFE_DROP`, `CASH_CORRECTION`, `SESSION_CLOSE`, `X_REPORT` — and writes **only** to `pos_z_session_events` (one audit/mirror row per fiscal event, keyed on `fiscal_event_id`). It contains **zero** references to `Shift` / `pos_shifts`. `grep "pos_shifts|Shift::" app/Modules/POS/Application/Projections/` is empty.
- `ZReportProjection::apply()` handles `Z_REPORT` → writes **only** `pos_z_reports`. No `pos_shifts` write.
- **No fiscal-event ingest path writes `pos_shifts` at all.** Every `pos_shifts` mutation today is REST-driven (`ShiftController::open/close`, `SyncController::syncCloseShift`, `ZReportSyncController::sync`, `ReportController::generateZReport`).
- Both projectors are registered via `$this->app->tag([...], FiscalEventProjector::class)` in `POSServiceProvider::register()` (`apps/api/app/Modules/POS/Providers/POSServiceProvider.php:50-58`). `ZSessionLifecycleProjection` is already in that tag set and already handles the shift lifecycle events — so the `pos_shifts` upsert is **added inside that existing projector**, requiring no new registration and no priority tuning.

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
2. **Author the canonical fiscal event(s) via the single-writer transaction — by EXTENDING the existing `authorZSessionOpenWithOpeningFloatOnDb`, not by nesting wrappers.** That function (`zSessionAuthoring.ts:490`) already opens ONE `withWriteTransaction('fiscal', async (tx) => …)` and appends `SESSION_OPEN` + `OPENING_FLOAT` via `engine.append(tx, …)` — the exact proven pattern `receiptService.ts:413` uses. We add the `local_shifts` insert (step 3) and the receipt-anchor insert (step 3b) **inside that same `tx` block, via the `tx` handle**. **HARD CONSTRAINT (single-writer invariant, see [[project_pos_single_writer_sqlite]]):** never call a self-transacting wrapper (`authorZSessionOpenWithOpeningFloat`, `enqueueWrite`, pool-`execute`) from inside a gate job — it re-acquires the writer mutex and deadlocks. All writes in the open sequence use the one `tx` handle. (Codex F-2 flagged the deadlock footgun; it is real for nesting, but multi-write-in-one-gate is proven by the receipt path — see §12.)
3. **Write the local projection row in the SAME transaction, via `tx`.** `insertLocalShift(tx, { id: shiftId, terminal_id, session_id: sessionId, shift_number, status:'OPEN', opening_cash, opened_at, cashier_id, cashier_name })` into the **new local SQLite `local_shifts` table** (device-side mirror of `pos_shifts`), atomically with the two fiscal-event rows and the chain-head advance. Any failure → ROLLBACK → no orphaned shift, no orphaned event, no stale chain head.
3b. **Write the receipt anchor in the SAME transaction, via `tx`.** Today `insertShiftReceiptAnchor` runs as a **separate** `getDatabase()` write *before* authoring (`terminalStore.ts:228-238`) — outside the fiscal gate. Move it inside this `tx` so the anchor, the events, and the `local_shifts` row commit (or roll back) atomically, closing the window where a receipt could be rung against a half-open shift. The anchor keys on `shift_id` (string) — no integer FK to `local_shifts`, so there is no FK-ordering coupling (Codex F-3).
4. **Post-commit: bump pending count + schedule debounced sync** — `useSyncStore.getState().incrementPendingCount()` + `scheduleDebouncedSync()`, after the tx resolves (never inside).
5. **Sync** rides the existing `pushOfflineReceipts` rail (renamed conceptually to "push pending fiscal events" — it already pushes all event types in chain order).
6. **Stranded-`syncing` recovery** already runs at the top of every push tick (`recoverStrandedSyncingFiscalEvents`) — unchanged.
7. **Server ingests** via `OutboxIngestor` — unchanged. The `verifyZSessionLifecycle` check already enforces SESSION_OPEN-before-others and no-duplicate-SESSION_OPEN per session.
8. **Server dispatches projection jobs** via `DB::afterCommit()` — unchanged plumbing; the already-tagged `ZSessionLifecycleProjection` already receives `SESSION_OPEN`/`SESSION_CLOSE`, so no new registration is needed.
9. **The existing `ZSessionLifecycleProjection` is EXTENDED to upsert `pos_shifts`** (not a new projector), idempotent on `pos_shifts.id = shift_id` (device UUID becomes the PK, exactly as `pos_receipts` uses the device receipt UUID + `fiscal_event_id` uniqueness). `SESSION_OPEN` → `INSERT … ON CONFLICT (id) DO NOTHING` an OPEN row; `SESSION_CLOSE` → update to CLOSED with cash-count fields, idempotent (re-applying a `SESSION_CLOSE` for an already-CLOSED shift is a no-op — Codex F-12). The `pos_shifts_one_open_per_terminal` partial unique index already enforces one-open-per-terminal; the open upsert must treat a partial-index conflict as idempotent success, not an error (Codex F-15 — index already exists). Because the same projector creates the shift row on `SESSION_OPEN` before any later `Z_REPORT`/`SESSION_CLOSE`, the `pos_z_reports.shift_id` FK is always satisfied (Codex F-5 — `pos_receipts` has no shift FK at all, so receipts are never blocked).
10. **Never block the business operation on network.** `withWriteTransaction` is pure SQLite; the UI returns immediately after commit. Sync is background.

### 3.2 Close lifecycle

`SESSION_CLOSE` + `Z_REPORT` are already authored on-device (`appendZSessionCloseAndZReport`). The change is purely to **stop calling `POST /pos/shifts/{id}/close` as the gate** and let the extended `ZSessionLifecycleProjection` close the `pos_shifts` row from the synced `SESSION_CLOSE` event. `Z_REPORT` continues to project to `pos_z_reports` via the existing `ZReportProjection`.

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

**`apps/web` UI change is REQUIRED, not optional (Codex F-4).** `apps/web/src/features/pos/api/shiftApi.ts` exports `openShift` (`:87` → `POST /pos/shifts/open`) and `closeShift` (`:94` → `POST /pos/shifts/{id}/close`), and the web POS shift panel renders Open/Close controls that call them. If those endpoints start returning 409 for v3 terminals with no UI change, the operator clicks "Open/Close shift" and gets an unhandled 409. **Required:** the web POS shift panel must detect a v3 terminal (the terminal record already carries `fiscal_schema_version`) and **hide/disable the Open & Close controls**, showing "This terminal's shifts are opened and closed on the device." Read paths (`current`, `receipts`, `balance`, cash-drawer ops) are unchanged. The genuine remote-close need (lost/broken terminal) is the **deferred admin-recovery exception** (Resolved Decision 4 / §10), not this panel. This is a Phase-4 deliverable.

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
| **EXTEND `ZSessionLifecycleProjection`** to upsert `pos_shifts` (`SESSION_OPEN`→create OPEN row, `SESSION_CLOSE`→close row) — already tagged in `POSServiceProvider::register()` (`:50-58`) and already handling these events | **S — the core change (no new projector/registration)** |
| `ShiftController::open` / `ShiftManagementService::openShift` | **S** — 409 `SHIFT_DEVICE_AUTHORITY_REQUIRED` for v3; legacy unchanged |
| `ShiftController::close` / `closeShift` / `SyncController::syncCloseShift` | **S** — 409 for v3; close happens via projection |
| `ShiftController::current/index/receipts` | **S (minor)** — work as-is once `pos_shifts.id` == device UUID; `receipts` already filters by cashier+time |
| `ShiftResource` | **S** — echo `fiscal_shift_id`/`session_id` (now == id) for round-trip verification |
| `ZSessionLifecycleProjection` | **OK** — keeps writing `pos_z_session_events`; unchanged |
| `ZReportProjection` `:66` (`where shift_id`) | **OK** — keys on device UUID already; works once `pos_shifts.id` == that UUID |
| `CashRegisterReportService` (queries `pos_shifts` for closed rows) | **OK once** the extended `ZSessionLifecycleProjection` closes the row with `actual_cash`/`variance` from `SESSION_CLOSE` |
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
2. **Server projector first, additively.** Ship the `pos_shifts` upsert inside `ZSessionLifecycleProjection` **before** flipping the device. It only acts on synced `SESSION_OPEN`/`SESSION_CLOSE` events, which v3 terminals already emit — so the moment it ships, `pos_shifts` rows start being created/closed from fiscal events for shifts opened the old way too (the `SESSION_OPEN` already carries `shift_id`). Backfill: for already-open shifts that predate the change, leave the existing REST-created `pos_shifts` row as-is (the upsert's `ON CONFLICT (id) DO NOTHING` is a no-op if a row with that UUID exists; if the REST id differs from the device `shift_id`, the open shift is grandfathered and closes via its existing path).
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

### Phase 2 — EXTEND `ZSessionLifecycleProjection` for `pos_shifts` (SESSION_OPEN)
- Add `pos_shifts` upsert to the **existing** `ZSessionLifecycleProjection` (already handles `SESSION_OPEN`, already tagged in `POSServiceProvider::register()` — no new projector, no registration, no priority tuning). `SESSION_OPEN` → `INSERT pos_shifts … ON CONFLICT (id) DO NOTHING`, PK = device `shift_id`; `shift_number` from payload; `cashier_id`/`opening_cash`/`opened_at` mapped. Treat a `pos_shifts_one_open_per_terminal` partial-index conflict as **idempotent success** (catch + no-op), NOT an error — so dead-letter replay of a `SESSION_OPEN` for an already-open shift never crashes the projector (Codex F-15).
- `ShiftResource::toArray()` exposes `shift_number`, `session_id`, `opened_at_device` so the device reconcile read can round-trip (Codex F-6/F-16).
- Migration: **upgrade** the existing `(terminal_id, shift_number)` plain index to UNIQUE (Codex F-7 — the plain index already exists at `:62`; the column already exists at `:33`, so **no column add and no data backfill** — existing rows already carry server-assigned numbers). A rogue second device's colliding number then fails loud.
- 409 `SHIFT_DEVICE_AUTHORITY_REQUIRED` guard on `POST /pos/shifts/open` for v3.
- **Tests:** SESSION_OPEN ingest → OPEN `pos_shifts` row with device UUID PK; re-delivery idempotent (no error); partial-index conflict on replay → idempotent no-op (NOT dead-letter); v3 POST /open returns 409. (Scoped PHPUnit `--filter`, never the full suite.)
- **Reuses:** `OutboxIngestor`, `ApplyFiscalEventProjectionJob`, the existing projector + registration, v3-guard pattern.

### Phase 3 — device-authoritative shift CLOSE + projection
- `closeShift()` authors `SESSION_CLOSE` + `Z_REPORT` (already exist) + updates `local_shifts` to CLOSED; drop the gate POST.
- Extend `ZSessionLifecycleProjection` for `SESSION_CLOSE` → close `pos_shifts` (actual_cash, variance, severity, manager_override_by, closed_by from payload). **Idempotent close** — re-applying `SESSION_CLOSE` to an already-CLOSED row is a no-op, so a reconnect that re-delivers the close never double-closes or corrupts the chain (Codex F-12; the v3 409 already prevents a competing server-side close). 409 on `POST /pos/shifts/{id}/close` + `sync-close` for v3.
- **Tests:** close offline updates `local_shifts`; SESSION_CLOSE ingest closes `pos_shifts` satisfying `pos_shifts_closed_logic`; re-delivered SESSION_CLOSE is a no-op; `CashRegisterReportService` sees the closed row.
- **Reuses:** `appendZSessionCloseAndZReport`, `ZReportProjection`.

### Phase 4 — consumer one-id sweep + Today-Sales/cash-drawer + web-admin UI
- Make every shift-scoped lookup key on the single device UUID (zReportService, endOfDayPreview, fetchShiftReceipts, fetchCashDrawerOps, cashDrawerApi, Header, TodaySalesPanel). Collapse `fiscal_shift_id` into `id`. Server-side, receipts resolve to a shift by `terminal+cashier+posted_at` window (no shift FK on `pos_receipts`), so this sweep is primarily client-side (Codex F-5/F-14).
- **`apps/web` POS shift panel:** detect v3 terminals (via the terminal's `fiscal_schema_version`) and **hide/disable the Open & Close controls** with a "shifts open/close on the device" notice, so the v3 409 is never hit interactively (Codex F-4). Read paths unchanged.
- **Tests:** Today Sales + cash-drawer ops resolve online with the device UUID; offline SQLite fallbacks key on the same id; web panel hides open/close for a v3 terminal fixture.

### Phase 5 — offline EOD close completeness (B5/B6/B7)
- Durable SQLite cache for `companyConfig` (B5).
- Call `refreshFraudSettingsCache` on bootstrap + cache authorized managers (B6).
- **Eager (not lazy) PIN sync at first login (Codex F-8):** operator-PIN sync to `operator_pins` runs immediately after a successful login, before the device can go offline — not on first lazy use — so a device that goes offline right after login can still authorize an EOD close.
- Manager-PIN offline path (B7) — **mirror manager-PIN bcrypt hashes to SQLite** via a new `GET /pos/manager-pin-data` (mirrors `/pos/auth/pin-data`) into `manager_pins`; above-hard-variance close verifies the PIN locally. Seed on first login, refresh on sync (Decision 1). **Security hardening (Codex F-9):** (a) offline lockout — after N failed local bcrypt attempts, mark the entry locked and require online re-auth (reuse the existing manager-PIN throttle columns on `terminal_state`); (b) `synced_at` + `revoked_at` columns so a PIN change/rotation propagates and stale hashes are invalidated on next sync; (c) `manager_pins` is a **distinct table** from `operator_pins` (managers ≠ operators), sourced from `AuthorizedManagersController`.
- **Tests:** EOD close offline computes variance thresholds from cached fraud settings; above-hard-variance manager authorization verifies against the mirrored bcrypt hash with no network; N failed attempts lock the entry; a revoked PIN is rejected after sync.

### Phase 6 — reconciliation + rollout hardening
- Background reconcile pass (§4.c) with advisory banner on remote-close conflict; audited and idempotent (no double-close — Codex F-12).
- Device `shift_number` **counter seed** from the server's `MAX(shift_number)` for the terminal at first boot (the `pos_shifts.shift_number` *column* is already populated server-side, so there is no server-side data backfill — Codex F-13 is moot; only the device counter needs seeding). In-flight grandfather handling.
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

- **Fiscal/legal (NF525) — claim softened (Codex F-10):** this design does **not** change the fiscal authorship posture. Device-authored fiscal events are **already** the v3 production model (`SESSION_OPEN`/`SALE_RECEIPT`/`SESSION_CLOSE` are device-authored and chained today; server fiscal authoring is retired). Making `pos_shifts` a projection of those already-device-authored events introduces no new fiscal-authorship boundary — it only stops a *non-fiscal* REST row (`pos_shifts`) from being the source of truth. The per-terminal SHA-256 chain (unbroken, device-signed) is the compliance-bearing artifact and is unchanged. We therefore do **not** assert "NF525 neutral" as a closed fact — it is *consistent with the existing certified posture*, but any formal certification language is owned by the compliance workstream, not this spec. **Tunisia NACEF (eff. 1 Jul 2026):** the MDF signing requirement is a separate major workstream ([[project_tunisia_nacef_fiscal]]); this design does not foreclose it but does not satisfy it either. No device-authored shift event is claimed MDF-signed. Validate with a Tunisian fiscal specialist before the Tunisia launch.
- **Clock skew:** UUIDv7 + `shift_number` timestamps rely on device clock; the engine already validates `event_time_device` UTC-second precision and rejects >86400s drift server-side. NTP background sync recommended.
- **Two-device-per-terminal mis-provisioning:** the partial unique index + `(terminal_id, shift_number)` unique make this fail loud (dead-letter) rather than silently double-open. Acceptable.
- **Pre-flip in-flight shifts:** grandfathered; close via their original path; new opens are device-authoritative.
- **Web admin expectation shift (resolved):** web admin can no longer open/close a v3 shift (409); the web POS panel hides those controls for v3 terminals and the device reconcile banner surfaces any remote-close intent (Decision 4 / §4.b / Phase 4). The genuine lost-device recovery case is the deferred admin-recovery `SESSION_CLOSE`-on-behalf exception (§10).

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

## 11. Decision summary

The work is **device rewire + extending one existing server projector**, not a ground-up build: the fiscal authoring rails (SESSION_OPEN/OPENING_FLOAT/SESSION_CLOSE/Z_REPORT), the sync rail, the ingest/verify pipeline, the device-minted UUIDs, the `pos_shifts.shift_number` column, the `pos_shifts_one_open_per_terminal` index, and a registered, already-firing `ZSessionLifecycleProjection` all already exist. The server change is to add a `pos_shifts` upsert **inside that existing projector** — every field it needs is already in the synced payloads except `shift_number` (added to the `SESSION_OPEN` payload, no `SessionOpenV2`, pre-live). The device changes: mint the id+number locally, write `local_shifts` + the receipt anchor inside the existing `authorZSessionOpenWithOpeningFloatOnDb` fiscal transaction (via the `tx` handle — never nest a self-transacting wrapper), read current-shift from SQLite, and stop treating the REST endpoints as the authority (demote to v3-gated reconcile/read; `apps/web` hides open/close for v3).

---

## 12. Review reconciliation — Codex adversarial review r1 (2026-06-13)

Full review: `docs/superpowers/reviews/2026-06-13-pos-offline-first-shifts-spec-codex-review.md` (verdict NEEDS-REWORK, 4 BLOCKER / 6 HIGH). Each finding was verified against the actual code/runtime before acting (per `superpowers:receiving-code-review` — external feedback is evaluated, not obeyed). Outcome: **no surviving blocker**; the design is sound. Several findings were verifiably false (Codex partly reviewed against section numbers and a `DeviceShiftProjector` name that this spec does not use).

| # | Sev (Codex) | Verdict after verification | Evidence / action |
|---|---|---|---|
| F-1 | BLOCKER | **FALSE** | Live demo: device-authored `SESSION_OPEN` is `integrity_status=verified` and projected `applied` by `pos_core_z_session_lifecycle`. In v3 all events are device-authored; no "server-must-author" gate exists. No change. |
| F-2 | BLOCKER | **OVERSTATED → addressed** | Multi-write-in-one-gate is proven (`receiptService.ts:413`, `authorZSessionOpenWithOpeningFloatOnDb:503`). Real kernel: don't nest self-transacting wrappers. §3.1 step 2 now names the `…OnDb` tx + the hard no-nesting constraint. |
| F-3 | BLOCKER | **VALID → addressed** | Anchor write is currently a separate pre-write (`terminalStore.ts:228-238`). §3.1 step 3b moves it into the same open `tx`. No FK coupling (anchor keys on string `shift_id`). |
| F-4 | BLOCKER | **PARTIALLY VALID → addressed** | Spec already kept the endpoints for web; gap was the web UI. §4.b + Phase 4 now require `apps/web` to hide open/close for v3 terminals. |
| F-5 | HIGH | **FALSE** | `pos_receipts` has **no** shift FK; receipts resolve to a shift by `terminal+cashier+posted_at`. No projection-ordering race. Extending the one projector means the shift row precedes any Z anyway. |
| F-6 | HIGH | **FALSE** | `pos_shifts.shift_number` column already exists (`:33`). Only the `SESSION_OPEN` *payload* lacks it. `ShiftResource` field exposure added (Phase 2). |
| F-7 | HIGH | **PARTIALLY TRUE → addressed** | `(terminal_id, shift_number)` plain index exists (`:62`); Phase 2 upgrades it to UNIQUE. No column add / no backfill. |
| F-8 | HIGH | **VALID → addressed** | Phase 5 makes operator-PIN sync eager at first login (not lazy); device shift_id 404 is fixed structurally by device-authoritative projection. |
| F-9 | HIGH | **VALID → addressed** | Phase 5 adds offline lockout + `synced_at`/`revoked_at` rotation + clarifies `manager_pins` is a distinct table. |
| F-10 | HIGH | **PARTIALLY VALID → addressed** | §9 NF525 claim softened: design does not change the already-device-authored v3 fiscal posture; NACEF/MDF explicitly not satisfied, deferred. |
| F-11 | MEDIUM | **FALSE** | `ZSessionLifecycleProjection` writes `pos_z_session_events`, not `pos_shifts` (grep empty). Headline holds; we extend that projector rather than add one. |
| F-12 | MEDIUM | **VALID → addressed** | Phase 3 makes the `SESSION_CLOSE` projection idempotent (no double-close); v3 409 already blocks a competing server close. |
| F-13 | MEDIUM | **MOSTLY MOOT → noted** | `shift_number` column already populated server-side → no data backfill. Only the device counter needs seeding (Phase 6). |
| F-14 | MEDIUM | **PARTIALLY VALID → addressed** | Server resolves shift by `terminal+cashier+window`, not by receipt `session_id`; sweep is mostly client-side (Phase 4). |
| F-15 | MEDIUM | **FALSE** | `pos_shifts_one_open_per_terminal` partial unique index already exists (`:69`). Phase 2 treats its conflict as idempotent success. |
| F-16 | LOW | **VALID → addressed** | `ShiftResource` exposes `shift_number`/`session_id`/`opened_at_device` (Phase 2). |
| F-17 | LOW | **FALSE** | §2 is the current-state map (not §3); "no projection writes `pos_shifts`" is true. No change. |
