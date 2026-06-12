# POS Offline SQLite Single-Writer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the IziPOS Tauri offline cash sale complete reliably (and in tens of ms) under concurrent sync, by serializing all SQLite writes through one app-level gate and running multi-statement fiscal transactions on a single Rust-owned connection — plus fix the `pushOfflineReceipts` `response.results` crash.

**Architecture:** Per spec `docs/superpowers/specs/2026-06-12-pos-offline-single-writer-design.md`. The tauri-plugin-sql pool is unsound for JS-issued `BEGIN…COMMIT` (statements split across pooled connections → self-deadlock + transaction poisoning). Fix: (1) a JS `writeGate` — one job at a time, `fiscal` lane preempts `sync` lane; (2) a Rust `db_writer` Tauri command set owning ONE `SqliteConnection` (WAL, busy_timeout 5s) used for all multi-statement transactions; (3) sync bulk writes serialized per-statement through the gate (they may keep using the pool handle — single autocommit statements are safe once the gate guarantees no concurrent fiscal tx); (4) reads stay on the plugin pool (WAL ⇒ never blocked).

**Tech Stack:** React/TS (Vite, vitest, better-sqlite3 test adapters), Tauri 2 + Rust (sqlx 0.8, tokio), strict TS (no `any` — use `unknown` + casts mirroring existing `asDatabase()` precedent).

**Working directory:** worktree `/Users/houssamr/Projects/syneriva/apps/erp.db-per-tenant`, branch `feat/parapharmacy-tunisia-demo` (stays on this branch — live verification needs the Tunisia demo stack). All `pnpm` commands run from `apps/pos/`; all `cargo` commands from `apps/pos/src-tauri/`.

**Test commands:**
- `pnpm exec vitest run <path>` (NEVER the full PHPUnit suite; backend untouched)
- `pnpm exec tsc --noEmit`
- `pnpm exec eslint <files>`
- `cargo check` / `cargo test db_writer` (from `src-tauri/`)

**Key invariants (do not violate):**
- Fiscal chain: append-only, per-terminal sequential hash chain; a fiscal transaction's read-modify-write must be exclusive — guaranteed here by "a transaction is ONE gate job on ONE physical connection".
- The Tauri SQL boundary rejects with **strings**, not `Error`s — every new error-handling path must be string-safe (no bare `instanceof Error` guards), and tests must exercise string rejections.
- A `withWriteTransaction` body must use ONLY the `tx` handle it is given and must NEVER call `enqueueWrite`/`withWriteTransaction` (the gate runs one job at a time — nested enqueue+await deadlocks). Same for jobs passed to `enqueueWrite`.
- Money/quantity stay strings (precision contract) — no changes to value handling anywhere in this plan.

---

## File map

| File | Action | Responsibility |
|---|---|---|
| `apps/pos/src/lib/db/writeGate.ts` | Create | Priority write queue + ambient writer + `withWriteTransaction` + `gatedSyncDb` |
| `apps/pos/src/lib/db/__tests__/writeGate.test.ts` | Create | Gate unit tests |
| `apps/pos/src/lib/db/__tests__/helpers/sqliteTestAdapter.ts` | Modify | Accept optional file path (for multi-connection tests) |
| `apps/pos/src/lib/db/__tests__/helpers/poolSimAdapter.ts` | Create | N-connection round-robin facade simulating the Tauri plugin pool |
| `apps/pos/src/lib/db/__tests__/poolTransactionUnsoundness.test.ts` | Create | Defect-class reproduction + gate counter-proof |
| `apps/pos/src-tauri/src/db_writer.rs` | Create | Single-connection writer commands |
| `apps/pos/src-tauri/src/lib.rs` | Modify | Register state + commands |
| `apps/pos/src-tauri/Cargo.toml` | Modify | Direct `sqlx` dep + tokio `macros` for tests |
| `apps/pos/src/lib/db/dbWriter.ts` | Create | JS invoke wrapper exposing `SqlSurface` |
| `apps/pos/src/lib/db.ts` | Modify | Boot: open writer, set gate writer, migrations via writer |
| `apps/pos/src/lib/offline/receiptService.ts` | Modify | Receipt tx → `withWriteTransaction('fiscal')` + perf log |
| `apps/pos/src/lib/offline/zReportService.ts` | Modify | Z tx → gate |
| `apps/pos/src/lib/fiscal/zSessionAuthoring.ts` | Modify | Session-open tx → gate |
| `apps/pos/src/lib/fiscal/ChainRecoveryService.ts` | Modify | Break/restart tx → gate |
| `apps/pos/src/lib/offline/accountPaymentService.ts` | Modify | tx → gate |
| `apps/pos/src/lib/accountCharge/accountChargeService.ts` | Modify | tx → gate |
| `apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts` | Modify | tx → gate |
| `apps/pos/src/lib/operatorApproval/cashDrawerApproval.ts` | Modify | tx → gate |
| `apps/pos/src/lib/sync/syncService.ts` | Modify | `gatedSyncDb` at every pull/push entry; `replaceIncoming` in short tx; `apiPostRaw` |
| `apps/pos/src/lib/api.ts` | Modify | Add `apiPostRaw` |
| `apps/pos/src/lib/db/__tests__/concurrentCheckout.integration.test.ts` | Create | The missing multi-connection concurrency proof |
| various `__tests__` files | Modify | `setWriter(adapter)` in setups for swept services |

---

### Task 1: `writeGate.ts` (TDD)

**Files:**
- Create: `apps/pos/src/lib/db/__tests__/writeGate.test.ts`
- Create: `apps/pos/src/lib/db/writeGate.ts`

- [ ] **Step 1: Write the failing tests**

