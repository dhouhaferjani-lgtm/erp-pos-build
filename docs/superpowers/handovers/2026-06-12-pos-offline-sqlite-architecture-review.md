# Handover: POS Offline SQLite / Sync / Fiscal-Write Architecture Review

**For:** a fresh Fable 5 session (deep architectural review, not a one-line patch).
**Date:** 2026-06-12
**Worktree:** `apps/erp.db-per-tenant`  **Branch:** `feat/parapharmacy-tunisia-demo` (pushed, tip `de12d6679`)
**Context memory:** read `project_parapharmacy_tunisia_demo.md` first.

---

## Mission

The Tauri POS (IziPOS, `apps/pos/`) cannot complete a cash sale: clicking **Exact** on the
cash-payment screen fails with **`Échec du paiement`**. The underlying error is
`error returned from database: (code: 5) database is locked`. Several targeted fixes have NOT
resolved it, which is the signal to **stop patching and review the architecture**.

**Do two things, holistically — do not just fix the lock:**
1. **Correctness:** make the offline sale reliably complete while a background/foreground sync is
   running, without ever risking fiscal-chain integrity.
2. **Performance:** the sale is *not snappy* — clicking checkout takes seconds when it should feel
   instant. Treat latency as a first-class design goal, not an afterthought. The owner explicitly
   suspects the current approach is architecturally wrong and wants it re-examined end to end.

Deliver: a brainstormed design (use `superpowers:brainstorming`) → spec → plan → TDD implementation.
Expect this to touch the offline DB layer, the sync scheduler, and the receipt/fiscal write path.

---

## The symptom (exact evidence — captured from the live Tauri console, not inferred)

```
[POS][offline][receipt] tx body threw — rolling back      receiptService.ts:532
  Object:
    errorType: "string"          // the Tauri SQL plugin REJECTS WITH A STRING, not an Error
    errorName: undefined
    message: "error returned from database: (code: 5) database is locked"
    hashSequence: null           // failed BEFORE the fiscal event was appended
    previousHash: null
    receiptNumber: ""            // failed BEFORE the receipt number was assigned
    terminalId: "69f3c50c-..."
[POS][offline][receipt] ROLLBACK also threw — connection may be in bad state   receiptService.ts:542
[POS][checkout][cash] failed                                                   paymentStore.ts:942
[POS][HomePage][handleCashConfirm] processCashCheckout threw                   HomePage.tsx:1099
```

Also present (a SEPARATE bug — does NOT block the sale, see below):
```
[POS][sync][pushOfflineReceipts] fiscal-event push threw —
  "undefined is not an object (evaluating 'response.results')"                 syncService.ts:321
[POS][syncService][pullProductsForeground] pull failed — Object               syncService.ts:753
```
(The WebSocket `ws://localhost:8085 / wss://localhost` errors are Reverb websocket noise — ignore.)

Earlier in the failure history the same `(code: 5) database is locked` was thrown one layer
earlier — at the **pre-transaction READ** `getTerminalState(db, …)` (`receiptService.ts:262`). So
the lock hits both reads and writes on the receipt path.

---

## Root cause established so far

- The Tauri SQL plugin (`@tauri-apps/plugin-sql`) wraps a **SQLx connection POOL**. `getDatabase()`
  returns ONE `Database` JS object (singleton per company DB `izipos-<uuid>.db`), but every
  `db.execute` / `db.select` borrows a **different physical connection** from the pool.
- Therefore the **cashier's receipt path and the sync writers run on different connections** and
  contend for the single SQLite file. SQLite returns `SQLITE_BUSY` (code 5) / `SQLITE_BUSY_SNAPSHOT`
  (517). The plugin surfaces these as plain **strings**.
- The DB is in **WAL** mode (`PRAGMA journal_mode=WAL`, set once in `db.ts` — file-persistent).
- The connection setup *assumes* SQLx gives every pooled connection a **5s `busy_timeout`**; the
  field evidence (immediate code-5 failures) shows that wait is **not happening**, and a
  per-connection `PRAGMA busy_timeout` cannot be reliably applied across a pool from JS.
