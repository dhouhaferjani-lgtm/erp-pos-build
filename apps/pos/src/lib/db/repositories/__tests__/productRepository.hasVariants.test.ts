/**
 * M4 adversarial-review: has_variants roundtrip tests for productRepository.
 *
 * Verifies that:
 *   - has_variants: true is persisted as INTEGER 1 and read back as boolean true.
 *   - has_variants: false is persisted as INTEGER 0 and read back as undefined/falsy.
 *   - has_variants absent (undefined) round-trips as falsy (safe default).
 *   - A re-upsert with a changed value overwrites the stored value (ON CONFLICT path).
 *
 * Uses the real SQLite engine via SqliteTestAdapter + applyAllMigrations so
 * migration v54 (ALTER TABLE products ADD COLUMN has_variants) is exercised
 * against the actual schema with all prior migrations applied.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import type { POSProduct } from '@/types/product';
import {
  upsertProducts,
  getAllProducts,
  getProductByBarcode,
  getProductById,
} from '../productRepository';

/** Minimal valid POSProduct for upsert — mirrors the helper in isPhysical test. */
function makeProduct(overrides: Partial<POSProduct> & { id: string }): POSProduct {
  return {
    name: 'Test product',
    sku: `SKU-${overrides.id}`,
    sale_price: '10.000',
    stock_quantity: 0,
    sellableType: 'product',
    ...overrides,
  };
}

describe('productRepository — has_variants roundtrip (real SQLite)', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    db = adapter.asDatabase();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('persists has_variants: true and reads back true via getAllProducts', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-has-variants', has_variants: true })]);
    const products = await getAllProducts(db);
    const p = products.find((x) => x.id === 'p-has-variants');
    expect(p).toBeDefined();
    expect(p!.has_variants).toBe(true);
  });

  it('persists has_variants: false and reads back falsy via getAllProducts', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-no-variants', has_variants: false })]);
    const products = await getAllProducts(db);
    const p = products.find((x) => x.id === 'p-no-variants');
    expect(p).toBeDefined();
    // false or undefined — either is falsy; the caller checks `product.has_variants === true`
    expect(p!.has_variants).toBeFalsy();
  });

  it('treats absent has_variants (undefined) as falsy — safe default', async () => {
    // Any caller that does not set has_variants (e.g. Menu flatten path) must
    // produce a falsy value, never accidentally open the variant picker.
    await upsertProducts(db, [makeProduct({ id: 'p-absent-variants' })]);
    const products = await getAllProducts(db);
    const p = products.find((x) => x.id === 'p-absent-variants');
    expect(p).toBeDefined();
    expect(p!.has_variants).toBeFalsy();
  });

  it('reads back has_variants: true via getProductByBarcode', async () => {
    await upsertProducts(db, [
      makeProduct({ id: 'p-barcode-variant', has_variants: true, barcode: 'BAR-001' }),
    ]);
    const p = await getProductByBarcode(db, 'BAR-001');
    expect(p).not.toBeNull();
    expect(p!.has_variants).toBe(true);
  });

  it('reads back has_variants: true via getProductById', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-id-variant', has_variants: true })]);
    const p = await getProductById(db, 'p-id-variant');
    expect(p).not.toBeNull();
    expect(p!.has_variants).toBe(true);
  });

  it('overwrites has_variants on re-upsert (false → true)', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-flip', has_variants: false })]);
    await upsertProducts(db, [makeProduct({ id: 'p-flip', has_variants: true })]);
    const p = await getProductById(db, 'p-flip');
    expect(p!.has_variants).toBe(true);
  });

  it('overwrites has_variants on re-upsert (true → false)', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-reflip', has_variants: true })]);
    await upsertProducts(db, [makeProduct({ id: 'p-reflip', has_variants: false })]);
    const p = await getProductById(db, 'p-reflip');
    expect(p!.has_variants).toBeFalsy();
  });
});