```ts
// apps/pos/src/lib/db/__tests__/writeGate.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import {
  enqueueWrite,
  withWriteTransaction,
  setWriter,
  gatedSyncDb,
  __resetWriteGateForTesting,
} from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

function deferred(): { promise: Promise<void>; resolve: () => void } {
  let resolve!: () => void;
  const promise = new Promise<void>((r) => { resolve = r; });
  return { promise, resolve };
}

function recordingWriter(log: string[]): SqlSurface {
  return {
    execute: async (sql: string) => { log.push(sql); return { rowsAffected: 0 }; },
    select: async <T>() => [] as unknown as T,
  };
}

beforeEach(() => {
  __resetWriteGateForTesting();
});

describe('enqueueWrite', () => {
  it('runs jobs one at a time, FIFO within a lane', async () => {
    const order: string[] = [];
    const gateA = deferred();
    const a = enqueueWrite('sync', async () => { order.push('a:start'); await gateA.promise; order.push('a:end'); });
    const b = enqueueWrite('sync', async () => { order.push('b'); });
    // b must not start while a is blocked
    await new Promise((r) => setTimeout(r, 10));
    expect(order).toEqual(['a:start']);
    gateA.resolve();
    await Promise.all([a, b]);
    expect(order).toEqual(['a:start', 'a:end', 'b']);
  });

  it('fiscal lane preempts queued sync jobs', async () => {
    const order: string[] = [];
    const gateA = deferred();
    const a = enqueueWrite('sync', async () => { order.push('syncA'); await gateA.promise; });
    const b = enqueueWrite('sync', async () => { order.push('syncB'); });
    const c = enqueueWrite('fiscal', async () => { order.push('fiscalC'); });
    gateA.resolve();
    await Promise.all([a, b, c]);
    expect(order).toEqual(['syncA', 'fiscalC', 'syncB']);
  });

  it('a throwing job rejects its caller and does not wedge the queue', async () => {
    const failing = enqueueWrite('sync', async () => { throw 'error returned from database: (code: 5) database is locked'; });
    await expect(failing).rejects.toMatch('database is locked');
    await expect(enqueueWrite('sync', async () => 'ok')).resolves.toBe('ok');
  });

  it('propagates results', async () => {
    await expect(enqueueWrite('fiscal', async () => 42)).resolves.toBe(42);
  });
});

describe('withWriteTransaction', () => {
  it('throws a clear error when no writer is set', async () => {
    await expect(withWriteTransaction('fiscal', async () => undefined))
      .rejects.toThrow('[writeGate] writer not initialized');
  });

  it('wraps the body in BEGIN IMMEDIATE / COMMIT on the writer', async () => {
    const log: string[] = [];
    setWriter(recordingWriter(log));
    const result = await withWriteTransaction('fiscal', async (tx) => {
      await tx.execute('INSERT INTO t VALUES (1)');
      return 'done';
    });
    expect(result).toBe('done');
    expect(log).toEqual(['BEGIN IMMEDIATE TRANSACTION', 'INSERT INTO t VALUES (1)', 'COMMIT']);
  });

  it('ROLLBACKs and rethrows the ORIGINAL error when the body throws a STRING', async () => {
    const log: string[] = [];
    setWriter(recordingWriter(log));
    await expect(withWriteTransaction('fiscal', async () => {
      throw 'error returned from database: (code: 5) database is locked';
    })).rejects.toMatch('database is locked');
    expect(log).toEqual(['BEGIN IMMEDIATE TRANSACTION', 'ROLLBACK']);
  });

  it('a failing ROLLBACK does not mask the original error', async () => {
    const writer: SqlSurface = {
      execute: async (sql: string) => {
        if (sql === 'ROLLBACK') throw 'rollback failed';
        return { rowsAffected: 0 };
      },
      select: async <T>() => [] as unknown as T,
    };
    setWriter(writer);
    await expect(withWriteTransaction('fiscal', async () => { throw new Error('body failed'); }))
      .rejects.toThrow('body failed');
  });

  it('is exclusive: nothing interleaves between BEGIN and COMMIT', async () => {
    const log: string[] = [];
    setWriter(recordingWriter(log));
    const gate = deferred();
    const tx = withWriteTransaction('fiscal', async (w) => {
      await w.execute('S1');
      await gate.promise;
      await w.execute('S2');
    });
    const intruder = enqueueWrite('fiscal', async () => { log.push('INTRUDER'); });
    await new Promise((r) => setTimeout(r, 10));
    gate.resolve();
    await Promise.all([tx, intruder]);
    expect(log).toEqual(['BEGIN IMMEDIATE TRANSACTION', 'S1', 'S2', 'COMMIT', 'INTRUDER']);
  });
});

describe('gatedSyncDb', () => {
  it('routes execute through the sync lane against the ORIGINAL handle, select passthrough', async () => {
    const calls: string[] = [];
    const db = {
      execute: async (sql: string) => { calls.push(`exec:${sql}`); return { rowsAffected: 1 }; },
      select: async <T>(sql: string) => { calls.push(`select:${sql}`); return [] as unknown as T; },
    };
    const sdb = gatedSyncDb(db as never);
    await (sdb as unknown as SqlSurface).select('SELECT 1');
    const res = await (sdb as unknown as SqlSurface).execute('INSERT 1');
    expect(res.rowsAffected).toBe(1);
    expect(calls).toEqual(['select:SELECT 1', 'exec:INSERT 1']);
  });

  it('is idempotent — wrapping twice returns the same wrapper (no nested-enqueue deadlock)', () => {
    const db = { execute: async () => ({ rowsAffected: 0 }), select: async <T>() => [] as unknown as T };
    const once = gatedSyncDb(db as never);
    expect(gatedSyncDb(once)).toBe(once);
  });

  it('gated execute waits behind a running fiscal transaction', async () => {
    const order: string[] = [];
    setWriter(recordingWriter(order));
    const gate = deferred();
    const tx = withWriteTransaction('fiscal', async (w) => { await w.execute('TX'); await gate.promise; });
    const db = { execute: async (sql: string) => { order.push(sql); return { rowsAffected: 0 }; }, select: async <T>() => [] as unknown as T };
    const sdb = gatedSyncDb(db as never);
    const write = (sdb as unknown as SqlSurface).execute('SYNC-WRITE');
    await new Promise((r) => setTimeout(r, 10));
    expect(order).not.toContain('SYNC-WRITE');
    gate.resolve();
    await Promise.all([tx, write]);
    expect(order[order.length - 1]).toBe('SYNC-WRITE');
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/pos && pnpm exec vitest run src/lib/db/__tests__/writeGate.test.ts`
Expected: FAIL — `Cannot find module '@/lib/db/writeGate'`

- [ ] **Step 3: Implement `writeGate.ts`**

```ts
// apps/pos/src/lib/db/writeGate.ts
import type Database from '@tauri-apps/plugin-sql';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

/**
 * Single app-level write gate for the offline SQLite DB.
 *
 * WHY: the Tauri SQL plugin wraps a SQLx connection POOL — every
 * execute/select borrows a different physical connection, and sqlx returns
 * connections to the idle queue WITHOUT rolling back open transactions. A
 * JS-issued `BEGIN … COMMIT` therefore splits across connections: the BEGIN
 * holds the write lock on one connection while the next statement blocks on
 * another → `(code: 5) database is locked` self-deadlock — and unrelated
 * writes can land on the in-tx connection and silently join a fiscal
 * transaction. See the 2026-06-12 single-writer design spec.
 *
 * CONTRACT:
 *   - Exactly one job runs at a time. The `fiscal` lane preempts queued
 *     `sync` jobs; FIFO within a lane.
 *   - A transaction is ONE job on the Rust-owned single writer connection
 *     (`setWriter` at boot) — exclusive by construction.
 *   - Job bodies must NEVER call enqueueWrite/withWriteTransaction (the gate
 *     runs one job at a time; a nested enqueue+await deadlocks).
 *   - Errors from the Tauri boundary are STRINGS — nothing here assumes
 *     `instanceof Error`.
 */

export type WriteLane = 'fiscal' | 'sync';

interface QueuedJob {
  run: () => Promise<void>;
}

let writer: SqlSurface | null = null;
const lanes: Record<WriteLane, QueuedJob[]> = { fiscal: [], sync: [] };
let pumping = false;

/** Boot-time injection of the Rust single-connection writer (tests inject adapters). */
export function setWriter(surface: SqlSurface | null): void {
  writer = surface;
}

function getWriterOrThrow(): SqlSurface {
  if (writer === null) {
    throw new Error('[writeGate] writer not initialized — call setWriter() during getDatabase() boot');
  }
  return writer;
}

/**
 * Serialize a write job. The job closes over its own DB handle (pool handle
 * for single-statement sync writes is fine — single autocommit statements
 * are sound once the gate guarantees no concurrent fiscal transaction).
 */
export function enqueueWrite<T>(lane: WriteLane, job: () => Promise<T>): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    lanes[lane].push({
      run: async () => {
        try {
          resolve(await job());
        } catch (error) {
          reject(error as Error);
        }
      },
    });
    void pump();
  });
}

async function pump(): Promise<void> {
  if (pumping) return;
  pumping = true;
  try {
    for (;;) {
      const next = lanes.fiscal.shift() ?? lanes.sync.shift();
      if (!next) break;
      await next.run(); // never throws — run() resolves/rejects the caller's promise
    }
  } finally {
    pumping = false;
  }
}

/**
 * Run `fn` inside BEGIN IMMEDIATE … COMMIT on the single writer connection,
 * as one exclusive gate job. ROLLBACK on any failure (string-safe); the
 * body's original error is always the one rethrown.
 */
export function withWriteTransaction<T>(
  lane: WriteLane,
  fn: (tx: SqlSurface) => Promise<T>,
): Promise<T> {
  return enqueueWrite(lane, async () => {
    const w = getWriterOrThrow();
    await w.execute('BEGIN IMMEDIATE TRANSACTION');
    try {
      const result = await fn(w);
      await w.execute('COMMIT');
      return result;
    } catch (error) {
      try {
        await w.execute('ROLLBACK');
      } catch (rollbackError) {
        console.error('[writeGate] ROLLBACK failed after tx error — connection may be in bad state', {
          rollbackError: String(rollbackError),
        });
      }
      throw error;
    }
  });
}

const syncWrapped = new WeakSet<object>();

/**
 * Wrap a DB handle so every `execute` is serialized through the sync lane
 * (per-statement granularity — a queued fiscal tx waits at most ONE
 * statement). `select` passes through (reads never block under WAL).
 * Idempotent: re-wrapping a wrapper returns it unchanged (a double wrap
 * would nest enqueues and deadlock the gate).
 */
export function gatedSyncDb(db: Database): Database {
  if (syncWrapped.has(db as unknown as object)) return db;
  const surface = db as unknown as SqlSurface;
  const wrapped: SqlSurface = {
    execute: (sql: string, params?: unknown[]) =>
      enqueueWrite('sync', () => surface.execute(sql, params)),
    select: <T>(sql: string, params?: unknown[]) => surface.select<T>(sql, params),
  };
  syncWrapped.add(wrapped);
  return wrapped as unknown as Database;
}

export function __resetWriteGateForTesting(): void {
  writer = null;
  lanes.fiscal.length = 0;
  lanes.sync.length = 0;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/pos && pnpm exec vitest run src/lib/db/__tests__/writeGate.test.ts`
Expected: PASS (all)

- [ ] **Step 5: Typecheck + lint, then commit**

```bash
cd apps/pos && pnpm exec tsc --noEmit && pnpm exec eslint src/lib/db/writeGate.ts src/lib/db/__tests__/writeGate.test.ts
git add src/lib/db/writeGate.ts src/lib/db/__tests__/writeGate.test.ts
git commit -m "feat(pos): app-level priority write gate for offline SQLite (fiscal preempts sync)"
```

---

### Task 2: Pool-unsoundness reproduction test (defect-class pin)

