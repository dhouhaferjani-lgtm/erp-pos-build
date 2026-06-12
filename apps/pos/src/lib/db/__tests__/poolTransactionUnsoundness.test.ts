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
