/**
 * SQLite busy-lock retry — the app-layer equivalent of `PRAGMA busy_timeout`.
 *
 * The Tauri SQL plugin (`@tauri-apps/plugin-sql`) wraps a SQLx connection POOL:
 * every `execute`/`select` borrows a different physical connection, so the
 * cashier's receipt path contends with the background sync scheduler and SQLite
 * returns `(code: 5) database is locked` (plain BUSY) or `(code: 517)` mid-tx
 * (BUSY_SNAPSHOT). The plugin rejects with a STRING, not an Error. The
 * connection setup ASSUMES SQLx hands every pooled connection a 5s busy_timeout,
 * but the field evidence (writers failing immediately at receiptService.ts:262,
 * a pre-tx READ) shows that wait is not happening — and we cannot set a
 * per-connection PRAGMA reliably across a pool from JS. So we retry here.
 *
 * SQLITE_BUSY guarantees the statement had NO effect (it never acquired the
 * lock), so retrying a single statement is safe — exactly what busy_timeout
 * does internally.
 */

const LOCK_SIGNATURE = 'database is locked';

export function isDatabaseLockedError(error: unknown): boolean {
  const message = error instanceof Error ? error.message : String(error);
  return message.includes(LOCK_SIGNATURE);
}

export interface BusyRetryOptions {
  /** Number of retries AFTER the initial attempt. Default 8. */
  maxRetries?: number;
  /** Injectable sleep (tests pass a no-op). Default: exponential-ish backoff. */
  sleep?: (ms: number) => Promise<void>;
}

const defaultSleep = (ms: number): Promise<void> =>
  new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Run `op`, retrying only on the `database is locked` family. Backoff grows
 * 25,50,100,…ms capped at 250ms — ~5s of total patience over 8 retries, which
 * comfortably outlasts a sync write batch.
 */
export async function withBusyRetry<T>(
  op: () => Promise<T>,
  options: BusyRetryOptions = {},
): Promise<T> {
  const maxRetries = options.maxRetries ?? 8;
  const sleep = options.sleep ?? defaultSleep;

  let attempt = 0;
  for (;;) {
    try {
      return await op();
    } catch (error) {
      if (!isDatabaseLockedError(error) || attempt >= maxRetries) {
        throw error;
      }
      const backoff = Math.min(25 * 2 ** attempt, 250);
      attempt++;
      await sleep(backoff);
    }
  }
}

interface RetriableDatabase {
  execute: (sql: string, params?: unknown[]) => Promise<unknown>;
  select: (sql: string, params?: unknown[]) => Promise<unknown>;
}

/**
 * Wrap a Database instance's `execute`/`select` in-place so EVERY query —
 * reads (the line-262 terminal-state read), writes, and the receipt tx's
 * statements — transparently retries on lock contention. Idempotent per
 * instance because `getDatabase` wraps exactly once, right after load.
 */
export function wrapDatabaseWithBusyRetry(
  database: RetriableDatabase,
  options: BusyRetryOptions = {},
): void {
  const originalExecute = database.execute.bind(database);
  const originalSelect = database.select.bind(database);

  database.execute = (sql: string, params?: unknown[]) =>
    withBusyRetry(() => originalExecute(sql, params), options);
  database.select = (sql: string, params?: unknown[]) =>
    withBusyRetry(() => originalSelect(sql, params), options);
}