**Files:**
- Modify: `apps/pos/src/lib/db/__tests__/helpers/sqliteTestAdapter.ts` (constructor file path)
- Create: `apps/pos/src/lib/db/__tests__/helpers/poolSimAdapter.ts`
- Create: `apps/pos/src/lib/db/__tests__/poolTransactionUnsoundness.test.ts`

- [ ] **Step 1: Let `SqliteTestAdapter` open a file (back-compat default `':memory:'`)**

In `sqliteTestAdapter.ts`, change the constructor:

```ts
  constructor(filename: string = ':memory:') {
    this.inner = new BetterSqlite3(filename);
  }
```

- [ ] **Step 2: Create the pool-simulation helper**

```ts
// apps/pos/src/lib/db/__tests__/helpers/poolSimAdapter.ts
import { SqliteTestAdapter } from './sqliteTestAdapter';

/**
 * Simulates the `@tauri-apps/plugin-sql` failure surface: a POOL of physical
 * SQLite connections where every execute/select borrows the NEXT connection
 * (round-robin — sqlx's idle queue under concurrency), and every error is
 * rejected as a plain STRING (the Tauri invoke boundary).
 *
 * `busy_timeout` is 0 so lock collisions fail immediately and the test is
 * deterministic (production waits 5s then fails the same way).
 */
export class PoolSimAdapter {
  private readonly conns: SqliteTestAdapter[];
  private next = 0;

  constructor(filename: string, poolSize: number) {
    this.conns = Array.from({ length: poolSize }, () => {
      const c = new SqliteTestAdapter(filename);
      c.inner.pragma('journal_mode = WAL');
      c.inner.pragma('busy_timeout = 0');
      return c;
    });
  }

  private acquire(): SqliteTestAdapter {
    const c = this.conns[this.next % this.conns.length]!;
    this.next++;
    return c;
  }

  async execute(sql: string, params?: unknown[]): Promise<{ rowsAffected: number; lastInsertId?: number }> {
    try {
      return await this.acquire().execute(sql, params);
    } catch (e) {
      throw `error returned from database: (code: 5) ${(e as Error).message}`;
    }
  }

  async select<T>(sql: string, params?: unknown[]): Promise<T> {
    try {
      return await this.acquire().select<T>(sql, params);
    } catch (e) {
      throw `error returned from database: (code: 5) ${(e as Error).message}`;
    }
  }

  close(): void {
    for (const c of this.conns) c.close();
  }
}
```

- [ ] **Step 3: Write the reproduction + counter-proof test**

```ts
// apps/pos/src/lib/db/__tests__/poolTransactionUnsoundness.test.ts
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { PoolSimAdapter } from './helpers/poolSimAdapter';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { setWriter, withWriteTransaction, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

/**
 * Pins the ROOT CAUSE of the 2026-06-12 checkout failure: a JS-issued
 * BEGIN…COMMIT through a pooled-connection facade splits its statements
 * across physical connections — the BEGIN IMMEDIATE holds the write lock on
 * connection 0 while the next statement runs on connection 1 and self-
 * deadlocks → `(code: 5) database is locked` (as a STRING). The writeGate +
 * single-writer-connection architecture is the counter-proof.
 *
 * If someone "simplifies" the write path back onto the plugin pool, this
 * test is the tripwire.
 */

let dir: string;
let file: string;

beforeEach(() => {
  __resetWriteGateForTesting();
  dir = mkdtempSync(join(tmpdir(), 'pos-pool-sim-'));
  file = join(dir, 'pool.db');
});

afterEach(() => {
  rmSync(dir, { recursive: true, force: true });
});

const RECEIPT_LIKE_TX = async (db: { execute: (sql: string, p?: unknown[]) => Promise<unknown> }): Promise<void> => {
  await db.execute('BEGIN IMMEDIATE TRANSACTION');
  await db.execute('INSERT INTO fiscal_like (id) VALUES ($1)', ['evt-1']);
  await db.execute('COMMIT');
};

describe('pooled-connection transaction unsoundness (defect reproduction)', () => {
  it('a BEGIN IMMEDIATE + INSERT through a round-robin pool self-deadlocks with a STRING "database is locked"', async () => {
    const bootstrap = new SqliteTestAdapter(file);
    await bootstrap.execute('CREATE TABLE fiscal_like (id TEXT PRIMARY KEY)');
    bootstrap.close();

    const pool = new PoolSimAdapter(file, 2);
    let caught: unknown = null;
    try {
      await RECEIPT_LIKE_TX(pool);
    } catch (e) {
      caught = e;
    }
    expect(typeof caught).toBe('string'); // the Tauri boundary rejects with strings
    expect(String(caught)).toContain('database is locked');
    pool.close();
  });

  it('the same transaction through writeGate + a single writer connection succeeds', async () => {
    const bootstrap = new SqliteTestAdapter(file);
    await bootstrap.execute('CREATE TABLE fiscal_like (id TEXT PRIMARY KEY)');
    bootstrap.close();

    const writerConn = new SqliteTestAdapter(file);
    writerConn.inner.pragma('journal_mode = WAL');
    setWriter(writerConn as unknown as SqlSurface);

    await withWriteTransaction('fiscal', async (tx) => {
      await tx.execute('INSERT INTO fiscal_like (id) VALUES ($1)', ['evt-1']);
    });

    const rows = await writerConn.select<Array<{ id: string }>>('SELECT id FROM fiscal_like');
    expect(rows).toEqual([{ id: 'evt-1' }]);
    writerConn.close();
  });
});
```

- [ ] **Step 4: Run**

Run: `cd apps/pos && pnpm exec vitest run src/lib/db/__tests__/poolTransactionUnsoundness.test.ts src/lib/db/__tests__/migrations.tauri-string-errors.test.ts`
Expected: PASS (both — the second confirms the adapter constructor change is back-compatible)

- [ ] **Step 5: Commit**

```bash
git add src/lib/db/__tests__/helpers/sqliteTestAdapter.ts src/lib/db/__tests__/helpers/poolSimAdapter.ts src/lib/db/__tests__/poolTransactionUnsoundness.test.ts
git commit -m "test(pos): pin pooled-connection transaction unsoundness + writeGate counter-proof"
```

---

### Task 3: Rust `db_writer` single-connection commands

**Files:**
- Modify: `apps/pos/src-tauri/Cargo.toml`
- Create: `apps/pos/src-tauri/src/db_writer.rs`
- Modify: `apps/pos/src-tauri/src/lib.rs`

