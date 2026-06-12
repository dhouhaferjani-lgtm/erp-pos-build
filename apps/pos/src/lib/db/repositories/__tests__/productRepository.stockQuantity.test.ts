import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '../../__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import { upsertProducts, getAllProducts } from '../productRepository';
import type { POSProduct } from '@/types/product';

/**
 * Regression: location-aware-stock dropped `stock_quantity` from the `/products`
 * payload (real stock now lives in `location_stock`). `pullProductsCore` no longer
 * sets it, so the mapped POSProduct has `stock_quantity === undefined`. The upsert
 * bound it raw into the legacy `products.stock_quantity NOT NULL` column →
 *   "NOT NULL constraint failed: products.stock_quantity"
 * → every product insert failed → 0 products in the grid for non-menu tenants.
 */
async function runAllMigrations(db: { execute: (sql: string, params?: unknown[]) => Promise<unknown> }): Promise<void> {
  for (const m of migrations) {
    if (m.run) await m.run(db);
    else await db.execute(m.sql);
  }
}

describe('upsertProducts: stock_quantity absent (location-aware /products payload)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('persists products even when stock_quantity is absent (defaults the legacy column to 0)', async () => {
    // Mirrors the real runtime: the API row carries no stock_quantity.
    const product = {
      id: 'p1',
      name: 'Omega-3 Fish Oil',
      sku: 'PB-SUP-0001',
      sale_price: '10.000',
      tax_rate: '19.00',
    } as unknown as POSProduct;

    await upsertProducts(adapter.asDatabase(), [product]);

    const all = await getAllProducts(adapter.asDatabase());
    expect(all).toHaveLength(1);
    expect(all[0]!.id).toBe('p1');
    expect(all[0]!.stock_quantity).toBe(0);
  });
});
