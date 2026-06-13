# POS Offline SQLite Single-Writer Architecture — Design Spec

**Date:** 2026-06-12
**Branch:** `feat/parapharmacy-tunisia-demo` (worktree `apps/erp.db-per-tenant`)
**Handover:** `docs/superpowers/handovers/2026-06-12-pos-offline-sqlite-architecture-review.md`
**Status:** Approved direction from owner ("stop patching, review the architecture"); executed autonomously per handover mandate.

---

## 1. Problem

The Tauri POS cannot reliably complete a cash sale: the receipt write path fails with
`error returned from database: (code: 5) database is locked`, and even successful
checkouts take seconds. Two app-layer fixes (`BEGIN IMMEDIATE`, busy-retry shim) did
not fix it.

## 2. Root cause (verified, sharper than the handover's)

`@tauri-apps/plugin-sql` (tauri-plugin-sql 2.3.2) opens the SQLite file via sqlx
`Pool::connect` — **default pool of up to 10 connections** (`sqlx-core-0.8.6
pool/options.rs:151`). Every JS `db.execute` / `db.select` borrows **any idle
connection**. Verified in `sqlx-core-0.8.6 pool/connection.rs::return_to_pool`:
a connection returned to the pool is only **pinged** — an open transaction is
**NOT rolled back** and the connection re-enters the idle queue still in-tx.

Consequences:

1. **Self-deadlock.** `BEGIN IMMEDIATE` lands on connection A (takes the WAL write
   lock) and A returns to the pool in-tx. The next statement of the "same"
   transaction (the fiscal append INSERT) can land on connection B → it executes
   *outside* the transaction and blocks on A's write lock → after sqlx's
   per-connection 5s `busy_timeout` (default IS set — `sqlx-sqlite-0.8.6
   options/mod.rs:201` — the handover's "not happening" was a misread: it waits 5s
   then fails) → `(code: 5) database is locked`. No retry budget can win: the lock
   belongs to the retrier's own orphaned `BEGIN`.
2. **Transaction poisoning.** A concurrent sync upsert can borrow connection A and
   silently **join the open fiscal transaction**. The eventual COMMIT/ROLLBACK
   applies to a mixed set of statements from unrelated flows — a fiscal-integrity
   hazard (chain rows could commit with foreign sync writes, or sync writes could
   vanish in a fiscal ROLLBACK).
3. **Latency.** Every lock collision costs up to 5s (busy_timeout) plus up to ~5s
   of JS busy-retry — the observed "checkout takes seconds".
4. **Why the first sale worked:** a cold pool has one connection, so all statements
   share it and transactions are accidentally sound. Concurrent UI reads/sync grow
   the pool, after which statement→connection assignment is effectively random.

The two prior fixes were correct hardening but target the wrong layer: the defect is
that **multi-statement transactions cannot be expressed through a connection pool
whose acquisition the app does not control.**

## 3. Hard constraints (inherited)

- Fiscal chain: append-only, strictly sequential per-terminal SHA-256 chain; the
  chain read-modify-write must be serialized; no gaps; events immutable.
- Offline-first: sale completes with no server; fail-closed semantics unchanged.
- Precision contract: strings + bc helpers on money/quantity (unchanged).
- Tauri SQL boundary rejects with **strings**, not Errors — tests must exercise
  this (better-sqlite3 throws Errors and masks the class).
- No full PHPUnit suite runs.

## 4. Chosen architecture

**A single Rust-owned writer connection + a JS priority write gate. The plugin
pool is demoted to reads-only.**

### 4.1 Rust: `db_writer` module (new, in `apps/pos/src-tauri/src/`)

- Manages **one long-lived sqlx `SqliteConnection`** (NOT a pool) per company DB,
  opened with explicit `SqliteConnectOptions`:
  `create_if_missing(true)`, `journal_mode(Wal)`, `synchronous(Normal)`,
  `busy_timeout(5s)`, `foreign_keys(true)`. Path resolution mirrors the plugin
  (`app_config_dir` + db file name) so it opens the SAME file.
- Tauri commands (async, serialized by a `tokio::sync::Mutex<SqliteConnection>`):
  - `writer_open(db: String)` — open/replace the connection (idempotent per name;
    closes previous on company switch).
  - `writer_execute(db, sql, params) -> { rowsAffected, lastInsertId }`
  - `writer_select(db, sql, params) -> Vec<JsonMap>`
  - `writer_close(db)`
