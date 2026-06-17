# Adversarial Code Review — POS Offline-First Shifts Phase 0+1

**Branch:** feat/offline-first-shifts
**Commits reviewed:** 424ce1cc2 (Phase 0), ff79d01b2 (Phase 1 OPEN), 2a4d78cb1 (M1)
**Diff base:** 837eecc9f
**Date:** 2026-06-14
**Reviewer:** Codex adversarial review pass
**Spec:** docs/superpowers/specs/2026-06-13-pos-offline-first-device-authoritative-shifts-design.md
**Handover:** docs/superpowers/handovers/2026-06-14-pos-offline-first-shifts-handover.md

---

## Overall Verdict: REQUEST-CHANGES

Two HIGH findings must be resolved before this can safely land on any terminal with an in-flight pre-cutover shift or before Phase 2 (server projector) is in the same deploy.

---

## Findings

### HIGH-1 — Pre-cutover backfill can install an `offline-<uuid>` string as the authoritative `fiscal_shift_id`

**Files/lines:**
- `apps/pos/src/stores/terminalStore.ts:213` — `backfillLocalShiftFromCache()` inserts `cached.id` as `local_shifts.id`
- `apps/pos/src/stores/terminalStore.ts:263` — maps `row.id` back to `fiscal_shift_id` in the returned `Shift`
- `apps/pos/src/lib/db/repositories/localShiftRepository.ts:73` — `cachedShiftToBackfillInput()` sets `id = cached.id`
- `apps/pos/src/lib/db/repositories/localShiftRepository.ts:153` — backfill insert uses that `id`

**Problem:** `backfillLocalShiftFromCache()` is called once on first v3 `fetchCurrentShift`. It reads the legacy `StorageKeys.SHIFT` cache and inserts the row with `id = cached.id`. For a grandfathered offline shift the `cached.id` is `offline-<uuid>` (not a valid UUIDv7). The mapping at line 263 then promotes `row.id` to `fiscal_shift_id`, so all subsequent receipt payload authoring, the anchor insert, and eventual `SESSION_CLOSE` use this non-UUID string. The server's `StrictCanonicalParser` validates `fiscal_shift_id` as a UUID and will quarantine the `SESSION_CLOSE` and every receipt authored under that shift.

A terminal mid-shift when the app updates to Phase 1 will be: (1) unable to close the current shift cleanly (`SESSION_CLOSE` quarantined), (2) unable to open a new shift (one-open partial unique: the backfilled row is still `OPEN` in `local_shifts`), (3) wedged until manual SQLite surgery.

**Fix required:** Backfill must check `isUuid(cached.id)`. If false (grandfathered offline shift), either reject the backfill and force the terminal to a clean-close REST call, OR promote `cached.fiscal_shift_id` (the real server UUID for online-opened shifts) as `local_shifts.id`. A guard test covering this edge case is also needed.

---

### HIGH-2 — Phase 1 is not independently shippable before Phase 2 (server projector)

