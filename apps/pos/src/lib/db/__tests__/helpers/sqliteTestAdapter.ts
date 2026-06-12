/**
 * Test-only adapter that wraps `better-sqlite3` so it matches the subset of
 * `@tauri-apps/plugin-sql` Database methods our repositories and migrations
 * call:
 *
 *   - `execute(sql, params?) => Promise<{ rowsAffected, lastInsertId? }>`
 *   - `select<T>(sql, params?) => Promise<T>`
 *
 * This lets us exercise the real migrations against a real SQLite engine
 * from Vitest, catching schema/precision regressions that pure-mock tests
 * can't see. Keep this file test-only — production code must stay on the
 * Tauri plugin.
 *
 * **Round-2 T32-P3: migrated from `node:sqlite` to `better-sqlite3`.**
 * `node:sqlite` requires Node 22.5+; CI pins Node 20, so the previous
 * skip-on-unavailable guard caused every SQLite-backed vitest suite to
 * skip silently on CI. `better-sqlite3` is a long-standing npm package that
 * runs on Node 18+ (matches the CI `NODE_VERSION: '20'`) and exposes the
 * same synchronous prepare/run/all API surface. Migration tests are now
 * actually merge-gated.
 */

import BetterSqlite3 from 'better-sqlite3';
import type { Database as BetterSqliteDatabase, Statement } from 'better-sqlite3';
import type Database from '@tauri-apps/plugin-sql';

interface ExecResult {
  rowsAffected: number;
  lastInsertId?: number;
}

/**
 * `better-sqlite3` supports `$name` named parameters out of the box. Our
 * callers use Postgres-style positional `$1`, `$2` … placeholders; map them
 * to a `{ $1: …, $2: … }` bind object so `Statement.run(bound)` /
 * `Statement.all(bound)` binds them by name.
 */
function bindParams(params?: unknown[]): Record<string, unknown> | undefined {
  if (!params || params.length === 0) return undefined;
  const out: Record<string, unknown> = {};
  for (let i = 0; i < params.length; i++) {
    // better-sqlite3 named params drop the leading `$` in the bind object
    // key (unlike `node:sqlite` which expects the literal `$1`).
    out[String(i + 1)] = params[i];
  }
  return out;
}

function isSelect(sql: string): boolean {
  return /^\s*(SELECT|PRAGMA|WITH)\b/i.test(sql);
}

/**
 * Multi-statement SQL contains a `;` followed by more SQL (i.e. additional
 * statements after the trailing terminator). `better-sqlite3`'s `prepare()`
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
  readonly inner: BetterSqliteDatabase;

  constructor(filename: string = ':memory:') {
    this.inner = new BetterSqlite3(filename);
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
    const stmt: Statement = this.inner.prepare(sql);
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
    const stmt: Statement = this.inner.prepare(sql);
    const bound = bindParams(params);
    const rows = bound ? stmt.all(bound) : stmt.all();
    // better-sqlite3 rows are plain objects already — normalize defensively
    // so downstream test equality helpers behave consistently with the prior
    // `node:sqlite` adapter shape.
    return (rows as Array<Record<string, unknown>>).map((r) => ({ ...r })) as unknown as T;
  }

  close(): void {
    this.inner.close();
  }

  /** Cast to Database for repo function signatures. */
  asDatabase(): Database {
    return this as unknown as Database;
  }
}
