import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import {
  setWriter,
  withWriteTransaction,
  enqueueWrite,
  wrapDatabaseWithSyncGate,
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
async function fiscalCheckout(label: string, startOrder: string[]): Promise<number> {
  return withWriteTransaction('fiscal', async (tx) => {
    startOrder.push(label);
    const rows = await tx.select<Array<{ seq: number }>>("SELECT seq FROM chain WHERE terminal_id = 'T1'");
    const next = rows[0]!.seq + 1;
    await tx.execute("INSERT INTO fiscal_like (seq, terminal_id) VALUES ($1, 'T1')", [next]);
    await tx.execute("UPDATE chain SET seq = $1 WHERE terminal_id = 'T1'", [next]);
    return next;
  });
}

describe('checkout under concurrent bulk sync (two real connections, string boundary)', () => {
  it('completes gap-free and preempts queued sync chunks', async () => {
    // The pool survivor, gated in place like production getDatabase() does.
    const syncDb = stringBoundary(poolConn);
    wrapDatabaseWithSyncGate(syncDb as never);
    const startOrder: string[] = [];

    // Flood: 30 chunks of 50 rows through the POOL connection on the sync lane.
    const flood = Promise.all(
      Array.from({ length: 30 }, (_, chunk) =>
        enqueueWrite('sync', async () => {
          startOrder.push(`sync${chunk}`);
          const values = Array.from({ length: 50 }, (_, j) =>
            `('p-${chunk}-${j}', 'Product ${chunk}-${j}')`).join(', ');
          await stringBoundary(poolConn).execute(`INSERT INTO products_like (id, name) VALUES ${values}`);
        })),
    );

    // Three checkouts are REQUESTED while the flood is queued (only the
    // first sync chunk has started — enqueueWrite runs synchronously up to
    // the first await).
    const checkouts = Promise.all([
      fiscalCheckout('fiscal1', startOrder),
      fiscalCheckout('fiscal2', startOrder),
      fiscalCheckout('fiscal3', startOrder),
    ]);
    const [s1, s2, s3] = await checkouts;
    await flood;

    // 1. All checkouts completed with a strictly sequential, gap-free chain.
    expect([s1, s2, s3]).toEqual([1, 2, 3]);
    const events = await writerConn.select<Array<{ seq: number }>>('SELECT seq FROM fiscal_like ORDER BY seq');
    expect(events.map((e) => e.seq)).toEqual([1, 2, 3]);

    // 2. Every sync row landed (the gate starves nobody).
    const count = await writerConn.select<Array<{ n: number }>>('SELECT COUNT(*) AS n FROM products_like');
    expect(count[0]!.n).toBe(1500);

    // 3. Priority: the three checkouts ran immediately after the ONE sync
    //    chunk that was already in flight — they preempted the other 29
    //    queued chunks exactly.
    expect(startOrder.slice(0, 4)).toEqual(['sync0', 'fiscal1', 'fiscal2', 'fiscal3']);

    // 4. The in-place gated execute path also works against the writer's
    //    committed state (cross-connection WAL visibility).
    await syncDb.execute("INSERT INTO products_like (id, name) VALUES ('extra', 'x')");
    const extra = await writerConn.select<Array<{ n: number }>>("SELECT COUNT(*) AS n FROM products_like WHERE id = 'extra'");
    expect(extra[0]!.n).toBe(1);
  });
});
