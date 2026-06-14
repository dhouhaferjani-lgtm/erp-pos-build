/**
 * FV4 — variant-barcode tier + `variant-hit` in the scan resolver.
 *
 * Verifies:
 *   FV4.1  scanning a variant barcode → `{ kind: 'variant-hit', product, variant }`
 *   FV4.2  deactivated variant barcode (getVariantByBarcode → null) → fall-through (miss)
 *   FV4.3  variant-hit is NOT written to the recent-scan LRU
 *   FV4.4  product barcode still returns `{ kind: 'hit', product }` (unchanged)
 *   FV4.5  variant product_id resolved from in-memory snapshot (no SQLite getProductById)
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { POSProduct, POSProductVariant } from '@/types/product';

// ── mocks (declare before the import of the SUT) ──────────────────────────────

const getVariantByBarcodeSpy = vi.fn();
vi.mock('@/lib/db/repositories/variantRepository', () => ({
  getVariantByBarcode: (...args: unknown[]) => getVariantByBarcodeSpy(...args),
}));

const getProductsByBarcodeSpy = vi.fn();
const getProductByIdSpy = vi.fn();
const upsertProductsSpy = vi.fn();
vi.mock('@/lib/db/repositories/productRepository', () => ({
  getProductsByBarcode: (...args: unknown[]) => getProductsByBarcodeSpy(...args),
  getProductById: (...args: unknown[]) => getProductByIdSpy(...args),
  upsertProducts: (...args: unknown[]) => upsertProductsSpy(...args),
}));

const fetchProductByBarcodeSpy = vi.fn();
vi.mock('@/api/productApi', () => ({
  fetchProductByBarcode: (...args: unknown[]) => fetchProductByBarcodeSpy(...args),
}));

const getCachedScanSpy = vi.fn();
const setCachedScanSpy = vi.fn();
vi.mock('@/lib/scan/scanResolutionCache', () => ({
  getCachedScan: (...args: unknown[]) => getCachedScanSpy(...args),
  setCachedScan: (...args: unknown[]) => setCachedScanSpy(...args),
  clearScanCache: vi.fn(),
}));

import { resolveScannedCode } from '../resolveScannedCode';

// ── helpers ───────────────────────────────────────────────────────────────────

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

function makeVariant(over: Partial<POSProductVariant>): POSProductVariant {
  return {
    id: 'v-default',
    product_id: 'p-default',
    variant_code: 'V-DEFAULT',
    sku: 'V-DEFAULT-SKU',
    barcode: '1111111111',
    name_suffix: '— Red / M',
    is_default: false,
    is_active: true,
    display_order: 1,
    price_override: null,
    image_url: null,
    stock_quantity: 5,
    ...over,
  };
}

const db = {} as import('@tauri-apps/plugin-sql').default;
const COMPANY_ID = 'company-1';

// ── tests ─────────────────────────────────────────────────────────────────────

describe('resolveScannedCode — FV4 variant-barcode tier', () => {
  beforeEach(() => {
    getVariantByBarcodeSpy.mockReset();
    getProductsByBarcodeSpy.mockReset();
    getProductByIdSpy.mockReset();
    upsertProductsSpy.mockReset();
    fetchProductByBarcodeSpy.mockReset();
    getCachedScanSpy.mockReset();
    setCachedScanSpy.mockReset();

    // Default: Tier 0 LRU miss so we reach SQLite tier
    getCachedScanSpy.mockReturnValue(null);
  });

  // FV4.1 — scanning a variant barcode returns variant-hit with parent product
  it('FV4.1: variant barcode → variant-hit with product (parent resolved from in-memory snapshot)', async () => {
    const parent = makeProduct({ id: 'p-shoe', name: 'Nike Air' });
    const variant = makeVariant({
      id: 'v-shoe-39-red',
      product_id: 'p-shoe',
      barcode: 'VAR-BARCODE-001',
      name_suffix: '— 39 / Red',
    });

    getVariantByBarcodeSpy.mockResolvedValue(variant);
    // No product barcode match in SQLite
    getProductsByBarcodeSpy.mockResolvedValue([]);

    const result = await resolveScannedCode('VAR-BARCODE-001', {
      db,
      companyId: COMPANY_ID,
      products: [parent],
    });

    expect(result).toEqual({ kind: 'variant-hit', product: parent, variant });
    // API should NOT be called — variant-hit short-circuits
    expect(fetchProductByBarcodeSpy).not.toHaveBeenCalled();
  });

  // FV4.1b — parent product resolved from SQLite when not in in-memory snapshot
  it('FV4.1b: variant barcode → variant-hit with parent resolved from SQLite (not in memory)', async () => {
    const parent = makeProduct({ id: 'p-shoe', name: 'Nike Air' });
    const variant = makeVariant({
      id: 'v-shoe-39-red',
      product_id: 'p-shoe',
      barcode: 'VAR-BARCODE-002',
    });

    getVariantByBarcodeSpy.mockResolvedValue(variant);
    getProductByIdSpy.mockResolvedValue(parent);
    getProductsByBarcodeSpy.mockResolvedValue([]);

    const result = await resolveScannedCode('VAR-BARCODE-002', {
      db,
      companyId: COMPANY_ID,
      products: [], // empty in-memory snapshot → must use getProductById
    });

    expect(result).toEqual({ kind: 'variant-hit', product: parent, variant });
    expect(getProductByIdSpy).toHaveBeenCalledWith(db, 'p-shoe');
    expect(fetchProductByBarcodeSpy).not.toHaveBeenCalled();
  });

  // FV4.2 — deactivated variant (getVariantByBarcode returns null) → falls through → miss
  it('FV4.2: deactivated variant barcode (getVariantByBarcode → null) falls through to miss', async () => {
    getVariantByBarcodeSpy.mockResolvedValue(null);
    // No product match either
    getProductsByBarcodeSpy.mockResolvedValue([]);
    fetchProductByBarcodeSpy.mockResolvedValue([]);

    const result = await resolveScannedCode('INACTIVE-VAR-BC', {
      db,
      companyId: COMPANY_ID,
      products: [],
    });

    expect(result).toEqual({ kind: 'miss' });
  });

  // FV4.3 — variant-hit must NOT be written to the recent-scan LRU
  it('FV4.3: variant-hit is NOT written to the Tier 0 LRU (setCachedScan not called)', async () => {
    const parent = makeProduct({ id: 'p-shirt' });
    const variant = makeVariant({ product_id: 'p-shirt', barcode: 'VAR-LRU-TEST' });

    getVariantByBarcodeSpy.mockResolvedValue(variant);
    getProductsByBarcodeSpy.mockResolvedValue([]);

    const result = await resolveScannedCode('VAR-LRU-TEST', {
      db,
      companyId: COMPANY_ID,
      products: [parent],
    });

    expect(result.kind).toBe('variant-hit');
    expect(setCachedScanSpy).not.toHaveBeenCalled();
  });

  // FV4.4 — product barcode still returns hit (existing behaviour unchanged)
  it('FV4.4: product barcode still returns { kind: hit, product } (existing tiers intact)', async () => {
    const product = makeProduct({ id: 'p-product', barcode: 'PRODUCT-BC-001' });

    // No variant for this barcode
    getVariantByBarcodeSpy.mockResolvedValue(null);
    getProductsByBarcodeSpy.mockResolvedValue([product]);

    const result = await resolveScannedCode('PRODUCT-BC-001', {
      db,
      companyId: COMPANY_ID,
      products: [],
    });

    expect(result).toEqual({ kind: 'hit', product });
    // A product hit DOES write to the LRU
    expect(setCachedScanSpy).toHaveBeenCalledWith('PRODUCT-BC-001', product, COMPANY_ID);
  });

  // FV4.5 — parent product not synced → fall through to existing product tiers (no crash)
  it('FV4.5: variant found but parent product not synced → falls through (no crash)', async () => {
    const variant = makeVariant({
      product_id: 'p-orphan',
      barcode: 'ORPHAN-VAR-BC',
    });

    getVariantByBarcodeSpy.mockResolvedValue(variant);
    // Parent not in memory and not in SQLite
    getProductByIdSpy.mockResolvedValue(null);
    // No product barcode match either
    getProductsByBarcodeSpy.mockResolvedValue([]);
    fetchProductByBarcodeSpy.mockResolvedValue([]);

    const result = await resolveScannedCode('ORPHAN-VAR-BC', {
      db,
      companyId: COMPANY_ID,
      products: [],
    });

    // Falls through to miss — no crash
    expect(result).toEqual({ kind: 'miss' });
    expect(fetchProductByBarcodeSpy).toHaveBeenCalledTimes(1);
  });
});