- The contention is **persistent, not transient**: the foreground bulk pull
  (`pullProductsForeground`, syncService.ts:753) writes ~1000 products + ~580 `location_stock` rows
  in a long write transaction, and the 60s background scheduler (`syncScheduler.ts`,
  `BASE_INTERVAL_MS = 60_000`, backing off to 5 min) adds more. A receipt write that overlaps a bulk
  pull **starves** — which is why it fails consistently, not randomly.

This is the architectural problem: **two unrelated write workloads (bulk catalog sync vs. the fiscal
sale) compete for one SQLite file via a connection pool, with no serialization and no effective lock
patience.** It also explains the latency: the sale waits on locks AND does fiscal-hash + multi-table
writes synchronously on the click path.

---

## What was already tried (and why it is insufficient — keep or discard as the new design dictates)

All on branch `feat/parapharmacy-tunisia-demo`:

1. `2cc02452b` — `receiptService.ts` receipt tx changed from `BEGIN TRANSACTION` → `BEGIN IMMEDIATE
   TRANSACTION` (take the write lock up front; also serializes the chain read-modify-write).
   *Correct hardening, but insufficient:* if the sync holds the write lock, `BEGIN IMMEDIATE` just
   blocks/fails to acquire.
2. `de12d6679` — `apps/pos/src/lib/db/busyRetry.ts` + wired into `getDatabase()`
   (`wrapDatabaseWithBusyRetry`): wraps the singleton's `execute`/`select` to retry on the
   `database is locked` family (code 5 + 517, matched as STRING) with capped backoff (~8 retries /
   ~5s) — an app-layer `busy_timeout`. *Did NOT fix it:* the lock is held longer than the retry
   budget (persistent starvation), so retrying a single contended statement cannot win.

