/**
 * Task 10 — `is_physical` roundtrip tests for productRepository.
 *
 * Uses the real SQLite engine via SqliteTestAdapter so the column addition
 * (migration v51), the boolean→integer write path, and the integer→boolean
 * read path are all exercised against the actual schema.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import type { POSProduct } from '@/types/product';
import { upsertProducts, getAllProducts } from '../productRepository';

/** Minimal valid POSProduct for upsert. */
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

describe('productRepository — is_physical roundtrip (real SQLite)', () => {
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

  it('persists is_physical: false and reads back false', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-service', is_physical: false })]);
    const products = await getAllProducts(db);
    const p = products.find((x) => x.id === 'p-service');
    expect(p).toBeDefined();
    expect(p!.is_physical).toBe(false);
  });

  it('persists is_physical: true and reads back true', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-physical', is_physical: true })]);
    const products = await getAllProducts(db);
    const p = products.find((x) => x.id === 'p-physical');
    expect(p).toBeDefined();
    expect(p!.is_physical).toBe(true);
  });

  it('treats absent is_physical (undefined) as physical — reads back true', async () => {
    // Menu flatten path and any other caller that doesn't set the field
    // must produce is_physical: true (fail toward enforcement).
    await upsertProducts(db, [makeProduct({ id: 'p-absent' })]);
    const products = await getAllProducts(db);
    const p = products.find((x) => x.id === 'p-absent');
    expect(p).toBeDefined();
    expect(p!.is_physical).toBe(true);
  });

  it('overwrites is_physical on re-upsert (physical → non-physical)', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-flip', is_physical: true })]);
    await upsertProducts(db, [makeProduct({ id: 'p-flip', is_physical: false })]);
    const products = await getAllProducts(db);
    const p = products.find((x) => x.id === 'p-flip');
    expect(p!.is_physical).toBe(false);
  });

  it('overwrites is_physical on re-upsert (non-physical → physical)', async () => {
    await upsertProducts(db, [makeProduct({ id: 'p-reflip', is_physical: false })]);
    await upsertProducts(db, [makeProduct({ id: 'p-reflip', is_physical: true })]);
    const products = await getAllProducts(db);
    const p = products.find((x) => x.id === 'p-reflip');
    expect(p!.is_physical).toBe(true);
  });
});
