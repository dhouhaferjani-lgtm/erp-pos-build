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
 * transaction. See docs/superpowers/specs/2026-06-12-pos-offline-single-writer-design.md.
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
 * Serialize a write job. The job closes over its own DB handle (the pool
 * handle for single-statement sync writes is fine — single autocommit
 * statements are sound once the gate guarantees no concurrent fiscal
 * transaction).
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

interface GateableDatabase {
  execute: (sql: string, params?: unknown[]) => Promise<unknown>;
}

/**
 * Wrap a Database instance's `execute` IN PLACE (mirroring
 * `wrapDatabaseWithBusyRetry`) so every write issued through the pooled
 * plugin handle — sync pulls, queued-event inserts, image cache, any
 * straggler — is serialized through the sync lane, per statement. A queued
 * fiscal transaction therefore waits at most ONE statement. `select` is
 * untouched (reads never block under WAL).
 *
 * Single autocommit statements on the pool are sound once the gate
 * guarantees no concurrent fiscal transaction holds the write lock; only
 * multi-statement transactions must use the Rust writer (withWriteTransaction).
 *
 * DEADLOCK RULE: code running INSIDE a gate job (a withWriteTransaction
 * body, a migration) must never touch the pooled handle's execute — it
 * would enqueue behind itself. Tx bodies use their `tx` handle only.
 */
export function wrapDatabaseWithSyncGate(database: Database): void {
  const gateable = database as unknown as GateableDatabase;
  const originalExecute = gateable.execute.bind(database);
  gateable.execute = (sql: string, params?: unknown[]) =>
    enqueueWrite('sync', () => originalExecute(sql, params));
}

export function __resetWriteGateForTesting(): void {
  writer = null;
  lanes.fiscal.length = 0;
  lanes.sync.length = 0;
}