- Param binding + row decoding mirror tauri-plugin-sql's JsonValue handling
  (null/bool/number/string/object→json). Errors are returned as plain strings —
  intentionally identical to the plugin's failure surface so the existing
  string-error handling and tests stay truthful.
- The mutex serializes *statements*; **logical exclusivity for transactions is the
  JS gate's job** (a tx is one queue job; nothing else can submit between its
  statements because the gate runs one job at a time).

### 4.2 JS: `writeGate` (new, `apps/pos/src/lib/db/writeGate.ts`)

- A single in-process async queue, **two priority lanes**:
  - `fiscal` (high): receipt tx, Z-report, cash-drawer, account payment/charge,
    override authoring, chain recovery, voucher balance updates.
  - `sync` (low): bulk catalog/stock upserts, sync mark-status writes, metadata.
- Jobs run strictly one-at-a-time (the single writer connection makes parallelism
  meaningless); when the running job finishes, the next job is taken from `fiscal`
  first, FIFO within a lane.
- API:
  - `enqueueWrite<T>(lane, job: (w: SqlSurface) => Promise<T>): Promise<T>` —
    for single statements / short statement groups that need no atomicity.
  - `withWriteTransaction<T>(lane, fn: (tx: SqlSurface) => Promise<T>): Promise<T>`
    — runs `BEGIN IMMEDIATE` … `fn` … `COMMIT` as ONE job; `ROLLBACK` in a
    `finally`-guarded catch (string-safe). The whole transaction is exclusive by
    construction; the fiscal chain read-modify-write is serialized.
- The writer handle passed to jobs implements the existing **`SqlSurface`**
  interface (`FiscalEventEngine.ts` already defines it: `{ execute, select }`),
  so `FiscalEventEngine.append`, repositories, and migrations work unchanged.
- A job that throws never wedges the gate (errors propagate to the caller;
  the queue advances).

### 4.3 Read path (unchanged plumbing, new invariant)

- `getDatabase()` keeps returning the plugin pool **for reads only**. Under WAL,
  readers never block on the writer. Because **no `BEGIN` ever flows through the
  plugin anymore**, no pooled connection ever carries transaction state — the
  poisoning class is eliminated, not mitigated.
- The `busyRetry` wrapper stays on the pool (reads can still rarely hit BUSY on
  checkpoint edges); it no longer guards writes.

### 4.4 Write-path migration (the sweep)

Every write moves to the gate. Concretely:

- **Boot** (`db.ts`): `writer_open` first; WAL pragma is owned by the writer's
  connect options; `runMigrations` and `runStuckReceiptRecovery` execute through
  `enqueueWrite('fiscal', …)` (boot is single-flight, so ordering is preserved).
- **Receipt path** (`receiptService.ts`): replace the raw `BEGIN IMMEDIATE`/
  `COMMIT`/`ROLLBACK` block with `withWriteTransaction('fiscal', …)`. Pre-tx
  reads (idempotency check, `getTerminalState` metadata read) stay on the read
  pool. The voucher balance updates stay inside the tx.
- **Other fiscal writers** (`zReportService`, `accountPaymentService`,
  `accountChargeService`, `posOverrideAuthoring`, `cashDrawerApproval`,
  `zSessionAuthoring`, `ChainRecoveryService`, `offlineReceiptRepository`'s tx
  helpers): same `withWriteTransaction('fiscal', …)` replacement for their
  deferred-`BEGIN` blocks.
- **Sync writers** (`syncService.ts` + repositories): each existing batch
  statement (`upsertProducts` 50-row batches, stock upserts, tombstones,
  mark-status updates, sync metadata) becomes a `sync`-lane job per batch — so a
  checkout waits at most ONE in-flight 50-row batch (~ms), never a whole pull.
  `replaceIncoming`'s zero-then-upsert pair runs as one short `sync`-lane
  transaction (it is small and its non-atomic window would otherwise be visible
  to stock reads).
- Repositories keep their `db` parameter (typed `SqlSurface`-compatible); the
  CALLER decides which handle to pass (read pool vs writer-inside-job). No
  repository grows a dependency on the gate.

### 4.5 Latency design

- Checkout tx = ~8 IPC statements on an uncontended connection → target
  **< 150 ms** end-to-end for `createOfflineReceipt` (vs 5–10s worst case today).
- `fiscal` lane preempts queued `sync` chunks; bulk pulls no longer sit on the
  click path at all.
- Add timing instrumentation: `console.info('[POS][perf][receipt]', { ms })`
  around the gate job so the live Tauri console shows the number.

