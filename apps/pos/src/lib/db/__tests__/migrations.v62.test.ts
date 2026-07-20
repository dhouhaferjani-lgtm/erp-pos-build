import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  applyAllMigrations,
  runMigrationsUpTo,
  runMigrationVersion,
} from './helpers/migrationTestHelpers';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
}

describe('Migration v62 — add quantity_decimals to products', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds a nullable INTEGER column without losing existing product rows and is idempotent', async () => {
    await runMigrationsUpTo(adapter, 61);
    await adapter.execute(
      `INSERT INTO products (id, name, sku, sale_price, stock_quantity)
       VALUES ($1, $2, $3, $4, $5)`,
      ['product-1', 'Widget', 'WID-1', '10.000', 3],
    );

    await runMigrationVersion(adapter, 62);

    const columns = await adapter.select<ColumnInfo[]>('PRAGMA table_info(products)');
    const quantityDecimals = columns.find((column) => column.name === 'quantity_decimals');
    expect(quantityDecimals).toMatchObject({ type: 'INTEGER', notnull: 0 });

    await expect(
      adapter.select<Array<{ id: string; quantity_decimals: number | null }>>(
        'SELECT id, quantity_decimals FROM products',
      ),
    ).resolves.toEqual([{ id: 'product-1', quantity_decimals: null }]);

    // Re-running the migration is a no-op (duplicate-column swallowed).
    await expect(runMigrationVersion(adapter, 62)).resolves.toBeUndefined();
  });

  it('fresh migrate-to-62 yields the same products schema as upgrade-from-61', async () => {
    // Fresh DB: apply every migration up to and including 62.
    await runMigrationsUpTo(adapter, 62);
    const freshColumns = await adapter.select<ColumnInfo[]>('PRAGMA table_info(products)');

    // Upgrade path: stop at 61, then apply 62.
    const upgradeAdapter = new SqliteTestAdapter();
    try {
      await runMigrationsUpTo(upgradeAdapter, 61);
      await runMigrationVersion(upgradeAdapter, 62);
      const upgradeColumns = await upgradeAdapter.select<ColumnInfo[]>(
        'PRAGMA table_info(products)',
      );

      const names = (cols: ColumnInfo[]) => cols.map((c) => c.name).sort();
      expect(names(freshColumns)).toEqual(names(upgradeColumns));
      expect(names(freshColumns)).toContain('quantity_decimals');
    } finally {
      upgradeAdapter.close();
    }
  });

  it('applyAllMigrations leaves quantity_decimals present on the products table', async () => {
    await applyAllMigrations(adapter);
    const columns = await adapter.select<ColumnInfo[]>('PRAGMA table_info(products)');
    expect(columns.map((column) => column.name)).toContain('quantity_decimals');
  });
});
