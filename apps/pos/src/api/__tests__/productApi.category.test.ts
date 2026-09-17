import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiGet } from '@/lib/api';
import { productCategory } from '@/test/fixtures/productCategory';
import { fetchPOSProducts, fetchProductByBarcode } from '../productApi';

vi.mock('@/lib/api', () => ({ apiGet: vi.fn() }));

function product(category: unknown) {
  return {
    id: '22222222-2222-4222-8222-222222222222',
    name: 'Crème de jour',
    sku: 'CREME-001',
    barcode: '6190000000012',
    sale_price: '12.000',
    stock_quantity: 5,
    category,
  };
}

beforeEach(() => vi.clearAllMocks());

describe('POS API category labels', () => {
  it.each([false, true])('projects the category DTO name from a product list (envelope: %s)', async (wrapped) => {
    const payload = product(productCategory());
    vi.mocked(apiGet).mockResolvedValueOnce(wrapped ? { data: [payload] } : [payload]);

    const products = await fetchPOSProducts({ limit: 500 });

    expect(products[0]?.category).toBe('Soins visage');
    expect(payload.category).toEqual(productCategory());
  });

  it('projects the category for barcode/SKU results that bypass the cache', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce([product(productCategory())]);

    const products = await fetchProductByBarcode('CREME-001');

    expect(products[0]?.category).toBe('Soins visage');
  });

  it.each([
    ['Boissons', 'Boissons'],
    [null, undefined],
    [undefined, undefined],
  ])('preserves legacy labels and uncategorized products (%s)', async (category, expected) => {
    vi.mocked(apiGet).mockResolvedValueOnce([product(category)]);

    const products = await fetchPOSProducts();

    expect(products[0]?.category).toBe(expected);
  });
});