### 4.6 Separate bug: `pushOfflineReceipts` crash

`POST /pos/sync/fiscal-events` returns a **top-level** `{ results: [...] }`
(`FiscalEventIngestionController.php:141`) — deliberately not the `{data, meta}`
envelope. The client calls `apiPost`, which unwraps `json.data` → `undefined` →
`response.results` throws. Fix: add `apiPostRaw` (mirror of the existing
`apiGetRaw`) and use it in `pushOfflineReceipts`. Error paths are unaffected
(the controller's `{error: {...}}` shapes already match `ApiRequestError`
parsing). No server change; no realignment-log entry needed.

## 5. Alternatives rejected

| Option | Why rejected |
|---|---|
| JS-only global write queue on the existing pool | Cannot pin a transaction's statements to one physical connection — the unsoundness (self-deadlock + poisoning) remains. |
| Pool-size-1 via plugin fork/patch | Serializes READS behind bulk writes (defeats the snappiness goal); requires maintaining a fork; still string-only errors. |
| Replace tauri-plugin-sql entirely (reads too) | Larger blast radius for zero benefit: pooled WAL reads are safe once no tx state ever enters the pool. |
| Keep retrying harder (longer budgets) | Retrying statement B can never beat its own transaction's lock held by connection A. Structurally hopeless. |

## 6. Testing strategy (TDD)

1. **Pool-simulation adapter (new test harness):** N real better-sqlite3
   connections to one file behind a round-robin `execute`/`select` facade that
   **rejects with strings** (extends the existing
   `migrations.tauri-string-errors.test.ts` adapter pattern). This
   deterministically reproduces the self-deadlock: a `BEGIN IMMEDIATE` + INSERT
   sequence through the facade fails with `database is locked` — proving the
   defect class, and pinning that the new write path never uses a pooled facade.
2. **writeGate unit tests:** lane priority (fiscal preempts queued sync), FIFO
   within lane, one-job-at-a-time exclusivity, ROLLBACK on body throw (string
   errors), thrown jobs don't wedge the queue, tx result propagation.
3. **Multi-connection concurrency test (the missing proof):** real SQLite file,
   connection W (writer) + connection P (simulated leftover pool reader);
   bulk sync chunks enqueued on the `sync` lane while a receipt transaction runs
   on the `fiscal` lane — assert: receipt completes, chain rows are gap-free and
   sequential, sync rows all land, and a fiscal job enqueued after 10 queued sync
   chunks runs before chunk 2.
4. **receiptService integration:** `createOfflineReceipt` through the gate against
   the string-rejecting adapter — success path + mid-tx failure path (rollback,
   no partial rows, chain head unchanged).
5. **`apiPostRaw` test:** `pushOfflineReceipts` with a fetch mock returning the
   real controller shape `{ results: [...] }` (no envelope) — events marked
   synced; regression test that `apiPost` unwrapping is NOT used.
6. **Rust:** param-binding/decoding unit tests in `db_writer.rs` (cargo test,
   no Tauri runtime needed for the binding helpers).
7. **Live verification:** Tauri checkout completing during a forced foreground
   bulk pull on the Tunisia demo stack (the handover's acceptance gate), with the
   perf log showing the receipt tx duration.

## 7. Out of scope

- Server-side changes (none needed).
- Deferring fiscal projections off the click path — unnecessary once contention
  is gone (~8 statements ≈ tens of ms); revisit only if the live perf log
  disagrees.
- Scheduler pause-during-checkout — superseded by lane priority.
- The two independent PR #191 candidates (migration string-error guard,
  `stock_quantity` NOT-NULL default) — already commits `5c9e3f95e`/`86e40591e`.

## 8. Risks & mitigations

- **JS dies mid-transaction → writer connection wedged in-tx.** `withWriteTransaction`
  ROLLBACKs in a finally-guarded catch; a hard webview crash restarts the app and
  the Rust connection with it. Residual risk accepted (same as today's, minus the
  pool multiplying it).
- **Two writers during rollout (missed write call site still on the pool).** The
  sweep is grep-driven (`db.execute(` on known write tables + all `BEGIN` sites);
  an ESLint guard is NOT added in this pass (tracked as follow-up); the writer's
  5s busy_timeout keeps any straggler correct (briefly slow, not broken).
- **Rust layer bugs (binding/decoding).** Mirrored from the plugin's own code +
  dedicated cargo tests + the vitest string-boundary tests.
