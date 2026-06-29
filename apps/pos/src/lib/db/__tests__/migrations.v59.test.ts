/**
 * Real-SQLite replay test for migration v59 — `add_skin_fields_to_customers`.
 *
 * v59 uses a `run` handler (NOT a bare `sql` string) with idempotent ALTER
 * guards via `isDuplicateColumnError()`, mirroring the v58 pattern.
 *
 * Assertions:
 *   (a) Fresh path: seed `sync_metadata['customers.updated_since']`, apply
 *       migrations through v59, assert columns `skin_type` and `skin_advice_note`
 *       exist AND `customers.updated_since` is gone (forces a full customer
 *       re-fetch so upgraded devices backfill the new skin columns on next sync).
 *   (b) Idempotency: pre-create `skin_type` before running v59 (simulates a
 *       device that partially applied or had a schema drift), then run v59 and
 *       assert both columns still exist and the cursor is still deleted.
 *
 * Uses `better-sqlite3` via `SqliteTestAdapter` (canonical pattern since T32-P3).
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';

async function runMigrationsUpTo(adapter: SqliteTestAdapter, maxVersion: number): Promise<void> {
  for (const m of migrations) {
    if (m.version > maxVersion) continue;
    if (m.run) {
      await m.run(adapter.asDatabase());
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  dflt_value: string | number | null;
}

describe('Migration v59 — add_skin_fields_to_customers', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds skin_type and skin_advice_note columns (TEXT, nullable) to customers', async () => {
    await runMigrationsUpTo(adapter, 59);

    const cols = await adapter.select<ColumnInfo[]>('PRAGMA table_info(customers)');

    const skinType = cols.find((c) => c.name === 'skin_type');
    const skinAdviceNote = cols.find((c) => c.name === 'skin_advice_note');

    expect(skinType, 'customers.skin_type must exist after v59').toBeDefined();
    expect(skinType?.type).toBe('TEXT');

    expect(skinAdviceNote, 'customers.skin_advice_note must exist after v59').toBeDefined();
    expect(skinAdviceNote?.type).toBe('TEXT');
  });

  it('clears customers.updated_since cursor from sync_metadata so the next sync is a full re-fetch', async () => {
    // Apply v1..v58 to set up the customers + sync_metadata tables, then seed
    // a customers.updated_since cursor that simulates an existing install.
    await runMigrationsUpTo(adapter, 58);

    // Seed the cursor that an earlier delta sync left in place.
    await adapter.execute(
      `INSERT INTO sync_metadata (key, value, updated_at)
       VALUES ($1, $2, datetime('now'))
       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')`,
      ['customers.updated_since', '2026-06-01T10:00:00Z'],
    );

    // Confirm it exists before v59.
    const before = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'customers.updated_since'",
    );
    expect(before).toHaveLength(1);

    // Run only v59.
    const v59 = migrations.find((m) => m.version === 59);
    expect(v59, 'v59 must exist in migrations array').toBeDefined();
    expect(v59!.run, 'v59 must use a run handler (not a bare sql string)').toBeDefined();
    await v59!.run!(adapter.asDatabase());

    // The cursor MUST be gone.
    const after = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'customers.updated_since'",
    );
    expect(
      after,
      'v59 must DELETE customers.updated_since from sync_metadata to trigger a full customer re-fetch',
    ).toHaveLength(0);
  });

  it('does not error when customers.updated_since cursor is absent (fresh install path)', async () => {
    await runMigrationsUpTo(adapter, 58);

    // No cursor seeded — fresh install.
    const before = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'customers.updated_since'",
    );
    expect(before).toHaveLength(0);

    const v59 = migrations.find((m) => m.version === 59)!;
    // Must resolve without throwing even when no cursor row exists.
    await expect(v59.run!(adapter.asDatabase())).resolves.toBeUndefined();
  });

  it('(idempotency) completes without error when skin_type already exists, and both columns + cursor deletion are correct', async () => {
    // Apply v1..v58 first to set up the baseline schema.
    await runMigrationsUpTo(adapter, 58);

    // Pre-create skin_type to simulate a partially-applied migration or schema drift.
    await adapter.execute('ALTER TABLE customers ADD COLUMN skin_type TEXT');

    // Seed a customers.updated_since cursor so we can verify it gets deleted.
    await adapter.execute(
      `INSERT INTO sync_metadata (key, value, updated_at)
       VALUES ($1, $2, datetime('now'))`,
      ['customers.updated_since', '2026-06-01T12:00:00Z'],
    );

    // Run v59 — must not throw despite skin_type already existing.
    const v59 = migrations.find((m) => m.version === 59);
    expect(v59, 'v59 must exist in migrations array').toBeDefined();
    await expect(v59!.run!(adapter.asDatabase())).resolves.toBeUndefined();

    // Both columns must now exist.
    const cols = await adapter.select<ColumnInfo[]>('PRAGMA table_info(customers)');
    const names = cols.map((c) => c.name);

    expect(names, 'skin_type must be present after idempotent v59 run').toContain('skin_type');
    expect(names, 'skin_advice_note must be present after idempotent v59 run').toContain(
      'skin_advice_note',
    );

    // Cursor must still be deleted.
    const cursor = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'customers.updated_since'",
    );
    expect(
      cursor,
      'customers.updated_since must be deleted even in the idempotent run',
    ).toHaveLength(0);
  });
});
