/**
 * Real-SQLite replay test for migration v30 — `add_menu_composite_columns`.
 *
 * v30 adds `sellable_id` and `menu_category_id` to the `products` table so
 * Menu-tenant catalog rows can encode the (sellable, category) pair as a
 * composite primary key. The column types are nullable TEXT — for
 * standard-retail tenants the columns stay NULL and `id` continues to equal
 * the bare sellable UUID. For Menu-tenant rows written by
 * `flattenMenuToProducts` post-v30, both columns are populated and `id` is
 * the colon-delimited composite.
 *
 * Mirrors the v28 / v29 test pattern: run migrations 1..29, run only v30,
 * assert the columns appear with the right types, prove idempotency, and
 * verify pre-existing rows backfill cleanly.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';

const nodeSqliteAvailable = (() => {
  try {
    // node:sqlite is the canonical real-engine for these replay tests; the
    // require() form mirrors `migrations.v28.test.ts`'s availability gate so
    // we stay consistent with the surrounding pattern.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function runMigrationsUpTo(adapter: SqliteTestAdapter, maxVersion: number): Promise<void> {
  for (const m of migrations) {
    if (m.version > maxVersion) continue;
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

async function runOnlyV30(adapter: SqliteTestAdapter): Promise<void> {
  const v30 = migrations.find((m) => m.version === 30);
  if (!v30) {
    throw new Error('Migration v30 not found in migrations.ts');
  }
  if (!v30.run) {
    throw new Error('Migration v30 must have a run() function');
  }
  await v30.run(adapter);
}

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  dflt_value: string | number | null;
}

d('Migration v30 — add_menu_composite_columns', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds sellable_id and menu_category_id columns (nullable TEXT) to products', async () => {
    await runMigrationsUpTo(adapter, 29);
    await runOnlyV30(adapter);

    const cols = await adapter.select<ColumnInfo[]>('PRAGMA table_info(products)');
    const sellableCol = cols.find((c) => c.name === 'sellable_id');
    const categoryCol = cols.find((c) => c.name === 'menu_category_id');

    expect(sellableCol, 'products.sellable_id must exist after v30').toBeDefined();
    expect(sellableCol?.type).toBe('TEXT');
    // Nullable so standard-retail rows can leave them NULL.
    expect(sellableCol?.notnull).toBe(0);

    expect(categoryCol, 'products.menu_category_id must exist after v30').toBeDefined();
    expect(categoryCol?.type).toBe('TEXT');
    expect(categoryCol?.notnull).toBe(0);
  });

  it('is idempotent: running v30 twice does not throw on duplicate column', async () => {
    await runMigrationsUpTo(adapter, 29);
    await runOnlyV30(adapter);
    await expect(runOnlyV30(adapter)).resolves.toBeUndefined();
  });

  it('leaves pre-existing product rows with NULL sellable_id / menu_category_id (no destructive backfill)', async () => {
    // Standard-retail tenants on a pre-v30 install have rows where `id`
    // already IS the bare sellable UUID. Migration v30 must not touch
    // existing rows — `getAllProducts` continues to project `id` as the
    // canonical reference and the new columns stay NULL until a fresh
    // sync rewrites them.
    await runMigrationsUpTo(adapter, 29);

    await adapter.execute(
      `INSERT INTO products (id, name, sku, sale_price, stock_quantity)
       VALUES ($1, $2, $3, $4, $5)`,
      ['11111111-1111-1111-1111-111111111111', 'Pre-v30 Product', 'SKU-PRE', '10.00', 50],
    );

    await runOnlyV30(adapter);

    const rows = await adapter.select<
      Array<{
        id: string;
        sellable_id: string | null;
        menu_category_id: string | null;
      }>
    >('SELECT id, sellable_id, menu_category_id FROM products');

    expect(rows).toHaveLength(1);
    expect(rows[0]!.id).toBe('11111111-1111-1111-1111-111111111111');
    expect(rows[0]!.sellable_id).toBeNull();
    expect(rows[0]!.menu_category_id).toBeNull();
  });

  it('accepts a Menu-tenant composite-id row (id with colon, sellable_id and menu_category_id populated)', async () => {
    await runMigrationsUpTo(adapter, 29);
    await runOnlyV30(adapter);

    const sellable = '22222222-2222-2222-2222-222222222222';
    const category = '33333333-3333-3333-3333-333333333333';
    const composite = `${sellable}_${category}`;

    await adapter.execute(
      `INSERT INTO products (
        id, name, sku, sale_price, stock_quantity, sellable_id, menu_category_id
       ) VALUES ($1, $2, $3, $4, $5, $6, $7)`,
      [composite, 'Coca (Drinks)', 'COCA-D', '3.00', 999, sellable, category],
    );

    const row = await adapter.select<
      Array<{
        id: string;
        sellable_id: string | null;
        menu_category_id: string | null;
      }>
    >('SELECT id, sellable_id, menu_category_id FROM products WHERE id = $1', [composite]);

    expect(row).toHaveLength(1);
    expect(row[0]!.id).toBe(composite);
    expect(row[0]!.sellable_id).toBe(sellable);
    expect(row[0]!.menu_category_id).toBe(category);
  });
});
