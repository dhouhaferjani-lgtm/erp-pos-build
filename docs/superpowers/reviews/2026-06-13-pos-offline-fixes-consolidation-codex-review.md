# Code Review: feat/pos-offline-fixes consolidation branch

**Date:** 2026-06-13
**Branch:** `feat/pos-offline-fixes`
**Base:** `dev`
**Reviewer:** Codex automated (via Claude Sonnet 4.6 subagent)
**Diff command:** `git -C /Users/houssamr/Projects/syneriva/apps/erp.dev-consolidation diff dev...feat/pos-offline-fixes`

---

## VERDICT: APPROVE-WITH-MINOR-EDITS

---

## EXECUTIVE SUMMARY

This consolidation branch ships four well-scoped, complementary fixes for a documented "reports show zero sales" incident and the "database is locked" checkout failure. The fiscal correctness of the core changes — explicit currency plumbing into `GeneralLedgerService.postEntry` for Horizon workers, `fiscal-projections` queue registration, fiscal-id merge on shift re-hydration, and SQLite UTC timestamp normalization — is sound and each fix is accompanied by a targeted test that pins the specific failure mode. The single-writer architecture (Rust `db_writer.rs` + JS `writeGate.ts`) is well-designed and internally consistent. Cherry-pick coherence is clean: no dangling imports to excluded demo or deploy source files. One medium-severity gap exists: `appendXReport` in `reportApi.ts` still calls `engine.append()` outside a `withWriteTransaction` gate, inconsistent with the architecture this branch establishes, though it is pre-existing and not newly introduced here. Two low-severity issues round out the findings.

---

## FINDINGS

### MED

#### M1 — appendXReport and appendZCashDrawerMovement outside withWriteTransaction
**File:** `apps/pos/src/api/reportApi.ts:461` and `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:551,578`

**Description:** `FiscalEventEngine.append()` is documented as running "inside the CALLER's SQLite transaction — never commits" (see `FiscalEventEngine.ts:525-526`). The branch correctly migrates `authorZSessionOpenWithOpeningFloatOnDb` (two linked appends: SESSION_OPEN + OPENING_FLOAT) to `withWriteTransaction('fiscal', ...)`. However, `appendXReport` (`reportApi.ts:461`) and the `authorZCashDrawerMovement` dispatch path (`zSessionAuthoring.ts:662-669` → `appendZCashDrawerMovement` at line 578) both call `engine.append(db, ...)` with the raw pooled `Database` reference, outside any write-gate transaction.

This is pre-existing on `dev` and is not a regression introduced by this branch. However, since this branch establishes the single-writer contract and adds the ESLint guard, it creates an inconsistency: the two cases most recently touched by the branch (SESSION_OPEN) are gated, but X-report and cash-drawer movements are not. `appendXReport` is a single `engine.append()` — it modifies the hash chain in one statement, which is autocommit-safe but NOT serialized through the fiscal lane, meaning a concurrent `SALE_RECEIPT` engine.append could interleave. `appendZCashDrawerMovement` is similarly unprotected.

**Fix:** Wrap `appendXReport`'s `engine.append()` block (lines 461-503 in reportApi.ts) inside `withWriteTransaction('fiscal', async (tx) => { ... await engine.append(tx, ...) ... })`. Do the same for the `appendZCashDrawerMovement` implementation (lines 578-594). The `authorZCashDrawerMovement` orchestrator at line 662 uses `lockTerminal` (JS-level mutex, no SQLite transaction), which is insufficient for the single-writer contract. This does not need to block merge but should be tracked as a hardening ticket.

---

### LOW

#### L1 — Handover doc references excluded docker-compose.demo.yml
**File:** `docs/superpowers/handovers/2026-06-12-pos-reports-audit.md:74`

**Description:** The handover document at line 74 contains:
```
Demo stack: `docker compose -f docker-compose.demo.yml up -d --wait`, http://localhost:8088,
```
`docker-compose.demo.yml` was deliberately excluded from this branch (it lives in the 13 demo-specific commits that were not cherry-picked). The file does not exist in this worktree. This will confuse anyone following the handover document to reproduce the reports-audit scenario.

**Fix:** Either note in the handover doc that `docker-compose.demo.yml` requires the parapharmacy Tunisia demo branch, or replace the reference with the standard `docker-compose.yml` stack for the local dev environment.

---

#### L2 — OpenShiftScreen uses hardcoded Tailwind colors, not added to tokenMigratedGlobs
**File:** `apps/pos/src/components/pos/OpenShiftScreen.tsx:41,52,67,76`

**Description:** `OpenShiftScreen` is a new component introduced by this branch. It uses hardcoded Tailwind color classes (`text-gray-900`, `text-gray-500`, `text-gray-700`, `bg-red-50`, `text-red-700`, `bg-blue-600`) rather than the semantic design tokens established by the POS UI remediation that landed on the base branch. The component path (`src/components/pos/OpenShiftScreen.tsx`) is not included in `tokenMigratedGlobs` in `eslint.config.js`, so the color rule fires only as `warn` here rather than `error`. This is cosmetically inconsistent with the remediated components.

**Fix:** Either add `'src/components/pos/OpenShiftScreen.tsx'` to `tokenMigratedGlobs` in `eslint.config.js` and migrate the hardcoded classes to semantic tokens (e.g. `bg-action` for the button, `text-danger` for the error state), or accept the current state as a known debt item for the next UI sweep.

---

## CLEAN SECTIONS

### 1. Fiscal Correctness — PASS with one pre-existing caveat

