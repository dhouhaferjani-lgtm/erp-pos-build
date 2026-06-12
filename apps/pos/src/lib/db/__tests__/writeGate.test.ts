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
