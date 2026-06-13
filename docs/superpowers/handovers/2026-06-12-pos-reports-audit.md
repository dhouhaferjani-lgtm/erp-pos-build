# Handover: POS Reports Audit (X report / daily sales show no sales)

**For:** a fresh Fable 5 session.
**Date:** 2026-06-12
**Worktree:** `apps/erp.db-per-tenant`  **Branch:** `feat/parapharmacy-tunisia-demo` (pushed, tip `4b5242ef0`)
**Read first:** memory `project_pos_single_writer_sqlite.md` (the architecture that just shipped) and `project_parapharmacy_tunisia_demo.md`.

---

## Mission

Audit EVERY report surface in the Tauri POS (`apps/pos/`) from a code perspective and fix what's broken:
X report, daily sales, Z preview / end-of-day, the ReportsMenu entries, and any back-office-facing
sales summaries the POS feeds. The owner will test live in parallel and supply screenshots +
console logs; your job is to verify each report's data path end to end in code (and with tests),
not to wait for the live evidence.

## The symptom

On the Tunisia demo stack, a cash sale now completes reliably (the 2026-06-12 single-writer
architecture fix — see below). But **X report and daily sales show NO sales immediately after a
successful sale**. Unknown yet whether this is pre-existing, demo-data-specific, or a regression.

## Context you must not violate (single-writer architecture, shipped 2026-06-12)

- Root cause of the old checkout failure: the tauri-plugin-sql SQLx POOL splits JS-issued
  BEGIN…COMMIT across physical connections. ALL transactions now run via
  `withWriteTransaction('fiscal', fn)` from `src/lib/db/writeGate.ts` on a Rust-owned single
  connection (`src-tauri/src/db_writer.rs`). The pooled handle is reads-only; its `execute` is
  gated per-statement (sync lane). An ESLint guard forbids BEGIN/COMMIT/ROLLBACK on pooled handles.
- Inside a gate job, use ONLY the provided `tx` handle — calling `enqueueWrite`/pool-`execute`
  inside a job deadlocks the gate.
- Tests that hit a transaction path need `setWriter(adapter)` + `__resetWriteGateForTesting()`
  (see any swept `__tests__` file for the pattern). The Tauri boundary rejects with STRINGS.
- 13 commits of this architecture landed `60286d73d..4b5242ef0` — if you suspect a reports
  regression from the sweep, diff those (especially `zReportService.ts`, `zSessionAuthoring.ts`).

## Hypotheses to check (not exhaustive — audit holistically)

1. **Where does each report READ from?** If a report reads `offline_receipts` by `shift_id`,
   does the just-made sale carry the shift_id the report queries? (App restart between sale and
   report = new shift?) If it reads `fiscal_events`, does it filter by `chain_context` /
   `event_type` correctly?
2. **X report requires a SESSION_OPEN fiscal event** (`zSessionAuthoring.appendXReport` →
   `requireSessionOpenEvent`). If the shift was opened while checkout was still broken (pre-fix),
   the session-open events may be missing/failed → empty or erroring report. Check what the UI
   does when `requireSessionOpenEvent` throws — is the error swallowed into an empty state?
3. **Server-backed reports**: if daily sales reads a SERVER endpoint, the sale only appears after
   `pushOfflineReceipts` succeeds AND server-side projection runs. The push-path
   `response.results` crash was just fixed (`apiPostRaw`) — verify the receipt actually ingests
   on the demo server now (check `fiscal_events.sync_status`, server `fiscal_events` table, and
   whether earlier failed attempts left a sequence conflict).
4. **Old dead-lettered state**: the demo DB lived through the lock bug — `offline_receipts` may
   hold `failed` rows, and `runStuckReceiptRecovery` only resets the `database is locked`
   signature. Check what states exist locally (`status`, `sync_error`, `sync_status`).
5. **Timezone/business_date filters**: TND demo, `business_date` vs local-day boundaries.

## Key files / entry points

| File | Role |
|---|---|
| `src/components/pos/ReportsMenu.tsx` | report entry points (labels → handlers) |
| `src/components/pos/XReportModal.tsx` + `src/components/organisms/XReportModal/` | X report UI (data comes from the CALLER — trace it, likely HomePage/Header) |
| `src/lib/fiscal/zSessionAuthoring.ts` | `appendXReport`, `requireSessionOpenEvent`, session-open authoring |
| `src/lib/offline/zReportService.ts` | Z generation, receipt snapshots, totals (reads receipts for shift) |
| `src/lib/offline/endOfDayPreview` (see `src/lib/offline/__tests__/endOfDayPreview.test.ts`) | end-of-day preview math |
| `src/lib/db/repositories/offlineReceiptRepository.ts` / `fiscalEventRepository.ts` | the read queries reports depend on |

## Workflow & rules

- Use `superpowers:systematic-debugging` first (root-cause before fixing), then TDD any fix.
- The live DB is at `~/Library/Application Support/com.syneriva.izipos/izipos-<companyId>.db` —
  you may inspect it read-only with `sqlite3` to see the actual rows the reports query.
- Demo stack: `docker compose -f docker-compose.demo.yml up -d --wait`, http://localhost:8088,
  owner@pharmabio.tn/password; POS via `cd apps/pos && pnpm tauri dev`, POS01 @ Tunis.
  (NOTE: `docker-compose.demo.yml` + the Tunisia demo seeders live on the standalone
  `feat/parapharmacy-tunisia-demo` branch — they were intentionally excluded from the
  dev consolidation, so this file is not present on `dev`.)
- Tests: `pnpm exec vitest run <path>`, `pnpm exec tsc --noEmit`, `pnpm exec eslint <files>`.
  **NEVER run the full PHPUnit suite** (crashes the laptop) — scope any backend test with `--filter`.
- The owner is testing live in parallel and will paste screenshots/console logs into the session —
  reconcile their evidence with your code findings; don't guess when evidence conflicts.
- Deliverable: every report surface either verified-working (with the test that proves it) or
  fixed (TDD) — plus a short findings table in the final summary.
