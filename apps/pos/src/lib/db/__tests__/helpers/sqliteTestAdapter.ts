/**
 * Test-only adapter that wraps Node's built-in `node:sqlite` (Node 22.5+) so
 * it matches the subset of `@tauri-apps/plugin-sql` Database methods our
 * repositories and migrations call:
 *
 *   - `execute(sql, params?) => Promise<{ rowsAffected, lastInsertId? }>`
 *   - `select<T>(sql, params?) => Promise<T>`
 *
 * This lets us exercise the real migrations against a real SQLite engine
 * from Vitest, catching schema/precision regressions that pure-mock tests
 * can't see. Keep this file test-only — production code must stay on the
 * Tauri plugin.
 */

import { DatabaseSync, type SQLInputValue } from 'node:sqlite';
import type Database from '@tauri-apps/plugin-sql';

interface ExecResult {
  rowsAffected: number;
  lastInsertId?: number;
}

/** Convert positional `$1, $2 …` params into the `{ $1: …, $2: … }` form node:sqlite binds. */
function bindParams(params?: unknown[]): Record<string, SQLInputValue> | undefined {
  if (!params || params.length === 0) return undefined;
  const out: Record<string, SQLInputValue> = {};
  for (let i = 0; i < params.length; i++) {
    out[`$${i + 1}`] = params[i] as SQLInputValue;
  }
  return out;
}

function isSelect(sql: string): boolean {
  return /^\s*(SELECT|PRAGMA|WITH)\b/i.test(sql);
}

export class SqliteTestAdapter {
  readonly inner: DatabaseSync;

  constructor() {
    this.inner = new DatabaseSync(':memory:');
  }

  async execute(sql: string, params?: unknown[]): Promise<ExecResult> {
    const bound = bindParams(params);
    if (!bound) {
      // Multi-statement blocks (migration.sql) land here; `exec` handles them.
      this.inner.exec(sql);
      return { rowsAffected: 0 };
    }
    const stmt = this.inner.prepare(sql);
    const res = stmt.run(bound);
    return {
      rowsAffected: Number(res.changes),
      lastInsertId: Number(res.lastInsertRowid),
    };
  }

  async select<T>(sql: string, params?: unknown[]): Promise<T> {
    if (!isSelect(sql)) {
      throw new Error('SqliteTestAdapter.select called with non-SELECT statement: ' + sql);
    }
    const stmt = this.inner.prepare(sql);
    const bound = bindParams(params);
    const rows = bound ? stmt.all(bound) : stmt.all();
    // node:sqlite rows are null-prototype objects; normalize to plain objects so
    // test equality helpers behave as expected.
    return rows.map((r) => ({ ...(r as Record<string, unknown>) })) as unknown as T;
  }

  close(): void {
    this.inner.close();
  }

  /** Cast to Database for repo function signatures. */
  asDatabase(): Database {
    return this as unknown as Database;
  }
}
