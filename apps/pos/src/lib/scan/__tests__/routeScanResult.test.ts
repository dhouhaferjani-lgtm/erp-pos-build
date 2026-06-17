/**
 * FV5 — routeScanResult: pure helper that routes a resolved scan result to the
 * correct cart/UI action.
 *
 * Tests:
 *  1. variant-hit  → addVariantToCart(product, variant), nothing else called
 *  2. hit + has_variants=true → openVariantPicker(product), NOT addProductToCartWithToast
 *  3. hit + has_variants falsy → addProductToCartWithToast(product)
 *  4. choose → showChooser(code, candidates)
 *  5. miss → showNotFound(code)
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ScanResultHandlers } from '../routeScanResult';
import { routeScanResult } from '../routeScanResult';
import type { POSProduct, POSProductVariant } from '@/types/product';
import type { ResolveScannedCodeResult } from '../resolveScannedCode';

function makeHandlers(): ScanResultHandlers {
  return {
    addProductToCartWithToast: vi.fn(),
    addVariantToCart: vi.fn(),
    openVariantPicker: vi.fn(),
    showChooser: vi.fn(),
    showNotFound: vi.fn(),
  };
}

const baseProduct: POSProduct = {
  id: 'prod-1',
  name: 'Sneaker',
  sku: 'SKU-001',
  barcode: '1234567890',
  sale_price: '49.000',
  stock_quantity: 10,
  has_variants: false,
} as POSProduct;

const variantProduct: POSProduct = {
  ...baseProduct,
  id: 'prod-2',
  has_variants: true,
};

const variant: POSProductVariant = {
  id: 'var-1',
  product_id: 'prod-2',
  variant_code: 'M-BLK',
  sku: 'SKU-002-M-BLK',
  barcode: '9876543210',
  name_suffix: ' — M / Noir',
  is_default: false,
  is_active: true,
  display_order: 1,
  stock_quantity: 5,
};

describe('routeScanResult', () => {
  let h: ScanResultHandlers;

  beforeEach(() => {
    h = makeHandlers();
  });

  it('variant-hit → addVariantToCart(product, variant) only', () => {
    const result: ResolveScannedCodeResult = { kind: 'variant-hit', product: variantProduct, variant };
    routeScanResult(result, '9876543210', h);

    expect(h.addVariantToCart).toHaveBeenCalledOnce();
    expect(h.addVariantToCart).toHaveBeenCalledWith(variantProduct, variant);
    expect(h.addProductToCartWithToast).not.toHaveBeenCalled();
    expect(h.openVariantPicker).not.toHaveBeenCalled();
    expect(h.showChooser).not.toHaveBeenCalled();
    expect(h.showNotFound).not.toHaveBeenCalled();
  });

  it('hit + has_variants=true → openVariantPicker(product), NOT addProductToCartWithToast', () => {
    const result: ResolveScannedCodeResult = { kind: 'hit', product: variantProduct };
    routeScanResult(result, '1111111111', h);

    expect(h.openVariantPicker).toHaveBeenCalledOnce();
    expect(h.openVariantPicker).toHaveBeenCalledWith(variantProduct);
    expect(h.addProductToCartWithToast).not.toHaveBeenCalled();
    expect(h.addVariantToCart).not.toHaveBeenCalled();
  });

  it('hit + has_variants falsy → addProductToCartWithToast(product)', () => {
    const result: ResolveScannedCodeResult = { kind: 'hit', product: baseProduct };
    routeScanResult(result, '1234567890', h);

    expect(h.addProductToCartWithToast).toHaveBeenCalledOnce();
    expect(h.addProductToCartWithToast).toHaveBeenCalledWith(baseProduct);
    expect(h.openVariantPicker).not.toHaveBeenCalled();
    expect(h.addVariantToCart).not.toHaveBeenCalled();
  });

  it('choose → showChooser(code, candidates)', () => {
    const candidates = [baseProduct, variantProduct];
    const result: ResolveScannedCodeResult = { kind: 'choose', candidates };
    const code = 'AMBIGUOUS';
    routeScanResult(result, code, h);

    expect(h.showChooser).toHaveBeenCalledOnce();
    expect(h.showChooser).toHaveBeenCalledWith(code, candidates);
    expect(h.addProductToCartWithToast).not.toHaveBeenCalled();
    expect(h.addVariantToCart).not.toHaveBeenCalled();
    expect(h.openVariantPicker).not.toHaveBeenCalled();
  });

  it('miss → showNotFound(code)', () => {
    const result: ResolveScannedCodeResult = { kind: 'miss' };
    const code = 'UNKNOWN-CODE';
    routeScanResult(result, code, h);

    expect(h.showNotFound).toHaveBeenCalledOnce();
    expect(h.showNotFound).toHaveBeenCalledWith(code);
    expect(h.addProductToCartWithToast).not.toHaveBeenCalled();
    expect(h.addVariantToCart).not.toHaveBeenCalled();
    expect(h.openVariantPicker).not.toHaveBeenCalled();
  });
});
