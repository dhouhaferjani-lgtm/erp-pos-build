import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fetchProductVariants } from '../variantApi';

vi.mock('@/lib/api', () => ({ apiGet: vi.fn() }));

import { apiGet } from '@/lib/api';

describe('variantApi.fetchProductVariants', () => {
  beforeEach(() => {
    vi.mocked(apiGet).mockReset();
  });

  it('GETs the per-product variants endpoint', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce([]);
    await fetchProductVariants('prod-1');
    expect(apiGet).toHaveBeenCalledWith('/products/prod-1/variants');
  });

  it('maps the wire shape and coerces stock_quantity, dropping cost_override', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce([
      {
        id: 'var-1',
        product_id: 'prod-1',
        variant_code: 'SHOE-39-BLK',
        sku: 'SHOE-39-BLK',
        barcode: '111',
        name_suffix: ' — 39 / Black',
        is_default: true,
        is_active: true,
        display_order: 0,
        price_override: '14.50',
        cost_override: '9.00',
        image_url: null,
        stock_quantity: '7',
      },
    ]);

    const variants = await fetchProductVariants('prod-1');

    expect(variants).toHaveLength(1);
    const variant = variants[0]!;
    expect(variant.id).toBe('var-1');
    expect(variant.price_override).toBe('14.50');
    expect(variant.stock_quantity).toBe(7);
    // cost_override is advisory (spec §6.7) and must never reach the POS shape.
    expect('cost_override' in variant).toBe(false);
  });

  it('defaults stock_quantity to 0 when the backend omits it', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce([
      {
        id: 'var-1',
        product_id: 'prod-1',
        variant_code: 'V1',
        sku: 'V1',
        name_suffix: '',
        is_default: false,
        is_active: true,
        display_order: 0,
      },
    ]);

    const variants = await fetchProductVariants('prod-1');
    expect(variants[0]!.stock_quantity).toBe(0);
  });

  it('filters out inactive variants and sorts by display order', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce([
      { id: 'b', product_id: 'p', variant_code: 'B', sku: 'B', name_suffix: '', is_default: false, is_active: true, display_order: 2 },
      { id: 'gone', product_id: 'p', variant_code: 'G', sku: 'G', name_suffix: '', is_default: false, is_active: false, display_order: 1 },
      { id: 'a', product_id: 'p', variant_code: 'A', sku: 'A', name_suffix: '', is_default: true, is_active: true, display_order: 0 },
    ]);

    const variants = await fetchProductVariants('p');
    expect(variants.map((v) => v.id)).toEqual(['a', 'b']);
  });

  it('tolerates a double-wrapped { data } envelope', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      data: [
        { id: 'a', product_id: 'p', variant_code: 'A', sku: 'A', name_suffix: '', is_default: true, is_active: true, display_order: 0 },
      ],
    });

    const variants = await fetchProductVariants('p');
    expect(variants).toHaveLength(1);
  });
});
