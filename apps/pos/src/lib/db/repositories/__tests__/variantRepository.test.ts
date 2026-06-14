import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { upsertVariants, getVariantsForProduct, getVariantByBarcode, deleteVariantsById, deleteVariantsForProducts } from '@/lib/db/repositories/variantRepository';
import { upsertStockRows } from '@/lib/db/repositories/locationStockRepository';

const row = (over: Partial<Parameters<typeof upsertVariants>[1][number]> = {}) => ({
  id: 'v1', product_id: 'p1', sku: 'SKU1', barcode: 'BC1', name_suffix: ' — M',
  price_override: null, image_url: null, is_default: false, display_order: 0, updated_at: '2026-06-14T10:00:00Z', ...over,
});

describe('variantRepository', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;
  beforeEach(async () => { adapter = new SqliteTestAdapter(); db = adapter.asDatabase(); await applyAllMigrations(adapter); });
  afterEach(() => { adapter.close(); });

  it('upserts and lists variants for a product (active, ordered)', async () => {
    await upsertVariants(db, [row({ id: 'v2', display_order: 1, barcode: 'BC2' }), row({ id: 'v1', display_order: 0 })]);
    const list = await getVariantsForProduct(db, 'p1');
    expect(list.map((v) => v.id)).toEqual(['v1', 'v2']); // display_order asc
  });

  it('getVariantByBarcode returns the active variant; ignores empty/null', async () => {
    await upsertVariants(db, [row({ barcode: 'BC1' })]);
    expect((await getVariantByBarcode(db, 'BC1'))?.id).toBe('v1');
    expect(await getVariantByBarcode(db, '')).toBeNull();
    expect(await getVariantByBarcode(db, 'NOPE')).toBeNull();
  });

  it('joins location_stock available into stock_quantity', async () => {
    await upsertVariants(db, [row()]);
    await upsertStockRows(db, [{ product_id: 'p1', variant_id: 'v1', quantity: '5.0000', reserved: '0.0000', available: '5.0000', updated_at: null }]);
    expect((await getVariantsForProduct(db, 'p1'))[0]!.stock_quantity).toBe(5);
  });

  it('deletes by id and by product', async () => {
    await upsertVariants(db, [row({ id: 'v1' }), row({ id: 'v2', barcode: 'BC2' })]);
    await deleteVariantsById(db, ['v1']);
    expect((await getVariantsForProduct(db, 'p1')).map((v) => v.id)).toEqual(['v2']);
    await deleteVariantsForProducts(db, ['p1']);
    expect(await getVariantsForProduct(db, 'p1')).toEqual([]);
  });
});