**Conclusion:** retry/lock-patience is the wrong layer. The fix must remove the contention
(serialize writers and/or get the bulk sync off the sale's critical path), not wait it out.

These two commits are harmless hardening; the new design may keep, refactor, or remove them.

NOTE: two *independent, correct* fixes from the same session are PR #191 candidates and should land
regardless of this review — the migration string-error guard (`5c9e3f95e`) and the
`products.stock_quantity` NOT-NULL default (`86e40591e`). They are unrelated to the lock.

---

## Architectural options to evaluate (not prescriptive — brainstorm these and others)

- **Single app-level write queue / async mutex:** funnel ALL writes (sync pulls, receipt tx, Z, cash
  drawer, overrides) through one serialized queue so two writers never overlap. Reads stay parallel
  (WAL). Likely the highest-leverage change.
- **Pool size 1 for the SQLite connection** (force single connection so SQLite serializes naturally
  and "database is locked" cannot occur between our own calls). Requires checking what
  `tauri-plugin-sql` exposes (Rust connect-options / pool config) — may need a Rust-side change.
- **Get bulk sync off the checkout critical path:** suspend/defer the scheduler and any foreground
  pull while a sale is in progress; chunk bulk pulls into small short transactions that yield the
  write lock frequently; never run a multi-thousand-row pull as one transaction.
- **Make the fiscal append lean & instant on the click path:** measure where the seconds go
  (hash/canonical-byte serialization vs. lock waits vs. multi-table writes). Consider doing the
  minimal durable write synchronously and deferring projections.
- **Effective `busy_timeout`:** if a single-connection or Rust connect-option path is taken, set a
  real busy_timeout there instead of the JS retry shim.
- **WAL checkpoint policy:** ensure checkpoints aren't taking exclusive locks during sales.

Evaluate each against: latency (snappy sale), correctness under concurrency, and fiscal integrity.

---

## Hard constraints (do not violate)

- **Fiscal chain is sacred.** `fiscal_events` is an append-only, SHA-256-chained ledger; events are
  immutable; the hash chain is strictly sequential (each `previous_hash` = prior `current_hash`).
  Any serialization change must preserve a correct, gap-free per-terminal chain. The local DB
  currently holds 1 SALE_RECEIPT (seq 1) from the first successful sale — the chain head matters.
- **Offline-first is genuine.** The sale must complete with no server. (Memory:
  `feedback_pos_offline_first_priority` — fail-closed on reachable-but-erroring server; only true
  offline downgrades.)
- **Precision contract** (CLAUDE.md rule 19): never let a float touch money/quantity; strings + the
  scale helpers. The receipt path already follows this — keep it.
- **TDD is mandatory** (`superpowers:test-driven-development`). The Tauri plugin rejects with
  STRINGS, not Errors — tests using `better-sqlite3` (throws Errors) mask this whole class of bug.
  See `apps/pos/src/lib/db/__tests__/migrations.tauri-string-errors.test.ts` for the adapter pattern
  that reproduces the string boundary; reuse it. A real **multi-connection concurrency test**
  (two connections, one holds a write while the other runs the receipt path) is what was missing and
  is needed to prove any fix.
- **Do NOT run the full PHPUnit suite** (crashes the laptop) — this work is frontend/Tauri (vitest)
  anyway; scope backend tests with `--filter` if touched.

---

## Key files (with line anchors)

| File | Role |
|---|---|
| `apps/pos/src/lib/db.ts` | `getDatabase()` singleton, `Database.load`, WAL pragma, `wrapDatabaseWithBusyRetry` wiring, `runStuckReceiptRecovery` |
| `apps/pos/src/lib/db/busyRetry.ts` | app-layer lock retry (the insufficient shim) |
| `apps/pos/src/lib/offline/receiptService.ts` | `createOfflineReceipt`; idempotency check (~240), `getTerminalState` read (262), `BEGIN IMMEDIATE` tx (~391), tx body catch (532), ROLLBACK catch (542) |
| `apps/pos/src/lib/sync/syncScheduler.ts` | 60s→5min background scheduler (`setInterval`) |
| `apps/pos/src/lib/sync/syncService.ts` | `pushOfflineReceipts` (321, `response.results` bug), `pullProductsForeground` bulk write (753) |
| `apps/pos/src/lib/db/repositories/*` | product/location-stock/receipt repositories (the bulk writers) |
| `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` | hash-chain append, canonical bytes (TN matricule regex ~1292, normalization ~3060) |
| `apps/pos/src/stores/paymentStore.ts` | `:942` checkout cash orchestration |
| `apps/pos/src/pages/HomePage.tsx` | `:1099` `handleCashConfirm` → `processCashCheckout` |

(Other deferred-`BEGIN` write txs that share the contention risk: `zReportService`,
`accountPaymentService`, `accountChargeService`, `posOverrideAuthoring`, `cashDrawerApproval`,
`ChainRecoveryService`.)

---

## Also fix (separate, smaller)

`pushOfflineReceipts` crashes at `syncService.ts:321` — `undefined is not an object
(evaluating 'response.results')`: the server's fiscal-event-push response shape doesn't match what
the client expects. Receipts seal locally but never sync up until this is fixed. Independent of the
lock; fold into the same session.

---

## How to run / verify

- **Demo stack:** `docker compose -f docker-compose.demo.yml up -d --build --wait` (URL
  http://localhost:8088, owner@pharmabio.tn/password). Seed recipe + caveats in
  `project_parapharmacy_tunisia_demo.md`.
- **POS:** `apps/pos/.env.local` has `VITE_API_URL=http://localhost:8088`; `cd apps/pos && pnpm tauri
  dev`; claim terminal `POS01` at the Tunis store. Local DB lives at
  `~/Library/Application Support/com.syneriva.izipos/izipos-<companyId>.db` (+ `-wal`, `-shm`).
- **The real verification is a live Tauri checkout completing** while a sync runs — plus a vitest
  concurrency test that reproduces the contention deterministically.
- Unit tests: `cd apps/pos && pnpm exec vitest run <path>`; `pnpm exec tsc --noEmit`; `pnpm exec
  eslint <files>`.
```
