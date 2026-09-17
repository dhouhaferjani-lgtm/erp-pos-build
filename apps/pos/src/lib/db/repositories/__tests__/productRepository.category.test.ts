import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { productCategory } from '@/test/fixtures/productCategory';
import { getAllProducts, getProductById, getProductsByBarcode, upsertProducts, type ProductPayload } from '../productRepository';

// Stand-in for the PRE-FIX write path only: the old app passed the CategoryData
// object and the Rust writer stored it as JSON text. Not a fidelity model of
// db_writer.rs (numbers/booleans differ).
class NativeBindingAdapter extends SqliteTestAdapter {
  override execute(sql: string, params?: unknown[]) {
    return super.execute(sql, params?.map((value) =>
      value !== null && typeof value === 'object' ? JSON.stringify(value) : value,
    ));
  }
}

function product(category: ProductPayload['category'], id = '22222222-2222-4222-8222-222222222222'): ProductPayload {
  return {
    id,
    name: 'Crème de jour',
    sku: 'CREME-001',
    barcode: '6190000000012',
    sale_price: '12.000',
    stock_quantity: 5,
    category,
  };
}

describe('POS product category persistence (real SQLite)', () => {
  let adapter: NativeBindingAdapter;
  let db: ReturnType<NativeBindingAdapter['asDatabase']>;

  beforeEach(async () => {
    adapter = new NativeBindingAdapter();
    db = adapter.asDatabase();
    await applyAllMigrations(adapter);
  });

  afterEach(() => adapter.close());

  it('stores the readable category label from the server DTO on repeated sync', async () => {
    const payload = product(productCategory());
    await upsertProducts(db, [payload]);
    await upsertProducts(db, [payload]);

    expect(await adapter.select('SELECT category FROM products')).toEqual([{ category: 'Soins visage' }]);
    expect((await getAllProducts(db)).map((row) => row.category)).toEqual(['Soins visage']);
  });

  it('recovers the exact cached CategoryData shape before a sync can refresh it', async () => {
    const payload = product(JSON.stringify(productCategory()));
    await upsertProducts(db, [product('Soins visage')]);
    // Represent the database written by the old app without using the new write path.
    await adapter.execute('UPDATE products SET category = $1 WHERE id = $2', [payload.category, payload.id]);

    expect((await getAllProducts(db))[0]?.category).toBe('Soins visage');
    expect((await getProductById(db, payload.id))?.category).toBe('Soins visage');
    expect((await getProductsByBarcode(db, '6190000000012'))[0]?.category).toBe('Soins visage');
  });

  it('updates synchronized category names while unchanged cached products remain readable', async () => {
    await upsertProducts(db, [product('Soins visage', 'updated'), product('Boissons', 'unchanged')]);
    await adapter.execute('UPDATE products SET category = $1 WHERE id = $2', [
      JSON.stringify(productCategory({ id: 13, name: 'Boissons' })), 'unchanged',
    ]);

    const updated = product(productCategory({ name: 'Soins du visage' }), 'updated');
    await upsertProducts(db, [updated]);
    await upsertProducts(db, [updated]);

    expect((await getProductById(db, 'updated'))?.category).toBe('Soins du visage');
    expect((await getProductById(db, 'unchanged'))?.category).toBe('Boissons');
    expect(await getAllProducts(db)).toHaveLength(2);
  });

  it('keeps both products when distinct categories have the same display label', async () => {
    await upsertProducts(db, [
      product(productCategory({ id: 12 }), 'first'),
      product(productCategory({ id: 13 }), 'second'),
    ]);

    const products = await getAllProducts(db);
    expect(products.map((row) => row.id)).toEqual(['first', 'second']);
    expect(products.map((row) => row.category)).toEqual(['Soins visage', 'Soins visage']);
  });

  it('keeps plain Menu labels and arbitrary JSON-looking operator labels intact', async () => {
    const labels = ['Boissons', '{Soin}', '{"name":"operator label"}', '["Soins"]'];
    await upsertProducts(db, labels.map((label, index) => product(label, `product-${index}`)));

    expect((await getAllProducts(db)).map((row) => row.category)).toEqual(labels);
  });

  it('leaves products without a category available without manufacturing a label', async () => {
    await upsertProducts(db, [product(null)]);

    expect((await getAllProducts(db))[0]?.category).toBeUndefined();
  });

  it('normalizes each company cache independently even when SKU and category IDs match', async () => {
    const second = new NativeBindingAdapter();
    try {
      await applyAllMigrations(second);
      await upsertProducts(db, [product(productCategory())]);
      await upsertProducts(second.asDatabase(), [product(productCategory({
        company_id: '33333333-3333-4333-8333-333333333333',
        name: 'Entretien auto',
      }))]);

      expect((await getAllProducts(db))[0]?.category).toBe('Soins visage');
      expect((await getAllProducts(second.asDatabase()))[0]?.category).toBe('Entretien auto');
    } finally {
      second.close();
    }
  });
});
