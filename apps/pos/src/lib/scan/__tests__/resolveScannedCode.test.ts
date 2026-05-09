/**
 * T2.1 Step B — three-tier scan resolution helper.
 * Tests B.1–B.9 of the kickoff
 * (`docs/superpowers/plans/2026-05-09-pos-t2.1-catalog-truthfulness-kickoff-prompt.md`).
 *
 * The helper resolves a scanned code through three tiers:
 *   1. In-memory `productStore.products` (preserved barcode OR sku match)
 *   2. SQLite `getProductByBarcode(db, code)` (also barcode OR sku)
 *   3. API `fetchProductByBarcode(code, opts)` (server-side barcode OR sku)
 *
 * Tier 3 result classification:
 *   - 0 results → `{ kind: 'miss' }`
 *   - exactly 1 result → `{ kind: 'hit', product }` + SQLite write-through
 *   - >1 results → `{ kind: 'choose', candidates }` — chooser modal UX
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { POSProduct } from '@/types/product';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';

const getProductByBarcodeSpy = vi.fn();
const upsertProductsSpy = vi.fn();
const fetchProductByBarcodeSpy = vi.fn();

vi.mock('@/lib/db/repositories/productRepository', () => ({
  getProductByBarcode: (...args: unknown[]) => getProductByBarcodeSpy(...args),
  upsertProducts: (...args: unknown[]) => upsertProductsSpy(...args),
}));

vi.mock('@/api/productApi', () => ({
  fetchProductByBarcode: (...args: unknown[]) => fetchProductByBarcodeSpy(...args),
}));

import { resolveScannedCode } from '../resolveScannedCode';

function makeProduct(over: Partial<POSProduct>): POSProduct {
  return {
    id: 'p-default',
    name: 'Default product',
    sku: 'DEFAULT-SKU',
    barcode: '0000000000',
    sale_price: '10.00',
    stock_quantity: 100,
    category: 'Test',
    ...over,
  } as POSProduct;
}

const db = {} as import('@tauri-apps/plugin-sql').default;

describe('resolveScannedCode — T2.1 Step B', () => {
  beforeEach(() => {
    getProductByBarcodeSpy.mockReset();
    upsertProductsSpy.mockReset();
    fetchProductByBarcodeSpy.mockReset();
  });

  it('B.1: in-memory hit by barcode short-circuits SQLite + API', async () => {
    const product = makeProduct({ id: 'p1', barcode: '123', sku: 'X-001' });

    const result = await resolveScannedCode('123', { db, products: [product] });

    expect(result).toEqual({ kind: 'hit', product });
    expect(getProductByBarcodeSpy).not.toHaveBeenCalled();
    expect(fetchProductByBarcodeSpy).not.toHaveBeenCalled();
  });

  it('B.2: in-memory hit by SKU short-circuits SQLite + API (barcode/SKU disjunction preserved)', async () => {
    const product = makeProduct({ id: 'p1', barcode: '123', sku: 'X-001' });

    const result = await resolveScannedCode('X-001', { db, products: [product] });

    expect(result).toEqual({ kind: 'hit', product });
    expect(getProductByBarcodeSpy).not.toHaveBeenCalled();
    expect(fetchProductByBarcodeSpy).not.toHaveBeenCalled();
  });

  it('B.3: in-memory miss + SQLite hit returns hit (API not called)', async () => {
    const sqliteProduct = makeProduct({ id: 'p2', barcode: '999', sku: 'Y-002' });
    getProductByBarcodeSpy.mockResolvedValue(sqliteProduct);

    const result = await resolveScannedCode('999', { db, products: [] });

    expect(result).toEqual({ kind: 'hit', product: sqliteProduct });
    expect(getProductByBarcodeSpy).toHaveBeenCalledWith(db, '999');
    expect(fetchProductByBarcodeSpy).not.toHaveBeenCalled();
  });

  it('B.4: in-memory + SQLite miss + API single-result returns hit AND upserts SQLite', async () => {
    const apiProduct = makeProduct({ id: 'p3', barcode: '777', sku: 'Z-003' });
    getProductByBarcodeSpy.mockResolvedValue(null);
    fetchProductByBarcodeSpy.mockResolvedValue([apiProduct]);

    const result = await resolveScannedCode('777', { db, products: [] });

    expect(result).toEqual({ kind: 'hit', product: apiProduct });
    expect(fetchProductByBarcodeSpy).toHaveBeenCalledTimes(1);
    expect(upsertProductsSpy).toHaveBeenCalledWith(db, [apiProduct]);
  });

  it('B.5: in-memory + SQLite miss + API multi-result returns choose with all candidates (NO auto-pick)', async () => {
    const productA = makeProduct({ id: 'pa', barcode: '555', sku: 'A-1' });
    const productB = makeProduct({ id: 'pb', barcode: '555', sku: 'B-1' });
    getProductByBarcodeSpy.mockResolvedValue(null);
    fetchProductByBarcodeSpy.mockResolvedValue([productA, productB]);

    const result = await resolveScannedCode('555', { db, products: [] });

    expect(result).toEqual({ kind: 'choose', candidates: [productA, productB] });
    // No upsert on collision — caller resolves with the chooser, then
    // adds the picked product to cart (which separately upserts).
    expect(upsertProductsSpy).not.toHaveBeenCalled();
  });

  it('B.6: full miss across all three tiers returns miss', async () => {
    getProductByBarcodeSpy.mockResolvedValue(null);
    fetchProductByBarcodeSpy.mockResolvedValue([]);

    const result = await resolveScannedCode('404', { db, products: [] });

    expect(result).toEqual({ kind: 'miss' });
    expect(upsertProductsSpy).not.toHaveBeenCalled();
  });

  it('B.7: API timeout returns miss with logged error (no rejection bubbles up)', async () => {
    getProductByBarcodeSpy.mockResolvedValue(null);
    fetchProductByBarcodeSpy.mockRejectedValue(
      new FetchTimeoutError('https://example.test/api/v1/products', 5000, 'GET'),
    );
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    const result = await resolveScannedCode('123', { db, products: [] });

    expect(result).toEqual({ kind: 'miss' });
    // Logged via serializeErrorForLog payload shape.
    expect(consoleError).toHaveBeenCalled();
    const logged = consoleError.mock.calls.find(
      (c) =>
        typeof c[0] === 'string' &&
        c[0].includes('resolveScannedCode'),
    );
    expect(logged).toBeDefined();
    if (logged) {
      const payload = logged[1] as Record<string, unknown>;
      expect(payload).toHaveProperty('errorName');
      expect(payload).toHaveProperty('message');
    }
    consoleError.mockRestore();
  });

  it('B.8: empty / whitespace / single-char codes return miss WITHOUT any tier work', async () => {
    for (const code of ['', '  ', '\n', '\t', '1']) {
      getProductByBarcodeSpy.mockReset();
      fetchProductByBarcodeSpy.mockReset();

      const result = await resolveScannedCode(code, { db, products: [] });

      expect(result).toEqual({ kind: 'miss' });
      expect(getProductByBarcodeSpy).not.toHaveBeenCalled();
      expect(fetchProductByBarcodeSpy).not.toHaveBeenCalled();
    }
  });

  it('B.9: concurrent scans resolve independently (an aborted scan does not block the next)', async () => {
    // Scan 1: API rejects immediately with AbortError (simulates a
    // scan-handler that aborted the controller before the request
    // resolved — common UX pattern when the cashier scans a second
    // code while the first is in Tier 3). The helper catches the
    // error and downgrades to `kind: 'miss'`.
    fetchProductByBarcodeSpy.mockImplementationOnce(() =>
      Promise.reject(new DOMException('aborted', 'AbortError')),
    );
    // Scan 2: API resolves quickly with one product.
    const scan2Product = makeProduct({ id: 'p-scan2', barcode: '999' });
    fetchProductByBarcodeSpy.mockResolvedValueOnce([scan2Product]);
    getProductByBarcodeSpy.mockResolvedValue(null);

    // Suppress the expected AbortError log from scan1.
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    const [scan1Result, scan2Result] = await Promise.all([
      resolveScannedCode('123', { db, products: [] }),
      resolveScannedCode('999', { db, products: [] }),
    ]);

    expect(scan1Result).toEqual({ kind: 'miss' });
    expect(scan2Result).toEqual({ kind: 'hit', product: scan2Product });

    consoleError.mockRestore();
  });
});
