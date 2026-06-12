import { describe, it, expect, vi } from 'vitest';
import { isDatabaseLockedError, withBusyRetry, wrapDatabaseWithBusyRetry } from '../busyRetry';

/**
 * Root cause (POS checkout "Échec du paiement"): the Tauri SQL plugin wraps a
 * SQLx connection POOL. Each db.execute/db.select borrows a different physical
 * connection, so the cashier's receipt path and the background sync scheduler
 * contend and SQLite returns `(code: 5) database is locked` (or 517 BUSY_SNAPSHOT
 * mid-tx). The plugin rejects with a plain STRING, not an Error. busy_timeout —
 * which the connection setup ASSUMES SQLx defaults to 5s — is not waiting in the
 * field. withBusyRetry reproduces busy_timeout at the app layer, pool-agnostic.
 */
describe('isDatabaseLockedError', () => {
  it('matches the Tauri string for plain BUSY (code 5)', () => {
    expect(
      isDatabaseLockedError('error returned from database: (code: 5) database is locked'),
    ).toBe(true);
  });

  it('matches BUSY_SNAPSHOT (code 517)', () => {
    expect(
      isDatabaseLockedError('error returned from database: (code: 517) database is locked'),
    ).toBe(true);
  });

  it('matches a real Error object too', () => {
    expect(isDatabaseLockedError(new Error('database is locked'))).toBe(true);
  });

  it('does NOT match unrelated errors (must not retry those)', () => {
    expect(isDatabaseLockedError('UNIQUE constraint failed: fiscal_events.id')).toBe(false);
    expect(isDatabaseLockedError('duplicate column name: chain_context')).toBe(false);
  });
});

describe('withBusyRetry', () => {
  const noSleep = () => Promise.resolve();

  it('retries a locked operation then returns its value', async () => {
    let attempts = 0;
    const op = vi.fn(async () => {
      attempts++;
      if (attempts < 3) throw 'error returned from database: (code: 5) database is locked';
      return 'ok';
    });

    const result = await withBusyRetry(op, { maxRetries: 5, sleep: noSleep });

    expect(result).toBe('ok');
    expect(op).toHaveBeenCalledTimes(3);
  });

  it('does not retry a non-lock error', async () => {
    const op = vi.fn(async () => {
      throw new Error('UNIQUE constraint failed');
    });

    await expect(withBusyRetry(op, { maxRetries: 5, sleep: noSleep })).rejects.toThrow(
      'UNIQUE constraint failed',
    );
    expect(op).toHaveBeenCalledTimes(1);
  });

  it('rethrows the lock error after exhausting retries', async () => {
    const op = vi.fn(async () => {
      throw 'error returned from database: (code: 5) database is locked';
    });

    await expect(withBusyRetry(op, { maxRetries: 3, sleep: noSleep })).rejects.toBe(
      'error returned from database: (code: 5) database is locked',
    );
    // initial try + 3 retries
    expect(op).toHaveBeenCalledTimes(4);
  });
});

describe('wrapDatabaseWithBusyRetry', () => {
  it('wraps execute + select so locked calls transparently retry', async () => {
    let execAttempts = 0;
    const fakeDb = {
      execute: vi.fn(async () => {
        execAttempts++;
        if (execAttempts < 2) throw 'error returned from database: (code: 5) database is locked';
        return { rowsAffected: 1 };
      }),
      select: vi.fn(async () => [{ id: 'x' }]),
    };

    const execSpy = fakeDb.execute; // wrapping replaces fakeDb.execute with the wrapper
    wrapDatabaseWithBusyRetry(fakeDb as never, { sleep: () => Promise.resolve() });

    const res = await (fakeDb as { execute: (s: string) => Promise<unknown> }).execute('BEGIN');
    expect(res).toEqual({ rowsAffected: 1 });
    expect(execSpy).toHaveBeenCalledTimes(2); // retried once

    const rows = await (fakeDb as { select: (s: string) => Promise<unknown> }).select('SELECT 1');
    expect(rows).toEqual([{ id: 'x' }]);
  });
});
