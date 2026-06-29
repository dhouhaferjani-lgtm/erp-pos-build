/**
 * Real-SQLite replay test for migration v58 — `add_brand_and_parapharmacy_metadata_to_products`.
 *
 * v58 uses a `run` handler (NOT a bare `sql` string) with idempotent ALTER
 * guards via `isDuplicateColumnError()`, mirroring the v30 / v54 pattern.
 *
 * Assertions:
 *   (a) Fresh path: seed `sync_metadata['products_last_sync']`, apply migrations
 *       through v58, assert columns `brand_id`, `brand_name`, `parapharmacy_metadata`
 *       exist AND `products_last_sync` is gone (forces a full product re-fetch
 *       so upgraded devices backfill the new columns on next sync).
 *   (b) Idempotency: pre-create `brand_id` before running v58 (simulates a
 *       device that partially applied or had a schema drift), then run v58 and
 *       assert all 3 columns still exist and the cursor is still deleted.
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

describe('Migration v58 — add_brand_and_parapharmacy_metadata_to_products', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds brand_id, brand_name, parapharmacy_metadata columns (TEXT, nullable) to products', async () => {
    await runMigrationsUpTo(adapter, 58);

    const cols = await adapter.select<ColumnInfo[]>('PRAGMA table_info(products)');

    const brandId = cols.find((c) => c.name === 'brand_id');
    const brandName = cols.find((c) => c.name === 'brand_name');
    const pharaMeta = cols.find((c) => c.name === 'parapharmacy_metadata');

    expect(brandId, 'products.brand_id must exist after v58').toBeDefined();
    expect(brandId?.type).toBe('TEXT');

    expect(brandName, 'products.brand_name must exist after v58').toBeDefined();
    expect(brandName?.type).toBe('TEXT');

    expect(pharaMeta, 'products.parapharmacy_metadata must exist after v58').toBeDefined();
    expect(pharaMeta?.type).toBe('TEXT');
  });

  it('clears products_last_sync cursor from sync_metadata so the next sync is a full re-fetch', async () => {
    // Apply v1..v57 to set up the products + sync_metadata tables, then seed
    // a products_last_sync cursor that simulates an existing install.
    await runMigrationsUpTo(adapter, 57);

    // Seed the cursor that an earlier delta sync left in place.
    await adapter.execute(
      `INSERT INTO sync_metadata (key, value, updated_at)
       VALUES ($1, $2, datetime('now'))
       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')`,
      ['products_last_sync', '2026-06-01T10:00:00Z'],
    );

    // Confirm it exists before v58.
    const before = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'products_last_sync'",
    );
    expect(before).toHaveLength(1);

    // Run only v58.
    const v58 = migrations.find((m) => m.version === 58);
    expect(v58, 'v58 must exist in migrations array').toBeDefined();
    expect(v58!.run, 'v58 must use a run handler (not a bare sql string)').toBeDefined();
    await v58!.run!(adapter.asDatabase());

    // The cursor MUST be gone.
    const after = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'products_last_sync'",
    );
    expect(
      after,
      'v58 must DELETE products_last_sync from sync_metadata to trigger a full product re-fetch',
    ).toHaveLength(0);
  });

  it('does not error when products_last_sync cursor is absent (fresh install path)', async () => {
    await runMigrationsUpTo(adapter, 57);

    // No cursor seeded — fresh install.
    const before = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'products_last_sync'",
    );
    expect(before).toHaveLength(0);

    const v58 = migrations.find((m) => m.version === 58)!;
    // Must resolve without throwing even when no cursor row exists.
    await expect(v58.run!(adapter.asDatabase())).resolves.toBeUndefined();
  });

  it('(idempotency) completes without error when brand_id already exists, and all 3 columns + cursor deletion are correct', async () => {
    // Apply v1..v57 first to set up the baseline schema.
    await runMigrationsUpTo(adapter, 57);

    // Pre-create brand_id to simulate a partially-applied migration or schema drift.
    await adapter.execute('ALTER TABLE products ADD COLUMN brand_id TEXT');

    // Seed a products_last_sync cursor so we can verify it gets deleted.
    await adapter.execute(
      `INSERT INTO sync_metadata (key, value, updated_at)
       VALUES ($1, $2, datetime('now'))`,
      ['products_last_sync', '2026-06-01T12:00:00Z'],
    );

    // Run v58 — must not throw despite brand_id already existing.
    const v58 = migrations.find((m) => m.version === 58);
    expect(v58, 'v58 must exist in migrations array').toBeDefined();
    await expect(v58!.run!(adapter.asDatabase())).resolves.toBeUndefined();

    // All 3 columns must now exist.
    const cols = await adapter.select<ColumnInfo[]>('PRAGMA table_info(products)');
    const names = cols.map((c) => c.name);

    expect(names, 'brand_id must be present after idempotent v58 run').toContain('brand_id');
    expect(names, 'brand_name must be present after idempotent v58 run').toContain('brand_name');
    expect(names, 'parapharmacy_metadata must be present after idempotent v58 run').toContain(
      'parapharmacy_metadata',
    );

    // Cursor must still be deleted.
    const cursor = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'products_last_sync'",
    );
    expect(
      cursor,
      'products_last_sync must be deleted even in the idempotent run',
    ).toHaveLength(0);
  });
});
