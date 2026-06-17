/**
 * Real-SQLite replay test for migration v57 — `add_has_variants_to_products`.
 *
 * Renumbered from v54 → v57 during the feat/pos-offline-variants → dev merge:
 * dev independently shipped v53–v55 (offline-first shifts) and migration
 * versions are a global UNIQUE key, so Spec A's product_variants migration was
 * moved to v56 and this column-add to v57 (after dev's max, v55).
 *
 * HIGH-1 adversarial-review fix: v57 must:
 *   1. Add `has_variants INTEGER NOT NULL DEFAULT 0` to the `products` table.
 *   2. Delete the `products_last_sync` cursor from `sync_metadata` so the
 *      next pullProductsCore does a FULL product re-fetch and writes the
 *      server-authoritative `has_variants` value to all existing rows.
 *      Without the cursor reset, unchanged variant products stay stuck at
 *      `has_variants = 0` because the delta-keyed sync never re-fetches them.
 *
 * Uses `better-sqlite3` via `SqliteTestAdapter` (the canonical pattern since
 * T32-P3 migrated away from node:sqlite, which required Node 22+ and silently
 * skipped on CI's Node 20).
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

describe('Migration v57 — add_has_variants_to_products', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds has_variants column (INTEGER NOT NULL DEFAULT 0) to products', async () => {
    await runMigrationsUpTo(adapter, 57);

    const cols = await adapter.select<ColumnInfo[]>('PRAGMA table_info(products)');
    const col = cols.find((c) => c.name === 'has_variants');

    expect(col, 'products.has_variants must exist after v57').toBeDefined();
    expect(col?.type).toBe('INTEGER');
    expect(col?.notnull).toBe(1);
    expect(String(col?.dflt_value)).toBe('0');
  });

  it('HIGH-1: clears products_last_sync cursor from sync_metadata so the next sync is a full re-fetch', async () => {
    // Apply v1..v56 to set up the products + sync_metadata tables, then seed
    // a products_last_sync cursor that simulates an existing install that has
    // already performed a delta sync. v57 must DELETE this row so pullProductsCore
    // fetches ALL products (including variant ones) and writes has_variants.
    await runMigrationsUpTo(adapter, 56);

    // Seed the cursor that an earlier delta sync left in place.
    await adapter.execute(
      `INSERT INTO sync_metadata (key, value, updated_at)
       VALUES ($1, $2, datetime('now'))
       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')`,
      ['products_last_sync', '2026-06-01T10:00:00Z'],
    );

    // Confirm it exists before v57.
    const before = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'products_last_sync'",
    );
    expect(before).toHaveLength(1);

    // Apply only v57 (not the full runMigrationsUpTo which would re-use the
    // cached instance — we manually run the v57 sql directly to isolate the delta).
    const v57 = migrations.find((m) => m.version === 57);
    expect(v57, 'v57 must exist in migrations array').toBeDefined();
    expect(v57!.sql, 'v57 must use sql field for multi-statement execution').toBeTruthy();
    await adapter.execute(v57!.sql);

    // The cursor MUST be gone.
    const after = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'products_last_sync'",
    );
    expect(
      after,
      'v57 must DELETE products_last_sync from sync_metadata to trigger a full product re-fetch',
    ).toHaveLength(0);
  });

  it('HIGH-1: products.has_variants column is usable after the cursor-clearing v57 run', async () => {
    // Run v1..v56, seed a cursor, run v57, then verify both effects hold together:
    // the column exists AND the cursor is gone, so a product can be upserted
    // with has_variants=1.
    await runMigrationsUpTo(adapter, 56);

    await adapter.execute(
      `INSERT INTO sync_metadata (key, value, updated_at)
       VALUES ($1, $2, datetime('now'))`,
      ['products_last_sync', '2026-06-01T10:00:00Z'],
    );

    const v57 = migrations.find((m) => m.version === 57)!;
    await adapter.execute(v57.sql);

    // Cursor cleared.
    const cursor = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'products_last_sync'",
    );
    expect(cursor).toHaveLength(0);

    // Column usable — upsert a variant product and read it back.
    await adapter.execute(
      `INSERT INTO products (id, name, sku, sale_price, stock_quantity, has_variants)
       VALUES ($1, $2, $3, $4, $5, $6)`,
      ['prod-with-variants', 'Tire 205/55 R16', 'TIRE-205-55-R16', '89.000', 0, 1],
    );

    const rows = await adapter.select<Array<{ id: string; has_variants: number }>>(
      "SELECT id, has_variants FROM products WHERE id = $1",
      ['prod-with-variants'],
    );
    expect(rows).toHaveLength(1);
    expect(rows[0]!.has_variants).toBe(1);
  });

  it('existing products default to has_variants=0 (non-destructive backfill)', async () => {
    // Verify pre-v57 rows are unaffected: a product inserted before v57 runs
    // gets has_variants=0 by default — the standard-retail path stays intact.
    await runMigrationsUpTo(adapter, 56);

    await adapter.execute(
      `INSERT INTO products (id, name, sku, sale_price, stock_quantity)
       VALUES ($1, $2, $3, $4, $5)`,
      ['prod-no-variants', 'Oil Filter', 'OIL-FILTER', '12.000', 100],
    );

    const v57 = migrations.find((m) => m.version === 57)!;
    await adapter.execute(v57.sql);

    const rows = await adapter.select<Array<{ id: string; has_variants: number }>>(
      "SELECT id, has_variants FROM products WHERE id = $1",
      ['prod-no-variants'],
    );
    expect(rows).toHaveLength(1);
    expect(
      rows[0]!.has_variants,
      'pre-existing standard-retail product must default to has_variants = 0',
    ).toBe(0);
  });

  it('does not error when products_last_sync cursor is absent (fresh install path)', async () => {
    // On a brand-new install, sync_metadata has no products_last_sync row yet.
    // The DELETE in v57 must be a no-op (zero rows affected), not an error.
    await runMigrationsUpTo(adapter, 56);

    // No cursor seeded — fresh install.
    const before = await adapter.select<Array<{ value: string }>>(
      "SELECT value FROM sync_metadata WHERE key = 'products_last_sync'",
    );
    expect(before).toHaveLength(0);

    const v57 = migrations.find((m) => m.version === 57)!;
    // Must resolve without throwing even when no cursor row exists.
    await expect(adapter.execute(v57.sql)).resolves.toBeDefined();
  });
});
