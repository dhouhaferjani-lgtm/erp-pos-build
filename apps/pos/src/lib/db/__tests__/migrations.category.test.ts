import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { runMigrationsUpTo, runMigrationVersion } from './helpers/migrationTestHelpers';
import { productCategory } from '@/test/fixtures/productCategory';

describe('migration 68: normalize legacy JSON product categories', () => {
  let adapter: SqliteTestAdapter;
  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 67);
  });
  afterEach(() => adapter.close());

  it('rewrites CategoryData JSON to its name, leaves labels and unparseable text alone', async () => {
    const rows: Array<[string, string | null]> = [
      ['json', JSON.stringify(productCategory({ name: 'Soins visage' }))],
      ['label', 'Boissons'],
      ['brace', '{Soin}'],
      ['notdto', '{"name":"operator label"}'],
      ['nul', null],
    ];
    for (const [id, category] of rows) {
      await adapter.execute(
        "INSERT INTO products (id, name, sku, sale_price, stock_quantity, category) VALUES ($1, $2, $3, '1.000', 0, $4)",
        [id, id, id, category],
      );
    }
    await runMigrationVersion(adapter, 68);
    const after = Object.fromEntries(
      (
        await adapter.select<Array<{ id: string; category: string | null }>>(
          'SELECT id, category FROM products',
        )
      ).map((r) => [r.id, r.category]),
    );
    expect(after).toEqual({
      json: 'Soins visage',
      label: 'Boissons',
      brace: '{Soin}',
      notdto: '{"name":"operator label"}',
      nul: null,
    });
  });

  it('is idempotent', async () => {
    await adapter.execute(
      "INSERT INTO products (id, name, sku, sale_price, stock_quantity, category) VALUES ('a','a','a','1.000',0,$1)",
      [JSON.stringify(productCategory())],
    );
    await runMigrationVersion(adapter, 68);
    await runMigrationVersion(adapter, 68);
    expect(await adapter.select('SELECT category FROM products')).toEqual([
      { category: 'Soins visage' },
    ]);
  });
});