**Files/lines:**
- `apps/pos/src/stores/terminalStore.ts:821` — v3 `openShift()` no longer POSTs to `/pos/shifts/open`
- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:21` — projects only `SESSION_CLOSE`/`Z_REPORT`; does NOT project `SESSION_OPEN` (Phase 2 work)
- `apps/api/app/Modules/POS/Presentation/Controllers/ShiftController.php:140` — web admin reads `pos_shifts` to return current shift
- `apps/web/src/features/pos/api/shiftApi.ts:75` — `fetchCurrentShift()` calls `GET /pos/shifts/current`

**Problem:** Phase 1 removes the v3 REST POST without any server-side fallback. From the moment a v3 terminal opens a shift under Phase 1: `pos_shifts` has no row, web admin shows no current shift, `GET /pos/shifts/current` returns 404, and any web-admin close/balance/report sees a null shift.

**Fix required:** Either (a) gate Phase 1 behind a feature flag so it only activates once Phase 2 is also deployed, OR (b) keep the v3 REST POST as a fire-and-forget side-channel until Phase 2, OR (c) document clearly that Phases 1+2 MUST be deployed together and gate CI/CD accordingly.

---

### MED-1 — Direct `closeShift()` store action can clear state without closing the `local_shifts` row

**Files/lines:**
- `apps/pos/src/components/Header.tsx:250` — Header calls `generateZReport()` for normal EOD (correct path)
- `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:704` — `appendZSessionCloseAndZReport()` updates `local_shifts.closed_at`
- `apps/pos/src/stores/terminalStore.ts:878` — public `closeShift()` action still calls legacy REST only and sets `shift: null`
- `apps/pos/src/components/pos/CloseShiftModal.tsx:33` — some paths call `closeShift()` directly

**Problem:** The normal EOD path correctly closes the `local_shifts` row via `generateZReport()`. But the store's public `closeShift()` action was not updated: it POSTs to REST, swallows failure, removes `StorageKeys.SHIFT`, sets `shift: null`. Any path calling `closeShift()` directly will set `shift: null` in Zustand (device thinks closed), leave `local_shifts.closed_at = null` (partial unique blocks reopening), and leave no `SESSION_CLOSE` in the fiscal outbox. Result: terminal wedged with no recovery except manual SQLite surgery.

**Fix required:** Either (a) route `closeShift()` through `appendZSessionCloseAndZReport` for v3, or (b) add a `local_shifts` row cleanup to the legacy close path for state consistency.

---

### MED-2 — `shift_number` uniqueness is procedural only; no DB constraint on closed shifts

**Files/lines:**
- `apps/pos/src/lib/db/repositories/localShiftRepository.ts:80` — `nextShiftNumber()` uses `MAX(shift_number)` inside write transaction
- `apps/pos/src/lib/db/migrations.ts:1646` — partial unique index: `WHERE closed_at IS NULL` (one-open only; closed shift numbers can repeat)
- `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:57` — server-side UNIQUE is Phase 3 deferred

**Problem:** `MAX(shift_number)` is correct for single-writer monotone increment, but closed shift numbers have no uniqueness constraint. Hardware swap without deprovisioning or restore from an old backup with a reset `local_shifts` table can produce duplicate shift numbers that silently pass until Phase 3.

**Recommendation:** Add a non-partial UNIQUE index on `(terminal_id, shift_number)` to `local_shifts` in migration v52. The partial index remains for the one-open guard but is insufficient for number integrity.

---

### MED-3 — Test coverage weakened; deleted tests not replaced

**Files/lines:**
- `apps/pos/src/stores/__tests__/terminalStore.test.ts` (diff) — `SHIFT_ALREADY_OPEN` adoption test deleted
- `apps/pos/src/stores/__tests__/terminalStore.test.ts` (diff) — offline fork (`offline-<uuid>`) open test deleted
- `apps/pos/src/stores/__tests__/terminalStore.test.ts:379-452` — new v3 open tests use fully mocked db (no SQLite round-trip)

**Problem:** Deleted tests were the only coverage for double-open protection and offline fallback. No equivalent guard tests were added for: (1) one-open SQLite constraint blocking a second `openShift()` call, (2) backfill handling grandfathered `offline-<uuid>` IDs (see HIGH-1), (3) v3 terminal throwing on offline open (new throw path is untested). The new v3 open tests mock the db via `vi.mock` so they do not exercise the actual write transaction, `nextShiftNumber()`, or the partial unique index.

---

### LOW-1 — `ZReportProjectionTest` `SESSION_OPEN` fixtures missing required `shift_number`

**Files/lines:**
- `apps/api/tests/Feature/Fiscal/ZReportProjectionTest.php:258` — fixture omits `shift_number`
- `apps/api/tests/Feature/Fiscal/ZReportProjectionTest.php:415` — second fixture also omits `shift_number`
- `apps/api/app/Modules/Fiscal/Domain/DTOs/SessionOpenPayload.php:9` — `PAYLOAD_KEYS` now includes `shift_number` as required

**Problem:** The test inserts `SESSION_OPEN` events directly into the DB (bypassing `StrictCanonicalParser`), so missing `shift_number` does not cause a test failure. But if any future path replays or re-validates these events through the strict parser, they will be quarantined. The fixtures are no longer representative of real device output.

**Fix:** Update the test fixtures to include `shift_number` (e.g., `1`).

---

### LOW-2 — No explicit `crypto.getRandomValues` guard in `uuidv7.ts`

**Files/lines:**
- `apps/pos/src/lib/uuidv7.ts` — uses `crypto.getRandomValues` without a fallback or init guard

**Assessment:** Tauri 2 webview exposes the Web Crypto API, so this is not a current bug. However, if tests ever run in a pre-v19 Node environment the import will throw with an opaque error.

**Recommendation:** Add a defensive guard at module init: `if (!globalThis.crypto?.getRandomValues) throw new Error('Web Crypto API required');`

---

### LOW-3 — Migration v52 `opening_cash TEXT DEFAULT '0'` — confirmed compliant

**Files/lines:**
- `apps/pos/src/lib/db/migrations.ts:1646`

**Assessment:** Storing as TEXT (string decimal) is correct per the monetary precision contract. `DEFAULT '0'` is a valid decimal string. No issue.

---

## Summary Table

| ID | Severity | Area | Description |
|----|----------|------|-------------|
| HIGH-1 | HIGH | Backfill / lifecycle | `offline-<uuid>` installed as `fiscal_shift_id` for grandfathered shifts — terminal wedge |
| HIGH-2 | HIGH | Deployment coupling | Phase 1 removes REST POST before Phase 2 server projector — breaks web/admin shift visibility |
| MED-1 | MED | Lifecycle / close path | Direct `closeShift()` clears Zustand but leaves `local_shifts` OPEN — reopen wedge |
| MED-2 | MED | Shift number integrity | No full UNIQUE on `(terminal_id, shift_number)`; duplicates undetected until Phase 3 |
| MED-3 | MED | Test coverage | Deleted `SHIFT_ALREADY_OPEN`/offline-fork tests not replaced; new tests mock the db layer |
| LOW-1 | LOW | Test fixtures | `ZReportProjectionTest` `SESSION_OPEN` fixtures missing required `shift_number` |
| LOW-2 | LOW | UUIDv7 | No explicit `crypto.getRandomValues` guard at module init |
| LOW-3 | LOW | Migration | `opening_cash TEXT DEFAULT '0'` — confirmed compliant, no issue |

---

## What Looks Correct

- `SESSION_OPEN` + `OPENING_FLOAT` + anchor + `local_shifts` insert are all inside one `withWriteTransaction` — atomically correct.
- `nextShiftNumber()` runs inside the same write gate transaction — no TOCTOU gap for same-device races.
- `tx as unknown as Database` cast is consistent with the rest of the codebase; no deadlock risk since the write-gate serializes all writers.
- `shift_number` is added to `PAYLOAD_KEYS` on both device and server symmetrically — no hash mismatch for Phase 1 events.
- M1 wrapping `appendXReport` + `appendZCashDrawerMovement` in the write gate is correct (was previously unguarded).
- UUIDv7 timestamp ordering and version/variant bits appear correctly set (48-bit ms timestamp, version `0x7`, variant `0b10`).
- Migration v52 partial unique `WHERE closed_at IS NULL` correctly enforces one-open-per-terminal.
- `fetchCurrentShift` SQLite-first path correctly falls through to the server for v3 terminals — no legacy device regression.
- `StorageKeys.SHIFT` removal when no open local row is correct for v3 (prevents backfill from running twice).

---

## Verdict

**REQUEST-CHANGES**

HIGH-1 (grandfathered shift wedge) and HIGH-2 (deployment coupling) must be addressed before merging. MED-1 (direct close path) should be fixed in the same batch. MED-2 and MED-3 are acceptable as tracked follow-up items if owners explicitly accept the risk. LOW findings are minor polish.
