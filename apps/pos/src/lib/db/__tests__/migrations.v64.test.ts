import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { runMigrationsUpTo, runMigrationVersion } from './helpers/migrationTestHelpers';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import {
  getToleranceAutoAcceptCount,
  recordToleranceAutoAccept,
} from '@/lib/db/repositories/toleranceAutoAcceptRepository';

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  pk: number;
  dflt_value: string | null;
}

const SHIFT = '019eb000-0000-7000-8000-000000000001';

describe('Migration v64 — durable per-shift tender-tolerance auto-accept budget', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('creates tolerance_auto_accepts keyed by shift id', async () => {
    await runMigrationsUpTo(adapter, 63);
    await runMigrationVersion(adapter, 64);

    const columns = await adapter.select<ColumnInfo[]>(
      'PRAGMA table_info(tolerance_auto_accepts)',
    );
    const byName = new Map(columns.map((c) => [c.name, c]));

    // The shift id is the PK: closing and reopening a shift is exactly the
    // event that resets the budget.
    expect(byName.get('shift_id')).toMatchObject({ type: 'TEXT', pk: 1 });
    expect(byName.get('accept_count')).toMatchObject({ type: 'INTEGER', notnull: 1 });
    expect(byName.get('updated_at')?.dflt_value).toContain("datetime('now')");
  });

  it('is idempotent (CREATE TABLE IF NOT EXISTS) and preserves rows', async () => {
    await runMigrationsUpTo(adapter, 64);
    await recordToleranceAutoAccept(adapter.asDatabase(), SHIFT);

    await runMigrationVersion(adapter, 64);

    expect(await getToleranceAutoAcceptCount(adapter.asDatabase(), SHIFT)).toBe(1);
  });
});

describe('toleranceAutoAcceptRepository', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 64);
  });

  afterEach(() => {
    adapter.close();
  });

  it('reports zero for a shift that has spent nothing', async () => {
    expect(await getToleranceAutoAcceptCount(adapter.asDatabase(), SHIFT)).toBe(0);
  });

  it('increments inside SQLite and returns the new count', async () => {
    const db = adapter.asDatabase();
    expect(await recordToleranceAutoAccept(db, SHIFT)).toBe(1);
    expect(await recordToleranceAutoAccept(db, SHIFT)).toBe(2);
    expect(await getToleranceAutoAcceptCount(db, SHIFT)).toBe(2);
  });

  it('does not increment a read-modify-write race away', async () => {
    // The `accept_count + 1` lives in the SQL, so overlapping checkouts on the
    // same shift cannot both read N and both write N+1.
    const db = adapter.asDatabase();
    await Promise.all(
      Array.from({ length: 10 }, () => recordToleranceAutoAccept(db, SHIFT)),
    );
    expect(await getToleranceAutoAcceptCount(db, SHIFT)).toBe(10);
  });

  it('keeps each shift budget separate', async () => {
    const db = adapter.asDatabase();
    await recordToleranceAutoAccept(db, SHIFT);
    await recordToleranceAutoAccept(db, SHIFT);
    await recordToleranceAutoAccept(db, 'shift-two');

    expect(await getToleranceAutoAcceptCount(db, SHIFT)).toBe(2);
    expect(await getToleranceAutoAcceptCount(db, 'shift-two')).toBe(1);
    // A brand new shift starts with a fresh budget.
    expect(await getToleranceAutoAcceptCount(db, 'shift-three')).toBe(0);
  });
});

describe('tolerance auto-accept budget survives a process restart', () => {
  let dir: string;
  let file: string;

  beforeEach(() => {
    dir = mkdtempSync(join(tmpdir(), 'izipos-t6-'));
    file = join(dir, 'izipos-company.db');
  });

  afterEach(() => {
    rmSync(dir, { recursive: true, force: true });
  });

  it('reads back the spent budget from a NEW connection to the same file', async () => {
    // Session 1 — spend the whole budget, then close the database entirely.
    const first = new SqliteTestAdapter(file);
    await runMigrationsUpTo(first, 64);
    for (let i = 0; i < 10; i += 1) {
      await recordToleranceAutoAccept(first.asDatabase(), SHIFT);
    }
    expect(await getToleranceAutoAcceptCount(first.asDatabase(), SHIFT)).toBe(10);
    first.close();

    // Session 2 — a cold start: new process, new connection to the same file.
    // No migrations are re-run because production's runner skips versions it
    // has already applied; v64 re-application is covered by the idempotency
    // test above.
    const second = new SqliteTestAdapter(file);
    try {
      expect(await getToleranceAutoAcceptCount(second.asDatabase(), SHIFT)).toBe(10);
      // And the budget is still spendable/observable, not just readable.
      expect(await recordToleranceAutoAccept(second.asDatabase(), SHIFT)).toBe(11);
    } finally {
      second.close();
    }
  });
});
