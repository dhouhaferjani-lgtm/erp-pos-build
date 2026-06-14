# Handover — Offline-First POS Shifts: Phase 6 + F-3 security follow-up

**For:** a fresh session. **Date:** 2026-06-14.
**Worktree:** `apps/erp.offline-shifts`  **Branch:** `feat/offline-first-shifts`.
**Run all commands from this worktree.** It has its own installed deps (pnpm in `apps/pos`/`apps/web`; `composer install` was run in `apps/api`).

---

## Status — Phases 0–5 COMPLETE, on local `dev`

`dev` (local, in the `apps/erp.dev-consolidation` worktree) is at `0ee83276e` and
contains all of Phases 0–5. **Nothing is pushed to origin yet.** Each phase was
Codex-reviewed; every BLOCKER/HIGH was verified + fixed. Reviews + dispositions
live in `docs/superpowers/reviews/2026-06-14-pos-offline-first-shifts-phase*.md`.

What shipped (device-authoritative shift lifecycle, mirroring the receipt model):
- **P0** `local_shifts` table (migration **v53** — renumbered from v52 after a dev v52 collision) + per-terminal `shift_number` counter.
- **P1** device-authoritative OPEN (UUIDv7 + in-tx `shift_number`, atomic `local_shifts`+anchor+SESSION_OPEN/OPENING_FLOAT), SQLite-first `fetchCurrentShift`, dropped server-first/offline-fork/adopt; M1 (X_REPORT + Z cash-drawer via the fiscal write-gate); the device↔server `shift_number` payload contract.
- **P2** `pos_shifts` is a projection of SESSION_OPEN (idempotent + one-open pre-check, atomic apply tx), `(terminal_id, shift_number)` UNIQUE, `ShiftResource` exposes `session_id`/`opened_at_device`, 409 on v3 `POST /pos/shifts/open`.
- **P3** device-authoritative CLOSE: SESSION_CLOSE closes `pos_shifts` (idempotent, throws-to-retry if the open hasn't projected yet, `variance_severity` mapped through the enum so `'balanced'`→null), 409 on v3 close/sync-close, `closeShift` skips REST for v3.
- **Backfill removed** (clean-slate: no upgrade-mid-shift retrofit needed).
- **P4** one-id sweep (`fiscalShiftIdForReceipt`→`shift.id`, Header X/Z + cashDrawer gated on v3) + `apps/web` v3 UI gate (hide Open/Close for `fiscal_schema_version===3`).
- **P5 B5/B6/F-8** eager offline-EOD caching (companyConfig cache already wired; eager fraud-settings cache + offline read; eager operator-PIN sync at activation; cache refreshed on online EOD open).
- **P5 B7** offline manager-PIN approval for the above-hard-variance close — **reused** `operator_pins` + `operatorApproval/verifyScopedManagerPin` (scope `close_shift_variance`) instead of a distinct `manager_pins` table (owner approved the pivot — the hashes + scope are already mirrored via `/pos/auth/pin-data`). Added `targetOperatorId` to pin the verify to the selected manager; offline managers list derived from `operator_pins`; v3 close fails closed when the fraud policy isn't cached.
  - **BLOCKER fixed here:** `pullOperatorPins` now sends `terminal_id` (`/pos/auth/pin-data?terminal_id=<id>`) — it never did, so ALL offline approvals (cash-drawer, discount, EOD) were silently broken (`terminal_ids: []` → `scope_mismatch`).

---

## Remaining: Phase 6 (spec §7)

Read the spec first: `docs/superpowers/specs/2026-06-13-pos-offline-first-device-authoritative-shifts-design.md` §7 Phase 6 + §4.c (reconciliation).

### 6.1 — Device `shift_number` counter seed from server `MAX` (code; do first)
On first boot / activation, seed the device's per-terminal `shift_number` so the
device counter continues from the server's existing `MAX(shift_number)` rather
than restarting at 1 (avoids colliding with server-projected numbers from a
prior install). `pos_shifts.shift_number` is already populated server-side, so
this is a DEVICE counter seed only — no server data backfill (Codex F-13 moot).
- `nextShiftNumber` lives in `apps/pos/src/lib/db/repositories/localShiftRepository.ts` (MAX of `local_shifts.shift_number`+1). On a fresh `local_shifts`, seed from a server read (e.g. a `GET /pos/shifts?terminal=…` MAX or a dedicated field) during `seedOfflineHashChain` (`apps/pos/src/stores/terminalStore.ts`), best-effort, before the first open. Decide the server source (add a `max_shift_number` to the terminal payload, or read `pos_shifts`).

### 6.2 — Background reconcile + advisory banner (code)
§4.c: a background pass that detects when the server `pos_shifts` was closed
remotely (admin recovery) while the device thinks the shift is open, and surfaces
an **advisory, audited, idempotent** banner (no auto-close — Decision 4 disallows
web force-close by default; the genuine lost-device admin-recovery `SESSION_CLOSE`-
on-behalf is a separate deferred follow-up). Anchor: the sync cycle in
`apps/pos/src/lib/sync/syncService.ts` (`runFullSync`) + a `terminalStore` banner
state. Must be idempotent and never double-close (Codex F-12 — the SESSION_CLOSE
projection is already idempotent).

### 6.3 — Live offline cycle (operational; needs the demo stack)
End-to-end open→sell→close→reopen on the **Tunisia parapharmacy stack**
(`docker-compose.demo.yml`, http://localhost:8088, owner@pharmabio.tn/password,
POS01 @ Tunis) — that compose file lives on `feat/parapharmacy-tunisia-demo`, NOT
on `dev`. Pull it from there (or run there). Requires a live Tauri build (the POS
offline layer is Tauri-only; a browser can't run the sale/PIN/Z/sync path).

---

## F-3 — active-membership security follow-up (separate, server-side)

Codex B7 F-3 (HIGH, deferred): `/pos/auth/pin-data` (PosAuthController) and
`PinVerifier::verifyForApproval` gate on company-membership **existence**, not
**active** status, and derive approval scopes from global permissions. The online
`AuthorizedManagersController` filters **active** `UserCompanyMembership`. So a
suspended/revoked-membership user with a retained `pos_pin` +
`pos.close_shift_with_variance` permission can be mirrored into `operator_pins`,
appear in the offline manager list, and locally approve an above-hard close —
broader than the online list. This is a **pre-existing gap in the shared
operator-approval infra** (affects ALL offline override flows: cash-drawer,
discount, EOD), not introduced by B7, and low-risk on a clean-slate launch (no
suspended managers yet).
**Fix:** require an active `UserCompanyMembership` for the company in
`pin-data`'s user query AND in `PinVerifier::verifyForApproval`, mirroring
`AuthorizedManagersController.php:39-44`. Add regression tests (suspended member
absent from `approval_scopes`; rejected by manager-PIN verify). Files:
`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php`,
`apps/api/app/Modules/POS/Application/Services/PinVerifier.php`.

---

## Process + footguns (carry forward)

- **Codex review at the END OF EVERY phase** (owner instruction). The Codex
  runtime currently CANNOT write into this worktree sandbox — have it OUTPUT the
  review verbatim and write the file yourself to `docs/superpowers/reviews/`.
- **`dev` advances under a parallel session.** It is NOT a fast-forward target by
  default — `git merge dev` INTO `feat/offline-first-shifts` first (resolve, e.g.
  the migrations.ts version-collision pattern), verify, THEN
  `git -C apps/erp.dev-consolidation merge --ff-only feat/offline-first-shifts`.
  The dev worktree carries an untracked `go-live-security-audit.md` — a ff merge
  leaves it untouched; never operate on uncommitted work there.
- **NEVER run the full PHPUnit suite** (`php artisan test` no-filter / preflight)
  — it crashes the laptop. Always `--filter`/a file path.
- **Single-writer:** inside a `withWriteTransaction('fiscal')` job use ONLY the
  `tx` handle; never call a self-transacting wrapper (deadlock).
- **Migration numbering is a global UNIQUE key** — the next device SQLite
  migration is **v54** (v53 = `create_local_shifts`).
- **Pre-existing test failure** (not yours): `syncService > pullProducts >
  deleted_ids tombstone` fails on `dev` (cross-location-stock merge).
- **Commit messages** end with the Co-Authored-By line; commit only the files for
  the unit; React Doctor's staged-regression hook warning is non-blocking (the
  no-misused-promises onClick pattern is pre-existing).
- **Nothing is pushed to origin.** Decide push timing with the owner.

## First action for the next session
Start at **6.1** (counter seed — smallest, code-only, TDD-able): decide the
server source for the per-terminal `MAX(shift_number)` and seed
`nextShiftNumber` on a fresh `local_shifts` in `seedOfflineHashChain`. Then 6.2
(reconcile banner), Codex-review each, then 6.3 on the demo stack.
