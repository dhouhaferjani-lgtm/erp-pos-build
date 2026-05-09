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

/**
 * Multi-statement SQL contains a `;` followed by more SQL (i.e. additional
 * statements after the trailing terminator). `node:sqlite`'s `prepare()`
 * only accepts single statements; multi-statement blocks must go through
 * `exec()`. Migration `m.sql` blocks are the canonical caller.
 *
 * Heuristic: strip an optional trailing semicolon, then look for any
 * remaining `;` followed by non-whitespace. Test fixtures don't contain
 * `;` inside string literals, so this is safe for the fixture surface.
 */
function isMultiStatement(sql: string): boolean {
  const trimmed = sql.trim().replace(/;\s*$/, '');
  return /;\s*\S/.test(trimmed);
}

export class SqliteTestAdapter {
  readonly inner: DatabaseSync;

  constructor() {
    this.inner = new DatabaseSync(':memory:');
  }

  async execute(sql: string, params?: unknown[]): Promise<ExecResult> {
    // Multi-statement migration blocks must go through `exec`. A
    // single-statement query (with or without params) goes through
    // `prepare(...).run()` so callers get an accurate `rowsAffected`
    // — production `execute` defaults `params = []`, which would
    // otherwise be misrouted to `exec` and silently lose rowsAffected.
    if (isMultiStatement(sql)) {
      this.inner.exec(sql);
      return { rowsAffected: 0 };
    }
    const stmt = this.inner.prepare(sql);
    const bound = bindParams(params);
    const res = bound ? stmt.run(bound) : stmt.run();
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