The three most critical fiscal fixes are sound:

- **TreasuryReceiptBridge GL post on Horizon workers** (`TreasuryReceiptBridge.php:432`, `GeneralLedgerService.php:1121-1124`): `postEntry` now accepts an optional `?string $currencyCode` parameter. When called from the bridge (a Horizon worker with no bound `CompanyContext`), the receipt currency is passed explicitly, bypassing the no-arg `getScale()` that throws `UnboundCompanyContextException`. The fallback `$this->scale()` is retained for the HTTP request path where `CompanyContext` is available. The new test `test_bridge_applies_without_a_bound_company_context_like_a_queue_worker` correctly clears `CompanyContext` before calling `apply()`, mirroring worker reality.

- **fiscal-projections queue** (`config/horizon.php:204`): `fiscal-projections` added to the supervisor's `queue` list alongside `enrichment`, `images`, `imports`. The `HorizonQueueCoverageTest` CI guard (new file `tests/Unit/Config/HorizonQueueCoverageTest.php`) statically scans all `onQueue()` callsites and asserts coverage — a durable prevention against recurrence.

- **Fiscal-id preservation on shift re-hydrate** (`terminalStore.ts:723-751`): `fetchCurrentShift` now merges `fiscal_shift_id` / `fiscal_session_id` from the cached shift when the server returns the same shift id, rather than replacing the shift wholesale. The merge is conditional on `cached.id === normalized.id`, so a new shift still replaces cleanly. The `withShiftUser` helper synthesizes the `user` object from server cashier fields, preventing the "undefined is not an object (evaluating 'shift.user.id')" crash.

- **SQLite UTC timestamp normalization** (`sqliteTime.ts`, `reportApi.ts:383,527`, `zReportService.ts:175`): `toSqliteUtc()` is correctly applied at all three SQL boundaries that compare shift open timestamps against `datetime('now')` rows. The helper correctly detects already-normalized timestamps (space-separated UTC) and passes them through without re-parsing, avoiding the timezone-shift hazard.

The `appendXReport` missing write-gate issue (M1) is pre-existing on `dev` and not introduced by this branch.

### 2. Single-Writer Architecture Soundness — PASS

The architecture is sound:

- **Rust `db_writer.rs`**: One `SqliteConnection` per DB name, held in a `Mutex<HashMap<String, SqliteConnection>>`. Each Tauri command acquires the mutex for its duration, ensuring true single-writer serialization at the OS layer. WAL mode + `busy_timeout(5s)` on the connection means the reader pool can proceed concurrently. `writer_open` replaces any previous connection cleanly.

- **`writeGate.ts`**: Two-lane priority queue (`fiscal` preempts `sync`). The `pump()` function runs one job at a time via an async loop with a `pumping` flag — no race between pump re-entries. `withWriteTransaction` issues `BEGIN IMMEDIATE TRANSACTION` on the Rust writer, wrapping the callback, then `COMMIT` or `ROLLBACK`. ROLLBACK failures are caught and logged without swallowing the original error.

- **Deadlock prevention**: The gate contract ("Job bodies must NEVER call enqueueWrite/withWriteTransaction") is documented in `writeGate.ts:21`, the ESLint `BEGIN/COMMIT/ROLLBACK` guard prevents new violations at the source level, and the `replaceIncoming` migration to `withWriteTransaction('sync')` passes `tx` explicitly with a note not to use the gated wrapper inside.

- **Offline-first guarantee**: The `setWriter(null)` pattern allows boot-time injection, and `getWriterOrThrow()` fails loudly if the writer was not initialized, preventing silent silencing. The `openWriter`/`closeWriter` pair in `dbWriter.ts` is correctly wired.

No deadlock, dropped-write, or offline-guarantee break scenarios were identified.

### 3. Cherry-Pick Coherence — PASS with one doc reference (L1)

Source code is clean: no imports, config keys, or module references to excluded demo or deploy commits were found in `apps/pos/src/`, `apps/api/app/`, or `apps/api/config/`. All new module imports resolve correctly in the worktree. The one dangling reference is a doc reference in a handover markdown file (L1 above), not a code dependency.

### 4. POS UI Remediation Interaction — PASS with style debt (L2)

The `HomePage.tsx` diff is minimal and clean: it removes the inline shift-open form (41 lines) and replaces it with `<OpenShiftScreen onOpenShift={(openingCash) => void handleOpenShift(openingCash)} />`. The `handleOpenShift` callback signature change (`openingCash: string` parameter) is consistent. No token-migrated components were reverted. The `OpenShiftScreen` itself uses hardcoded colors (L2) but this is style debt, not a functional regression.

---

## MERGE RECOMMENDATION

Merge is safe subject to the following:

1. **(Required before merge)** Fix the handover doc reference to `docker-compose.demo.yml` (L1) so future developers are not misled about the reproduction environment.

2. **(Recommended, non-blocking)** File a hardening ticket for M1: migrate `appendXReport` in `reportApi.ts` and `appendZCashDrawerMovement` in `zSessionAuthoring.ts` to run inside `withWriteTransaction('fiscal', ...)`. This is a consistency gap with the architecture this branch establishes, not a blocking correctness issue for the immediate fixes being shipped.

3. **(Non-blocking)** Add `OpenShiftScreen.tsx` to `tokenMigratedGlobs` and migrate its colors in the next UI sweep (L2).

The fiscal correctness fixes, Horizon queue fix, and single-writer architecture are all correct and well-tested. This branch unblocks the production POS reports and addresses the offline checkout deadlock.
