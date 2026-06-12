import { describe, it, expect } from 'vitest';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';

/**
 * Regression for the production migration-abort bug.
 *
 * The other migration tests run against `better-sqlite3`, whose `.run()`/`.exec()`
 * throw a real JS `Error`. Production runs against `@tauri-apps/plugin-sql`, whose
 * `invoke` REJECTS WITH A PLAIN STRING, e.g.
 *   "error returned from database: (code: 1) duplicate column name: chain_context"
 *
 * The "duplicate column" guards in migrations.ts used
 * `error instanceof Error ? error.message : ''`, which evaluates to `''` for a
 * string error → the guard never matches → the (harmless, expected) duplicate at
 * migration v45 (ALTER fiscal_events ADD chain_context, already created by v37)
 * re-throws and ABORTS THE ENTIRE RUN. Result in the field: v45+ migrations
 * (queued_audit_events v46, location_stock v50) never run and product sync never
 * happens. better-sqlite3 masked it; this adapter reproduces the string boundary.
 */
class TauriStringErrorAdapter {
  constructor(private readonly inner: SqliteTestAdapter) {}

  async execute(sql: string, params?: unknown[]): Promise<unknown> {
    try {
      return await this.inner.execute(sql, params);
    } catch (e) {
      // Mimic the Tauri SQL plugin: reject with a STRING, not an Error.
      throw `error returned from database: (code: 1) ${(e as Error).message}`;
    }
  }

  async select<T>(sql: string, params?: unknown[]): Promise<T> {
    return this.inner.select<T>(sql, params);
  }
}

async function runAllMigrations(db: { execute: (sql: string, params?: unknown[]) => Promise<unknown> }): Promise<void> {
  for (const m of migrations) {
    if (m.run) {
      await m.run(db);
    } else {
      await db.execute(m.sql);
    }
  }
}

describe('migrations under the Tauri string-error boundary', () => {
  it('completes the full run even though the SQL plugin throws STRING errors (not Error objects)', async () => {
    const inner = new SqliteTestAdapter();
    const adapter = new TauriStringErrorAdapter(inner);

    let runError: unknown = null;
    try {
      await runAllMigrations(adapter);
    } catch (e) {
      runError = e;
    }

    // The expected, guarded duplicate-column must NOT abort the run.
    expect(runError).toBeNull();

    // Tables created by migrations AFTER v45 only exist if the run did not abort.
    const tables = await inner.select<{ name: string }[]>(
      "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('location_stock','queued_audit_events')",
    );
    const names = tables.map((t) => t.name);
    expect(names).toContain('queued_audit_events'); // v46
    expect(names).toContain('location_stock'); // v50

    inner.close();
  });
});