- [ ] **Step 1: Add the direct sqlx dependency (version-unified with the plugin's 0.8.6)**

In `Cargo.toml` `[dependencies]` add:

```toml
sqlx = { version = "0.8", default-features = false, features = ["sqlite", "runtime-tokio-rustls", "json"] }
```

And add a dev-dependencies section (for `#[tokio::test]`):

```toml
[dev-dependencies]
tokio = { version = "1", features = ["macros", "rt"] }
```

- [ ] **Step 2: Create `db_writer.rs`**

```rust
// apps/pos/src-tauri/src/db_writer.rs
//! Single-connection SQLite writer.
//!
//! The `tauri-plugin-sql` pool is unsound for JS-issued multi-statement
//! transactions (statements split across pooled connections). All offline
//! writes that need transactional atomicity run on THIS one long-lived
//! connection, serialized by the JS write gate + the state mutex below.
//! Errors are returned as plain strings to match the plugin's failure
//! surface (the JS layer's string-error handling stays truthful).

use serde_json::Value as JsonValue;
use sqlx::sqlite::{SqliteConnectOptions, SqliteJournalMode, SqliteSynchronous};
use sqlx::{Column, ConnectOptions, Connection, Executor, Row, SqliteConnection, TypeInfo, ValueRef};
use std::collections::HashMap;
use std::time::Duration;
use tauri::{command, AppHandle, Manager, Runtime, State};
use tokio::sync::Mutex;

#[derive(Default)]
pub struct WriterState(pub Mutex<HashMap<String, SqliteConnection>>);

#[derive(serde::Serialize)]
pub struct ExecResult {
    #[serde(rename = "rowsAffected")]
    pub rows_affected: u64,
    #[serde(rename = "lastInsertId")]
    pub last_insert_id: i64,
}

fn bind_values<'q>(
    mut query: sqlx::query::Query<'q, sqlx::Sqlite, sqlx::sqlite::SqliteArguments<'q>>,
    values: Vec<JsonValue>,
) -> sqlx::query::Query<'q, sqlx::Sqlite, sqlx::sqlite::SqliteArguments<'q>> {
    // Mirrors tauri-plugin-sql's binding exactly (wrapper.rs) so behavior is
    // identical to the read pool: null, string, number-as-f64, json fallback.
    for value in values {
        if value.is_null() {
            query = query.bind(None::<JsonValue>);
        } else if value.is_string() {
            query = query.bind(value.as_str().unwrap().to_owned());
        } else if let Some(number) = value.as_number() {
            query = query.bind(number.as_f64().unwrap_or_default());
        } else {
            query = query.bind(value);
        }
    }
    query
}

fn value_to_json(v: sqlx::sqlite::SqliteValueRef<'_>) -> JsonValue {
    if v.is_null() {
        return JsonValue::Null;
    }
    // DATE/TIME/DATETIME columns store TEXT in this schema — decode as String
    // (the plugin decodes via the `time` crate then stringifies; same output).
    match v.type_info().name() {
        "REAL" => v.to_owned().try_decode::<f64>().map(JsonValue::from).unwrap_or(JsonValue::Null),
        "INTEGER" | "NUMERIC" => v
            .to_owned()
            .try_decode::<i64>()
            .map(|n| JsonValue::Number(n.into()))
            .unwrap_or(JsonValue::Null),
        "BOOLEAN" => v.to_owned().try_decode::<bool>().map(JsonValue::Bool).unwrap_or(JsonValue::Null),
        "BLOB" => v
            .to_owned()
            .try_decode::<Vec<u8>>()
            .map(|b| JsonValue::Array(b.into_iter().map(|n| JsonValue::Number(n.into())).collect()))
            .unwrap_or(JsonValue::Null),
        _ => v
            .to_owned()
            .try_decode::<String>()
            .map(JsonValue::String)
            .unwrap_or(JsonValue::Null),
    }
}

async fn open_connection(path: std::path::PathBuf) -> Result<SqliteConnection, String> {
    SqliteConnectOptions::new()
        .filename(path)
        .create_if_missing(true)
        .journal_mode(SqliteJournalMode::Wal)
        .synchronous(SqliteSynchronous::Normal)
        .busy_timeout(Duration::from_secs(5))
        .foreign_keys(true)
        .connect()
        .await
        .map_err(|e| e.to_string())
}

/// Open (or re-open) the single writer connection for `db` (a bare file name
/// like `izipos-<companyId>.db`, resolved against the app config dir — the
/// SAME directory the SQL plugin uses, so both layers address one file).
#[command]
pub async fn writer_open<R: Runtime>(
    app: AppHandle<R>,
    state: State<'_, WriterState>,
    db: String,
) -> Result<(), String> {
    let app_path = app
        .path()
        .app_config_dir()
        .map_err(|e| format!("no app config dir: {e}"))?;
    std::fs::create_dir_all(&app_path).map_err(|e| format!("cannot create app config dir: {e}"))?;
    let conn = open_connection(app_path.join(&db)).await?;

    let mut map = state.0.lock().await;
    if let Some(old) = map.remove(&db) {
        let _ = old.close().await;
    }
    map.insert(db, conn);
    Ok(())
}

#[command]
pub async fn writer_execute(
    state: State<'_, WriterState>,
    db: String,
    sql: String,
    values: Vec<JsonValue>,
) -> Result<ExecResult, String> {
    let mut map = state.0.lock().await;
    let conn = map
        .get_mut(&db)
        .ok_or_else(|| format!("writer not open: {db}"))?;
    let query = bind_values(sqlx::query(&sql), values);
    let result = conn.execute(query).await.map_err(|e| e.to_string())?;
    Ok(ExecResult {
        rows_affected: result.rows_affected(),
        last_insert_id: result.last_insert_rowid(),
    })
}

#[command]
pub async fn writer_select(
    state: State<'_, WriterState>,
    db: String,
    sql: String,
    values: Vec<JsonValue>,
) -> Result<Vec<serde_json::Map<String, JsonValue>>, String> {
    let mut map = state.0.lock().await;
    let conn = map
        .get_mut(&db)
        .ok_or_else(|| format!("writer not open: {db}"))?;
    let query = bind_values(sqlx::query(&sql), values);
    let rows = conn.fetch_all(query).await.map_err(|e| e.to_string())?;
    let mut out = Vec::with_capacity(rows.len());
    for row in rows {
        let mut obj = serde_json::Map::new();
        for (i, column) in row.columns().iter().enumerate() {
            let raw = row.try_get_raw(i).map_err(|e| e.to_string())?;
            obj.insert(column.name().to_string(), value_to_json(raw));
        }
        out.push(obj);
    }
    Ok(out)
}

#[command]
pub async fn writer_close(state: State<'_, WriterState>, db: String) -> Result<(), String> {
    let mut map = state.0.lock().await;
    if let Some(conn) = map.remove(&db) {
        conn.close().await.map_err(|e| e.to_string())?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::Executor;

    async fn test_conn() -> SqliteConnection {
        SqliteConnectOptions::new()
            .in_memory(true)
            .connect()
            .await
            .expect("in-memory sqlite")
    }

    #[tokio::test]
    async fn bind_and_decode_roundtrip() {
        let mut conn = test_conn().await;
        conn.execute("CREATE TABLE t (a TEXT, b INTEGER, c REAL, d TEXT)")
            .await
            .unwrap();
        let q = bind_values(
            sqlx::query("INSERT INTO t (a, b, c, d) VALUES ($1, $2, $3, $4)"),
            vec![
                JsonValue::String("12.345".into()), // money stays a string
                JsonValue::Number(7.into()),
                JsonValue::from(1.5),
                JsonValue::Null,
            ],
        );
        conn.execute(q).await.unwrap();

        let rows = conn.fetch_all(sqlx::query("SELECT a, b, c, d FROM t")).await.unwrap();
        let row = &rows[0];
        let mut obj = serde_json::Map::new();
        for (i, column) in row.columns().iter().enumerate() {
            obj.insert(column.name().to_string(), value_to_json(row.try_get_raw(i).unwrap()));
        }
        assert_eq!(obj.get("a"), Some(&JsonValue::String("12.345".into())));
        // numbers bind as f64 (plugin parity) → INTEGER column affinity stores 7
        assert_eq!(obj.get("b"), Some(&JsonValue::Number(7.into())));
        assert_eq!(obj.get("c"), Some(&JsonValue::from(1.5)));
        assert_eq!(obj.get("d"), Some(&JsonValue::Null));
    }

    #[tokio::test]
    async fn open_connection_applies_wal_and_busy_timeout() {
        let dir = std::env::temp_dir().join(format!("pos-writer-test-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let mut conn = open_connection(dir.join("t.db")).await.unwrap();
        let rows = conn.fetch_all(sqlx::query("PRAGMA journal_mode")).await.unwrap();
        let mode = value_to_json(rows[0].try_get_raw(0).unwrap());
        assert_eq!(mode, JsonValue::String("wal".into()));
        let rows = conn.fetch_all(sqlx::query("PRAGMA busy_timeout")).await.unwrap();
        let timeout = value_to_json(rows[0].try_get_raw(0).unwrap());
        assert_eq!(timeout, JsonValue::Number(5000.into()));
        let _ = std::fs::remove_dir_all(&dir);
    }
}
```

- [ ] **Step 3: Register in `lib.rs`**

Add `mod db_writer;` after `mod printing;`, add `.manage(db_writer::WriterState::default())` before `.invoke_handler(...)`, and append to `generate_handler![...]`:

```rust
            db_writer::writer_open,
            db_writer::writer_execute,
            db_writer::writer_select,
            db_writer::writer_close,
```

- [ ] **Step 4: Build + test**

Run: `cd apps/pos/src-tauri && cargo check && cargo test db_writer`
Expected: compiles clean; 2 tests pass. (If `as_number()` is unavailable on the resolved serde_json, replace with `value.as_f64()` guarded by `value.is_number()` — plugin parity preserved.)

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src-tauri/Cargo.toml apps/pos/src-tauri/Cargo.lock apps/pos/src-tauri/src/db_writer.rs apps/pos/src-tauri/src/lib.rs
git commit -m "feat(pos): Rust single-connection SQLite writer commands (WAL, busy_timeout 5s)"
```

---

### Task 4: JS `dbWriter.ts` + boot rewiring in `db.ts`

**Files:**
- Create: `apps/pos/src/lib/db/dbWriter.ts`
- Modify: `apps/pos/src/lib/db.ts`

- [ ] **Step 1: Create the invoke wrapper**

```ts
// apps/pos/src/lib/db/dbWriter.ts
import { invoke } from '@tauri-apps/api/core';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

/**
 * JS face of the Rust single-connection writer (src-tauri/src/db_writer.rs).
 * All multi-statement transactions run on this surface via the writeGate —
 * never on the pooled plugin Database. Rejections are plain STRINGS (Tauri
 * boundary), same as the plugin.
 */
export async function openWriter(dbName: string): Promise<void> {
  await invoke('writer_open', { db: dbName });
}

export async function closeWriter(dbName: string): Promise<void> {
  await invoke('writer_close', { db: dbName });
}

export function createWriterSurface(dbName: string): SqlSurface {
  return {
    execute: (sql: string, params: unknown[] = []) =>
      invoke<{ rowsAffected: number; lastInsertId: number }>('writer_execute', {
        db: dbName,
        sql,
        values: params,
      }),
    select: <T>(sql: string, params: unknown[] = []) =>
      invoke<T>('writer_select', { db: dbName, sql, values: params }),
  };
}
```

- [ ] **Step 2: Rewire `getDatabase` boot in `db.ts`**

Replace the body of `getDatabase` (keep the singleton/company-switch shape) with:

```ts
import Database from '@tauri-apps/plugin-sql';
import { migrations } from './db/migrations';
import { wrapDatabaseWithBusyRetry } from './db/busyRetry';
import { closeWriter, createWriterSurface, openWriter } from './db/dbWriter';
import { enqueueWrite, setWriter } from './db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

let db: Database | null = null;
let currentDbName: string | null = null;

export async function getDatabase(companyId: string): Promise<Database> {
  const dbName = `izipos-${companyId}.db`;

  if (db && currentDbName === dbName) {
    return db;
  }

  // Close previous connections if switching companies
  if (db && currentDbName !== dbName) {
    await db.close();
    if (currentDbName) await closeWriter(currentDbName);
    setWriter(null);
    db = null;
  }

  // Single-writer architecture (2026-06-12 design spec):
  //   - ALL writes serialize through the writeGate; multi-statement
  //     transactions run on the Rust-owned single connection below (the
  //     plugin's SQLx POOL splits a JS BEGIN…COMMIT across physical
  //     connections — self-deadlock + transaction poisoning).
  //   - The plugin pool below is the READ path only (WAL ⇒ readers never
  //     block on the writer). busyRetry stays as read-side hardening for
  //     rare checkpoint-edge BUSY.
  // The writer's connect options own the WAL/synchronous/busy_timeout
  // pragmas (file-persistent), so no pool-side PRAGMA is issued anymore.
  await openWriter(dbName);
  const writerSurface: SqlSurface = createWriterSurface(dbName);
  setWriter(writerSurface);

  db = await Database.load(`sqlite:${dbName}`);
  currentDbName = dbName;
  wrapDatabaseWithBusyRetry(db);

  // Migrations + recovery are writes → run on the writer through the gate
  // (migrations include multi-statement blocks and one BEGIN-using data
  // migration; the pool must never see transaction statements).
  await enqueueWrite('fiscal', () => runMigrations(writerSurface));
  await enqueueWrite('fiscal', () => runStuckReceiptRecovery(writerSurface));

  return db;
}
```

Update `runMigrations` and `runStuckReceiptRecovery` signatures from `(database: Database)` to `(database: SqlSurface)` (their bodies only use `execute`/`select`, which `SqlSurface` provides; existing tests pass adapters that structurally satisfy both). Update `closeDatabase`:

```ts
export async function closeDatabase(): Promise<void> {
  if (db) {
    await db.close();
    if (currentDbName) await closeWriter(currentDbName);
    setWriter(null);
    db = null;
    currentDbName = null;
  }
}
```

- [ ] **Step 3: Verify**

Run: `cd apps/pos && pnpm exec tsc --noEmit && pnpm exec vitest run src/lib/db/__tests__`
Expected: typecheck clean; existing migration/db tests pass (they call `runMigrations`/adapters directly — signature loosening is structural).

- [ ] **Step 4: Commit**

```bash
git add src/lib/db/dbWriter.ts src/lib/db.ts
git commit -m "feat(pos): boot the single-writer connection; migrations/recovery run via write gate"
```

---

### Task 5: Receipt path through the gate (+ perf instrumentation)

**Files:**
- Modify: `apps/pos/src/lib/offline/receiptService.ts` (the `BEGIN IMMEDIATE` block, lines ~400–549)
- Modify: `apps/pos/src/lib/offline/__tests__/receiptService.test.ts`, `voucherCheckout.integration.test.ts`, `idempotencyRetry.integration.test.ts` (inject the adapter as gate writer)

- [ ] **Step 1: Update the three test files' setup (RED first)**

In each test file's setup (where the adapter/db is created), add:

```ts
import { setWriter, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';
```

and in `beforeEach` (after the adapter is constructed):

```ts
__resetWriteGateForTesting();
setWriter(adapter as unknown as SqlSurface);
```

(for `receiptService.test.ts`, the handle variable is `db` — use that name). Add a NEW failure-path test to `receiptService.test.ts` (append inside the main describe; reuse that file's existing seeding helpers for terminal state + cart fixtures):

```ts
  it('rolls back the fiscal append when the receipt insert fails (no chain advance, no partial rows)', async () => {
    const before = await db.select<Array<{ hash_sequence: number }>>(
      'SELECT hash_sequence FROM terminal_state WHERE terminal_id = $1', [TERMINAL_ID],
    );

    // Force the receipt INSERT to fail: pre-insert a row with the same
    // idempotency key is dodged by the pre-check, so collide on the
    // receipt PRIMARY KEY instead — stub crypto.randomUUID once to a
    // known id and pre-insert that id.
    const fixedId = '11111111-1111-4111-8111-111111111111';
    const uuidSpy = vi.spyOn(crypto, 'randomUUID').mockReturnValueOnce(fixedId);
    await db.execute(
      `INSERT INTO offline_receipts (id, idempotency_key, receipt_number, terminal_id, terminal_code, operator_id, operator_name, lines, subtotal, tax_amount, discount_amount, total, currency, fiscal_hash, previous_hash, hash_sequence, tendered_amount, change_due, payment_method_id, payment_repository_id, status)
       VALUES ($1, $2, 'X', $3, 'T1', 'op', 'Op', '[]', '0', '0', '0', '0', 'EUR', 'h', 'p', 999, '0', '0', 'pm', 'pr', 'pending')`,
      [fixedId, crypto.randomUUID(), TERMINAL_ID],
    );

    await expect(createOfflineReceipt(db, baseInput())).rejects.toBeTruthy();
    uuidSpy.mockRestore();

    const after = await db.select<Array<{ hash_sequence: number }>>(
      'SELECT hash_sequence FROM terminal_state WHERE terminal_id = $1', [TERMINAL_ID],
    );
    expect(after[0]!.hash_sequence).toBe(before[0]!.hash_sequence); // chain head unchanged
    const events = await db.select<Array<{ id: string }>>(
      "SELECT id FROM fiscal_events WHERE source_event_id = $1", [fixedId],
    );
    expect(events).toHaveLength(0); // fiscal append rolled back
  });
```

Adapt column lists/fixture helpers to what `receiptService.test.ts` already uses — the assertion contract (chain head unchanged + no fiscal event row) is what matters.

- [ ] **Step 2: Run to verify the new failure-path test fails for the right reason**

Run: `cd apps/pos && pnpm exec vitest run src/lib/offline/__tests__/receiptService.test.ts`
Expected: existing tests still PASS (gate not yet used by the service); the new test may pass already via the raw BEGIN block — if so it stays as a pinned regression test. The RED signal for this task is Step 4's gate-exclusivity test below if you add it; otherwise treat Step 3 as a refactor under existing green tests.

- [ ] **Step 3: Replace the transaction block in `receiptService.ts`**

Replace the whole region from `await db.execute('BEGIN IMMEDIATE TRANSACTION');` (line ~400) through the end of its `catch` block (line ~549) with:

```ts
  // Single-writer architecture: the receipt tx (fiscal append + receipt
  // insert + voucher balances) runs as ONE exclusive write-gate job on the
  // Rust single connection — `fiscal` lane preempts queued sync chunks. The
  // pooled `db` handle stays for the pre-transaction reads above only.
  const engine = await getFiscalEventEngine(input.companyId, db);
  const txStartedAt = performance.now();
  let fiscalEventResult: FiscalEventAppendResult | null = null;
  let receiptNumber = '';
  try {
    await withWriteTransaction('fiscal', async (tx) => {
      fiscalEventResult = await engine.append(tx, {
        event_type: 'SALE_RECEIPT',
        tenant_id: input.tenantId,
        company_id: input.companyId,
        terminal_id: input.terminalId,
        operator_id: input.operatorId,
        event_time_device: isoSecondsUtc(postedAtDate),
        business_date: businessDate,
        payload: canonicalPayload,
        reference_event_id: primaryApprovalReferenceEventId ?? undefined,
        source_event_class: 'offline_receipts',
        source_event_id: receiptId,
      });
      receiptNumber = generateReceiptNumber(
        terminalState.location_code,
        terminalState.terminal_code,
        fiscalEventResult.sequence_number,
      );

      const offlineReceipt: Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'> = {
        // ... UNCHANGED object literal — keep exactly as today ...
      };

      await insertOfflineReceipt(tx as unknown as Parameters<typeof insertOfflineReceipt>[0], offlineReceipt);

      // (keep the existing voucher-balance comment block verbatim)
      for (let i = 0; i < voucherTenders.length; i++) {
        const tender = voucherTenders[i]!;
        const voucher = resolvedVouchers[i]!;
        const newBalance = bcsub(voucher.current_balance, tender.amount, decimals);
        const clampedBalance = bccomp(newBalance, '0') < 0
          ? bcformat('0', decimals)
          : bcformat(newBalance, decimals);
        const newStatus: VoucherStatus = bccomp(clampedBalance, '0') === 0
          ? 'FullyRedeemed'
          : 'PartiallyRedeemed';

        await updateVoucherBalanceAndStatus(tx as unknown as Parameters<typeof updateVoucherBalanceAndStatus>[0], voucher.id, clampedBalance, newStatus);
      }
    });
  } catch (error) {
    console.error('[POS][offline][receipt] tx failed — rolled back', {
      ...serializeErrorForLog(error),
      receiptNumber,
      hashSequence: (fiscalEventResult as FiscalEventAppendResult | null)?.sequence_number ?? null,
      previousHash: (fiscalEventResult as FiscalEventAppendResult | null)?.previous_hash ?? null,
      terminalId: input.terminalId,
    });
    throw error;
  }
  console.info('[POS][perf][receipt] fiscal tx committed', {
    ms: Math.round(performance.now() - txStartedAt),
    receiptNumber,
  });
```

Add the import: `import { withWriteTransaction } from '@/lib/db/writeGate';`. Keep everything after (`useSyncStore…incrementPendingCount`, `scheduleDebouncedSync`, null-check, return) unchanged. NOTE: `getFiscalEventEngine` moved BEFORE the tx (engine construction is not a write).

- [ ] **Step 4: Run the offline suites**

Run: `cd apps/pos && pnpm exec vitest run src/lib/offline/__tests__ && pnpm exec tsc --noEmit`
Expected: PASS — including the failure-path test (now proving gate ROLLBACK), voucher + idempotency integration tests through the gate.

- [ ] **Step 5: Commit**

```bash
git add src/lib/offline/receiptService.ts src/lib/offline/__tests__/receiptService.test.ts src/lib/offline/__tests__/voucherCheckout.integration.test.ts src/lib/offline/__tests__/idempotencyRetry.integration.test.ts
git commit -m "feat(pos): checkout receipt tx runs exclusively on the single-writer gate (fiscal lane) + perf log"
```

---

### Task 6: Fiscal sweep A — `zReportService`, `zSessionAuthoring`, `ChainRecoveryService`

**Files:**
- Modify: `apps/pos/src/lib/offline/zReportService.ts:424-461`
- Modify: `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:498-541`
- Modify: `apps/pos/src/lib/fiscal/ChainRecoveryService.ts:184-231`
- Modify tests: `src/lib/offline/__tests__/zReportService.test.ts`, `src/lib/fiscal/__tests__/zSessionAuthoring.test.ts`, `src/lib/fiscal/__tests__/ChainRecoveryService.test.ts`

The transform is identical in all three: replace the manual `BEGIN`/`COMMIT`/`ROLLBACK` block with `withWriteTransaction('fiscal', async (tx) => { <body with db→tx substituted> })` and import `withWriteTransaction` from `@/lib/db/writeGate`. Inside the bodies, every handle use switches to `tx` (`engine.append(tx, …)`, `insertZReport(tx as unknown as Database, …)` etc. — cast with `as unknown as Database` only where a repo signature demands the plugin type, mirroring the existing `asDatabase()` precedent). Reads/computation BEFORE the old `BEGIN` stay on `db`.

- [ ] **Step 1: Update the three test files' setups** — same pattern as Task 5 Step 1 (`__resetWriteGateForTesting()` + `setWriter(adapter as unknown as SqlSurface)` in `beforeEach`).

- [ ] **Step 2: `zReportService.ts`** — replace lines 424–461 with:

```ts
  await withWriteTransaction('fiscal', async (tx) => {
    await insertZReport(tx as unknown as Database, zReport);
    if (zReportCountRows !== null && zReportCountRows.length > 0) {
      await insertZReportCounts(tx as unknown as Database, zReportCountRows);
    }
    await advanceZChain(tx as unknown as Database, terminalId, fiscalHash, newHashSequence, newZNumber);
    await updateGrandTotals(tx as unknown as Database, terminalId, grossSalesStr, taxStr, refundsStr, salesCount);

    const closeInput = buildFiscalCloseInput({
      companyId,
      companyName: company?.name ?? null,
      currencyCode: companyCurrency,
      decimals,
      fiscalHash,
      generatedAt,
      grandTotals,
      newHashSequence,
      opts,
      receiptSnapshots,
      reportData,
      shiftId,
      shiftOpenedAt,
      terminalId,
      zChainState,
      zReport,
      zReportCountRows,
    });
    if (closeInput !== null && companyId !== null) {
      const engine = await getFiscalEventEngine(companyId, db);
      await appendZSessionCloseAndZReport(tx, engine, closeInput);
    }
  });
```

- [ ] **Step 3: `zSessionAuthoring.ts` (`authorZSessionOpenWithOpeningFloatOnDb`)** — replace the `BEGIN`→`ROLLBACK` block (lines 498–541) with:

```ts
  const result = await withWriteTransaction('fiscal', async (tx) => {
    const sessionOpenEvent = await engine.append(tx, {
      event_type: 'SESSION_OPEN',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(openedAtDevice),
      business_date: input.businessDate,
      chain_context: chainContext,
      payload: buildSessionOpenPayload(input, openedAtDevice),
      source_event_class: 'pos_session',
      source_event_id: input.sessionId,
    });

    const openingFloatEvent = await engine.append(tx, {
      event_type: 'OPENING_FLOAT',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(openedAtDevice),
      business_date: input.businessDate,
      chain_context: chainContext,
      payload: buildOpeningFloatPayload(input, openedAtDevice, movementId),
      reference_event_id: sessionOpenEvent.id,
      source_event_class: 'z_cash_drawer_movement',
      source_event_id: movementId,
    });

    return { sessionOpenEvent, openingFloatEvent };
  });

  return {
    sessionOpenEvent: result.sessionOpenEvent,
    openingFloatEvent: result.openingFloatEvent,
    openingFloatMovementId: movementId,
  };
```

(The `db` parameter stays in the signature — `appendXReport`/sibling readers still use it; the doc comment should note writes now route via the gate.)

- [ ] **Step 4: `ChainRecoveryService.recordBreakAndRestart`** — replace the `sql.execute('BEGIN')`→catch block with:

```ts
    return withWriteTransaction('fiscal', async (txw) => {
      const breakResult = await this.engine.append(txw, {
        event_type: 'CHAIN_BREAK_DETECTED',
        tenant_id: request.tenant_id,
        company_id: request.company_id,
        terminal_id: request.terminal_id,
        operator_id: request.operator_id,
        event_time_device: request.event_time_device,
        business_date: request.business_date,
        payload: buildBreakPayload(request),
      });

      const restartResult = await this.engine.append(txw, {
        event_type: 'CHAIN_RESTART',
        tenant_id: request.tenant_id,
        company_id: request.company_id,
        terminal_id: request.terminal_id,
        operator_id: request.operator_id,
        event_time_device: request.event_time_device,
        business_date: request.business_date,
        payload: buildRestartPayload(request, breakResult),
      });

      // Flip the degraded flag in the SAME transaction as the appends.
      await txw.execute(
        `UPDATE terminal_state
            SET fiscal_chain_status = 'degraded'
          WHERE terminal_id = $1`,
        [request.terminal_id],
      );

      return { break: breakResult, restart: restartResult };
    });
```

(The `tx` parameter stays in the signature for the pre-tx `assertOffendingReference` symmetry and callers; remove the now-unused `const sql = tx as unknown as SqlSurface;` if nothing else uses it.)

- [ ] **Step 5: Run**

Run: `cd apps/pos && pnpm exec vitest run src/lib/offline/__tests__/zReportService.test.ts src/lib/fiscal/__tests__/zSessionAuthoring.test.ts src/lib/fiscal/__tests__/ChainRecoveryService.test.ts && pnpm exec tsc --noEmit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/lib/offline/zReportService.ts src/lib/fiscal/zSessionAuthoring.ts src/lib/fiscal/ChainRecoveryService.ts src/lib/offline/__tests__/zReportService.test.ts src/lib/fiscal/__tests__/zSessionAuthoring.test.ts src/lib/fiscal/__tests__/ChainRecoveryService.test.ts
git commit -m "feat(pos): Z-report, session-open, and chain-recovery txs run on the single-writer gate"
```

---

### Task 7: Fiscal sweep B — account payment/charge, override + drawer approvals

**Files:**
- Modify: `apps/pos/src/lib/offline/accountPaymentService.ts:291-328`
- Modify: `apps/pos/src/lib/accountCharge/accountChargeService.ts:462-~560` (block ends at its `COMMIT`/`ROLLBACK`)
- Modify: `apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts:77-~150`
- Modify: `apps/pos/src/lib/operatorApproval/cashDrawerApproval.ts:73-~125`
- Modify their test files (locate with `grep -rl "accountPaymentService\|accountChargeService\|posOverrideAuthoring\|cashDrawerApproval" src --include="*.test.ts"`): same `setWriter` setup as Task 5 Step 1.

Same mechanical transform as Task 6 — for each file: replace `await db.execute('BEGIN TRANSACTION'); try { … await db.execute('COMMIT'); } catch { ROLLBACK; throw; }` with `await withWriteTransaction('fiscal', async (tx) => { … })`, substitute `db` → `tx` for every statement INSIDE the body (`engine.append(tx, …)`, `insertLocalAccountPaymentRecord(tx as unknown as Database, …)` etc.), keep everything before/after the block untouched, add the `withWriteTransaction` import. Where the body computed values needed after the tx (e.g. `appendResult`), return them from the tx callback:

Example — `accountPaymentService.ts`:

```ts
  const appendResult = await withWriteTransaction('fiscal', async (tx) => {
    const engine = await getFiscalEventEngine(input.companyId, db);
    const result = await engine.append(tx, {
      event_type: 'ACCOUNT_PAYMENT',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(eventTimeDevice),
      business_date: businessDate,
      payload,
      source_event_class: 'account_payments',
      source_event_id: accountPaymentUuid,
    });

    if (input.isTraining !== true) {
      const mirrorAmount = bcformat(input.payment.amount, getCurrencyDecimals(input.currency));
      await insertLocalAccountPaymentRecord(tx as unknown as Database, {
        id: accountPaymentUuid,
        shift_id: input.shiftId,
        terminal_id: input.terminalId,
        method_code: input.payment.methodCode,
        amount: mirrorAmount,
        cash_impact: input.payment.methodCode === 'CASH' ? mirrorAmount : '0',
        currency: input.currency,
      });
    }

    return result;
  });
```

(then delete the old `let appendResult … = null` declaration and the now-redundant `if (appendResult === null)` guard, since `withWriteTransaction` either returns the result or throws). Apply the same return-from-callback pattern in `accountChargeService` (`appendResult`, `payload`), `posOverrideAuthoring` (`approvalEvent`, `overrideEvent`), and `cashDrawerApproval` (`approvalEvent` — note its `COMMIT` is inside the `try` before the `return`; the new shape returns the evidence object from the callback).

- [ ] **Step 1: Update test setups (RED-safe), Step 2: transform the four files, Step 3: run**

Run: `cd apps/pos && pnpm exec vitest run src/lib/offline/__tests__/accountPaymentService.test.ts src/lib/fiscal/__tests__/accountChargeCanonicalParity.test.ts src/lib/fiscal/__tests__/accountPaymentCanonicalParity.test.ts $(grep -rl "posOverrideAuthoring\|cashDrawerApproval" src --include="*.test.ts" | tr '\n' ' ') && pnpm exec tsc --noEmit`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A src/lib/offline/accountPaymentService.ts src/lib/accountCharge/accountChargeService.ts src/lib/operatorApproval/
git add -A src/lib/offline/__tests__ src/lib/fiscal/__tests__ src/lib/accountCharge/__tests__ 2>/dev/null || true
git commit -m "feat(pos): account payment/charge + override/drawer approval txs run on the single-writer gate"
```

---

### Task 8: Sync writes through the sync lane

**Files:**
- Modify: `apps/pos/src/lib/sync/syncService.ts`

- [ ] **Step 1: Route every exported pull/push entry through `gatedSyncDb`**

Add import: `import { gatedSyncDb, withWriteTransaction } from '@/lib/db/writeGate';`

At the TOP of each of these function bodies (the complete list of `syncService.ts` exports that write), add one line `db = gatedSyncDb(db);` (change the parameter binding to `let`-style by reassigning a local: `const sdb = gatedSyncDb(db);` and substitute `db`→`sdb` inside — pick the mechanical option, `const sdb`, to keep params readonly):

`pushOfflineReceipts`, `pushZReports`, `pushCashDrawerOps`, `pullProductsCore`, `pullProducts` (wrapper — passes `db` through, no change needed beyond `pullProductsCore`), `pullLocationStock`, `pullPaymentConfig`, `pullOperatorPins`, `pullTerminalState`, `pullZChainState`, `pushQueuedPinUpdates`, `pushQueuedAuditEvents`, `pullTables`, `pullActiveMenu`, plus the voucher pull/push functions and any `cleanup*`/`runFullSync` direct writes (`recoverStrandedSyncingFiscalEvents`, `cleanupSyncedReceipts`, `cleanupStuckReceipts`, `cleanupSyncedCashDrawerOps`, `cleanupOldSyncLogs`, `setSyncMetadata` calls inside `runFullSync` if any). Because `gatedSyncDb` is idempotent (WeakSet) and `select` passes through, reads are unaffected and double-wrapping via nested calls is safe.

IMPORTANT exception inside `pullLocationStock`: the `replaceIncoming` call becomes a short atomic transaction on the writer (its zero-then-upsert window would otherwise expose zeroed incoming quantities to concurrent stock reads):

```ts
  // Every page fetched — NOW write (see atomicity contract above).
  if (effectiveMode === 'full') {
    await replaceAllStock(sdb, allStock);
  } else {
    await upsertStockRows(sdb, allStock);
  }
  await withWriteTransaction('sync', (tx) =>
    replaceIncoming(tx as unknown as Database, incoming),
  );
```

(`replaceIncoming` receives `tx` — the raw writer — NOT `sdb`; passing the gated wrapper inside a gate job would deadlock.)

- [ ] **Step 2: Run the sync suites**

Run: `cd apps/pos && pnpm exec vitest run src/lib/sync/__tests__ && pnpm exec tsc --noEmit`
Expected: PASS — existing tests pass adapters/mocks whose `execute` now routes through the gate transparently (no writer needed for plain `enqueueWrite`); `pullLocationStock.test.ts` needs `setWriter(adapter as unknown as SqlSurface)` + `__resetWriteGateForTesting()` in its setup because of the `replaceIncoming` transaction.

- [ ] **Step 3: Commit**

```bash
git add src/lib/sync/syncService.ts src/lib/sync/__tests__
git commit -m "feat(pos): sync writes serialize per-statement through the sync lane; replaceIncoming is atomic"
```

---

### Task 9: Multi-connection concurrency proof (the missing test)

**Files:**
- Create: `apps/pos/src/lib/db/__tests__/concurrentCheckout.integration.test.ts`

- [ ] **Step 1: Write the test**

```ts
// apps/pos/src/lib/db/__tests__/concurrentCheckout.integration.test.ts
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import {
  setWriter,
  withWriteTransaction,
  enqueueWrite,
  gatedSyncDb,
  __resetWriteGateForTesting,
} from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

/**
 * The proof the 2026-06-12 handover demanded: a checkout-shaped fiscal
 * transaction completes — fast and gap-free — WHILE a bulk catalog sync is
 * flooding the sync lane, using TWO REAL SQLite connections on one file
 * (writer + pool survivor) and the string-error boundary.
 */

let dir: string;
let file: string;
let writerConn: SqliteTestAdapter;
let poolConn: SqliteTestAdapter;

/** Wrap an adapter so failures surface as Tauri-style STRINGS. */
function stringBoundary(adapter: SqliteTestAdapter): SqlSurface {
  return {
    execute: async (sql: string, params?: unknown[]) => {
      try {
        return await adapter.execute(sql, params);
      } catch (e) {
        throw `error returned from database: (code: 5) ${(e as Error).message}`;
      }
    },
    select: async <T>(sql: string, params?: unknown[]) => {
      try {
        return await adapter.select<T>(sql, params);
      } catch (e) {
        throw `error returned from database: (code: 5) ${(e as Error).message}`;
      }
    },
  };
}

beforeEach(async () => {
  __resetWriteGateForTesting();
  dir = mkdtempSync(join(tmpdir(), 'pos-concurrent-'));
  file = join(dir, 'concurrent.db');
  writerConn = new SqliteTestAdapter(file);
  writerConn.inner.pragma('journal_mode = WAL');
  poolConn = new SqliteTestAdapter(file);
  await writerConn.execute('CREATE TABLE products_like (id TEXT PRIMARY KEY, name TEXT)');
  await writerConn.execute('CREATE TABLE chain (terminal_id TEXT PRIMARY KEY, seq INTEGER NOT NULL)');
  await writerConn.execute('CREATE TABLE fiscal_like (seq INTEGER PRIMARY KEY, terminal_id TEXT NOT NULL)');
  await writerConn.execute("INSERT INTO chain (terminal_id, seq) VALUES ('T1', 0)");
  setWriter(stringBoundary(writerConn));
});

afterEach(() => {
  writerConn.close();
  poolConn.close();
  rmSync(dir, { recursive: true, force: true });
});

/** Checkout-shaped tx: read chain head, append next event, advance head. */
async function fiscalCheckout(): Promise<number> {
  return withWriteTransaction('fiscal', async (tx) => {
    const rows = await tx.select<Array<{ seq: number }>>("SELECT seq FROM chain WHERE terminal_id = 'T1'");
    const next = rows[0]!.seq + 1;
    await tx.execute("INSERT INTO fiscal_like (seq, terminal_id) VALUES ($1, 'T1')", [next]);
    await tx.execute("UPDATE chain SET seq = $1 WHERE terminal_id = 'T1'", [next]);
    return next;
  });
}

describe('checkout under concurrent bulk sync (two real connections, string boundary)', () => {
  it('completes gap-free and preempts queued sync chunks', async () => {
    const syncDb = gatedSyncDb(stringBoundary(poolConn) as never) as unknown as SqlSurface;
    const startOrder: string[] = [];

    // Flood: 30 chunks of 50 rows through the POOL connection on the sync lane.
    const flood = Promise.all(
      Array.from({ length: 30 }, (_, chunk) =>
        enqueueWrite('sync', async () => {
          startOrder.push(`sync${chunk}`);
          const values = Array.from({ length: 50 }, (_, j) =>
            `('p-${chunk}-${j}', 'Product ${chunk}-${j}')`).join(', ');
          await (stringBoundary(poolConn)).execute(`INSERT INTO products_like (id, name) VALUES ${values}`);
        })),
    );

    // Three checkouts land while the flood is queued.
    await new Promise((r) => setTimeout(r, 0));
    const s1 = await (async () => { startOrder.push('fiscal1'); return fiscalCheckout(); })();
    const s2 = await (async () => { startOrder.push('fiscal2'); return fiscalCheckout(); })();
    const s3 = await (async () => { startOrder.push('fiscal3'); return fiscalCheckout(); })();
    await flood;

    // 1. All checkouts completed with a strictly sequential, gap-free chain.
    expect([s1, s2, s3]).toEqual([1, 2, 3]);
    const events = await writerConn.select<Array<{ seq: number }>>('SELECT seq FROM fiscal_like ORDER BY seq');
    expect(events.map((e) => e.seq)).toEqual([1, 2, 3]);

    // 2. Every sync row landed (the gate starves nobody).
    const count = await writerConn.select<Array<{ n: number }>>('SELECT COUNT(*) AS n FROM products_like');
    expect(count[0]!.n).toBe(1500);

    // 3. Priority: each fiscal checkout ran before the LAST sync chunk
    //    started (it jumped the queued flood rather than draining it).
    const lastSyncIdx = startOrder.indexOf('sync29');
    for (const f of ['fiscal1', 'fiscal2', 'fiscal3']) {
      expect(startOrder.indexOf(f)).toBeLessThan(lastSyncIdx);
    }

    // 4. The sync lane's gated execute also exercised the wrapper path.
    await syncDb.execute("INSERT INTO products_like (id, name) VALUES ('extra', 'x')");
    const extra = await writerConn.select<Array<{ n: number }>>("SELECT COUNT(*) AS n FROM products_like WHERE id = 'extra'");
    expect(extra[0]!.n).toBe(1);
  });
});
```

- [ ] **Step 2: Run**

Run: `cd apps/pos && pnpm exec vitest run src/lib/db/__tests__/concurrentCheckout.integration.test.ts`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add src/lib/db/__tests__/concurrentCheckout.integration.test.ts
git commit -m "test(pos): two-connection concurrency proof — checkout completes gap-free under bulk sync flood"
```

---

### Task 10: `apiPostRaw` + `pushOfflineReceipts` response-shape fix

**Files:**
- Modify: `apps/pos/src/lib/api.ts` (next to `apiGetRaw`, ~line 196)
- Modify: `apps/pos/src/lib/sync/syncService.ts:246` (`pushOfflineReceipts`)
- Modify/extend: `apps/pos/src/lib/sync/__tests__/syncService.test.ts`

- [ ] **Step 1: Write the failing test** — in `syncService.test.ts`, find how `pushOfflineReceipts` is currently tested (it mocks `@/lib/api`). Add to the mock module an `apiPostRaw: vi.fn()` and a test:

```ts
  it('pushOfflineReceipts reads the UNWRAPPED { results } shape via apiPostRaw (server sends no data envelope)', async () => {
    // seed one pending fiscal event through the existing helper/fixture
    // pattern used by this file, then:
    vi.mocked(apiPostRaw).mockResolvedValueOnce({
      results: [{ stored: true, fiscal_event_id: PENDING_EVENT_ID, sequence_conflict: false, exception_class: null }],
    });

    const result = await pushOfflineReceipts(db);

    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(0);
    expect(apiPostRaw).toHaveBeenCalledWith(
      '/pos/sync/fiscal-events',
      expect.objectContaining({ envelopes: expect.any(Array) }),
      { timeoutMs: 30_000 },
    );
  });
```

(adapt fixture/seeding names to the file's existing helpers — the contract under test: `apiPostRaw` is called, `apiPost` is NOT, and a top-level `results` array is honored.)

Run: `pnpm exec vitest run src/lib/sync/__tests__/syncService.test.ts` → Expected: FAIL (`apiPostRaw` not exported / not called).

- [ ] **Step 2: Add `apiPostRaw` to `api.ts`** (right after `apiGetRaw`):

```ts
/**
 * Raw POST preserving the server's top-level response body (no `.data`
 * unwrap). Required by endpoints that deliberately do NOT use the
 * `{ data, meta }` envelope — e.g. `POST /pos/sync/fiscal-events`, whose
 * contract is a top-level `{ results: [...] }`
 * (FiscalEventIngestionController). Using `apiPost` there unwraps
 * `json.data` → `undefined` and crashes on `response.results`.
 */
export async function apiPostRaw<T>(
  url: string,
  data?: unknown,
  opts?: ApiRequestOptions,
): Promise<T> {
  return requestRaw<T>('POST', url, data, undefined, opts);
}
```

- [ ] **Step 3: Use it in `pushOfflineReceipts`** — change the import line to include `apiPostRaw`, and replace the call at line ~246:

```ts
      const response = await apiPostRaw<FiscalEventSyncBatchResponse>(
        '/pos/sync/fiscal-events',
        { envelopes: [fiscalEventToWireEnvelope(event)] },
        { timeoutMs: 30_000 },
      );
```

- [ ] **Step 4: Run**

Run: `cd apps/pos && pnpm exec vitest run src/lib/sync/__tests__/syncService.test.ts && pnpm exec tsc --noEmit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/lib/api.ts src/lib/sync/syncService.ts src/lib/sync/__tests__/syncService.test.ts
git commit -m "fix(pos): pushOfflineReceipts reads the fiscal-events sync response via apiPostRaw (top-level results, no data envelope)"
```

---

### Task 11: Full verification + live Tauri check

- [ ] **Step 1: Full POS quality gates**

```bash
cd apps/pos
pnpm exec vitest run
pnpm exec tsc --noEmit
pnpm exec eslint src/lib/db src/lib/sync/syncService.ts src/lib/offline src/lib/fiscal src/lib/operatorApproval src/lib/accountCharge src/lib/api.ts
cd src-tauri && cargo check && cargo test db_writer
```
Expected: all green. Fix anything that isn't before proceeding. (Do NOT run any PHPUnit.)

- [ ] **Step 2: Live verification on the Tunisia demo stack** (requires the user's machine/GUI):

```bash
docker compose -f docker-compose.demo.yml up -d --wait   # repo root of the worktree
cd apps/pos && pnpm tauri dev                            # claim POS01 @ Tunis store
```
Then: trigger a foreground product pull (refresh products) and immediately complete a cash sale (Exact). Acceptance: sale completes; console shows `[POS][perf][receipt] fiscal tx committed { ms: <~150 }`; no `database is locked` anywhere; after sync tick, the receipt pushes (no `response.results` crash) and shows synced.

- [ ] **Step 3: Final commit + push**

```bash
git add -A && git status   # verify only intended files
git commit -m "feat(pos): single-writer offline SQLite architecture — checkout reliable + snappy under concurrent sync"
git push origin feat/parapharmacy-tunisia-demo
```

---

## Self-review notes (done at plan-writing time)

- Spec §4.1→Task 3, §4.2→Task 1, §4.3→Task 4 (busyRetry stays read-side), §4.4→Tasks 4–8, §4.5→Task 5 (perf log) + Task 9 (proof), §4.6→Task 10, §6.1→Task 2, §6.2→Task 1, §6.3→Task 9, §6.4→Task 5, §6.5→Task 10, §6.6→Task 3, §6.7→Task 11. ESLint write-path guard is explicitly out of scope (spec §8 follow-up).
- Known judgment calls for the executor: exact fixture/helper names inside existing test files (Tasks 5, 7, 10) must be read from those files first; the plan pins the assertion contracts, not the fixture spellings.
- Type consistency: `SqlSurface` (from `FiscalEventEngine.ts`) is the only handle type added to signatures; casts to the plugin `Database` use `as unknown as Database`, matching `asDatabase()` precedent.
