# Handover — Implement Offline-First, Device-Authoritative POS Shifts

**For:** a fresh session (Opus/Fable). **Date:** 2026-06-14.
**Worktree:** `apps/erp.offline-shifts`  **Branch:** `feat/offline-first-shifts` (based on `dev`, fast-forwarded to `9177950d8`).
**Repo root for this work = this worktree.** Run all commands from here. A parallel session is active on `dev` — rebase/merge latest `dev` at session start, and never share another worktree (see memory `feedback_parallel_session_worktree_isolation`).

---

## Mission

Implement the **offline-first, device-authoritative shift lifecycle** so POS shift open/close work with **no network** (mirroring the already-device-authoritative receipt model). After the design + adversarial review cycle, the spec is **viable and reconciled — no surviving blocker**. The next step is **execution, starting at Phase 0**.

## Read first (all on this branch)

| Artifact | Path |
|---|---|
| **The spec (r2, authoritative)** | `docs/superpowers/specs/2026-06-13-pos-offline-first-device-authoritative-shifts-design.md` |
| Codex adversarial review r1 (of the spec) | `docs/superpowers/reviews/2026-06-13-pos-offline-first-shifts-spec-codex-review.md` |
| Codex consolidation review (of the shipped fixes) | `docs/superpowers/reviews/2026-06-13-pos-offline-fixes-consolidation-codex-review.md` |
| Memory | `project_pos_offline_first_shifts_spec.md`, `project_pos_reports_audit_2026_06_12.md`, `project_pos_single_writer_sqlite.md` |

The spec's **§12 Review Reconciliation** table is the single most important section: it records, per Codex finding, the verified verdict (6 were verifiably FALSE, 4 overstated/moot, 7 actionable-and-folded-in) with code evidence. **Read §12 before trusting any external claim about the existing code.**

## What already shipped to `dev` (do NOT rebuild)

The "reports show zero sales" + "database is locked" incident fixes and the shift-open crash fixes are merged to `dev` and present on this branch:
- SQLite UTC timestamp normalization (`sqliteTime.ts` + 4 read sites), Horizon `fiscal-projections` queue + `HorizonQueueCoverageTest`, `TreasuryReceiptBridge` GL `postEntry(..., $currencyCode)` for Horizon workers, `fetchCurrentShift` fiscal-id merge + `withShiftUser`, `fiscalShiftIdForReceipt()` at all 3 fiscal authoring sites, `OpenShiftScreen` (touch numpad + currency-scaled default), the single-writer architecture (Rust `db_writer.rs` + `writeGate.ts`).

## The design in brief (mirror the receipt model)

Device mints a **UUIDv7 shift id + a per-terminal monotone `shift_number`**, authors `SESSION_OPEN` + `OPENING_FLOAT` (and at close `SESSION_CLOSE` + `Z_REPORT`) into a **new local SQLite `local_shifts` table** *inside the existing single-writer fiscal transaction*, syncs via the unchanged fiscal-event rail, and **`pos_shifts` becomes a projection**. "Current open shift" is read purely from SQLite — no network. One UUID per shift (`shift_id == fiscal_shift_id == pos_shifts.id`). REST `/pos/shifts/*` demoted to v3-gated reconcile/read (kept for legacy v<3 + web-admin reads).

**Headline (verified, post-review):** the work is **device rewire + EXTENDING one existing projector**, not a ground-up build. `pos_shifts.shift_number`, the `(terminal_id, shift_number)` index, the `pos_shifts_one_open_per_terminal` partial unique index, and a registered, already-firing `ZSessionLifecycleProjection` (handles `SESSION_OPEN`/`SESSION_CLOSE`, projects `applied` on the live demo) **all already exist**. Only `shift_number` is a new `SESSION_OPEN` *payload* field.

## Owner decisions (LOCKED — do not re-litigate)

1. Manager-PIN offline → **mirror bcrypt hashes to SQLite** (`manager_pins`, distinct from `operator_pins`) + offline lockout + `synced_at`/`revoked_at` rotation; eager PIN sync at login. (Phase 5)
2. `shift_number` → **first-class additive `SESSION_OPEN` payload field, NO `SessionOpenV2`** (system is pre-live; events-immutable rule protects already-emitted production events, of which there are none). v3-gated.
3. v3 REST `/pos/shifts/open`+`/close` → **hard 409 `SHIFT_DEVICE_AUTHORITY_REQUIRED`** (mirrors `ZReportSyncController:72`).
4. Web-admin force-close → **disallowed by default** (web POS panel hides open/close for v3); admin-recovery `SESSION_CLOSE`-on-behalf is a **deferred** follow-up.
5. Offline-EOD caches (companyConfig B5 + fraud-settings/managers B6) → **included as Phase 5**.

## Phased plan (from spec §7) — start at Phase 0

- **Phase 0** — `local_shifts` SQLite table (migration) + `nextShiftNumber(terminalId)` device counter. Partial unique on `(terminal_id) WHERE status='OPEN'`. One-time copy of cached `StorageKeys.SHIFT` into `local_shifts`. **No server changes.** Self-contained, TDD-able now.
- **Phase 1** — device-authoritative OPEN: mint UUIDv7 + number; author `SESSION_OPEN`+`OPENING_FLOAT`+`local_shifts` insert + **receipt-anchor insert** atomically inside the existing `authorZSessionOpenWithOpeningFloatOnDb` tx (via the `tx` handle); drop server-first POST / `offline-<uuid>` fork / `SHIFT_ALREADY_OPEN` branch. `fetchCurrentShift` → SQLite-first.
- **Phase 2** — EXTEND `ZSessionLifecycleProjection` to upsert `pos_shifts` on `SESSION_OPEN` (idempotent `ON CONFLICT (id) DO NOTHING`; treat the one-open partial-index conflict as idempotent success). Upgrade `(terminal_id, shift_number)` index to UNIQUE. `ShiftResource` exposes `shift_number`/`session_id`/`opened_at_device`. 409 guard on v3 open.
- **Phase 3** — device-authoritative CLOSE + idempotent `SESSION_CLOSE` projection (no double-close). 409 on v3 close/sync-close.
- **Phase 4** — one-id consumer sweep (collapse `fiscal_shift_id` into `id`) + **`apps/web` POS panel hides open/close for v3 terminals** (`shiftApi.ts` exports `openShift`/`closeShift` — they must not 409 interactively).
- **Phase 5** — offline-EOD completeness: durable `companyConfig` cache (B5), `refreshFraudSettingsCache` + authorized-managers cache (B6), eager operator-PIN sync, `manager_pins` mirror + lockout/rotation (B7).
- **Phase 6** — background reconcile (advisory banner on remote-close), device `shift_number` counter seed from server `MAX`, live offline open→sell→close→reopen cycle.

## Outstanding hardening to fold in (from the consolidation review, APPROVE-w-minor)

- **M1 (do this in Phase 1):** `appendXReport` (`reportApi.ts:~461`) and `appendZCashDrawerMovement` (`zSessionAuthoring.ts:~578`) still call `engine.append(db, …)` **outside** a `withWriteTransaction('fiscal')` gate — inconsistent with the single-writer contract this work extends. Migrate both to author via the gate's `tx` handle while you're in this area.
- **L2 (optional):** `OpenShiftScreen.tsx` uses hardcoded Tailwind colors; add it to `tokenMigratedGlobs` in `eslint.config.js` and migrate to semantic tokens during a UI sweep.

## Critical invariants & footguns

- **Single-writer (memory `project_pos_single_writer_sqlite`):** inside a write-gate job use ONLY the provided `tx` handle. NEVER call a self-transacting wrapper (`authorZSessionOpenWithOpeningFloat`, `enqueueWrite`, pool-`execute`) inside another gate — it re-acquires the writer mutex and **deadlocks**. The `local_shifts` + anchor writes go inside the EXISTING `authorZSessionOpenWithOpeningFloatOnDb` `withWriteTransaction` (`zSessionAuthoring.ts:503`), mirroring `receiptService.ts:413`.
- **TDD always** (memory + CLAUDE.md): write the failing test first. Frontend: `pnpm exec vitest run <path>`, `pnpm exec tsc --noEmit`, `pnpm exec eslint <files>` from `apps/pos`. Backend: scoped `php artisan test --filter` / a file path from `apps/api`.
- **NEVER run the full PHPUnit suite** (`php artisan test` no-filter or `scripts/preflight.sh`) — it crashes the laptop (memory `feedback_no_full_test_suite`). Always `--filter`.
- **Cross-app deprecation check** before touching shared endpoints — `apps/web/src/features/pos/api/shiftApi.ts` consumes the shift endpoints (memory `feedback_cross_app_deprecation_check`).
- Pre-live: there are no production-sourced fiscal events to protect, so the `SESSION_OPEN` payload may be extended directly (Decision 2).

## Key files & proven patterns (anchors)

- Proven "author N events in one gate" pattern: `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:490` (`authorZSessionOpenWithOpeningFloatOnDb`, the function to EXTEND) and `apps/pos/src/lib/offline/receiptService.ts:413`.
- Device shift state: `apps/pos/src/stores/terminalStore.ts` — `openShift` (~`:755`), `fetchCurrentShift` (~`:721`), `authorShiftOpenFiscalEvents` (~`:194`, where the anchor write currently lives, outside the gate — move it in), `fiscalShiftIdForReceipt` (~`:206`).
- Projector to extend: `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php` (registered at `POSServiceProvider.php:50-58`).
- Server shift schema: `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php` (`shift_number` col `:33`, `(terminal_id,shift_number)` index `:62`, `pos_shifts_one_open_per_terminal` `:69`, closed-logic/variance CHECKs).
- v3-gating precedent: `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:72`.
- Payloads: `SessionOpenPayload.php` (add `shift_number`), `SessionClosePayload.php` (already carries everything close needs).
- SQLite migrations: `apps/pos/src/lib/db/migrations.ts`. Repos: `apps/pos/src/lib/db/repositories/`.

## Testing & demo stack

- Unit/integration tests run fine on this `dev`-based branch.
- **Live Tauri demo testing** needs the Tunisia parapharmacy stack (`docker-compose.demo.yml`, http://localhost:8088, owner@pharmabio.tn/password, POS01 @ Tunis) — that compose file lives on `feat/parapharmacy-tunisia-demo`, **not** on `dev`. For end-to-end live validation (Phase 6), pull it from that branch or run there. For Phases 0–5 unit work you don't need it.
- The single-writer test pattern: `setWriter(adapter)` + `__resetWriteGateForTesting()` in setup (see any swept `__tests__`); tripwire `poolTransactionUnsoundness.test.ts`; concurrency proof `concurrentCheckout.integration.test.ts`.

## Immediate next step

The owner was choosing between (a) a focused 2nd Codex pass on the revised spec sections, vs (b) **start Phase 0**. Recommendation given: **start Phase 0** (the design is sound, the review added no blocker), and run Codex on the implementation diff later rather than the spec again. Confirm with the owner, then begin Phase 0 TDD: write the `local_shifts` migration + `nextShiftNumber` test first.
