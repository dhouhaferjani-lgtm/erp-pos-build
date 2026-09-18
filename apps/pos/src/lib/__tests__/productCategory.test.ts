import { describe, expect, it } from 'vitest';
import { cachedProductCategoryLabel, productCategoryLabel, toPOSProduct } from '../productCategory';
import { productCategory } from '@/test/fixtures/productCategory';

describe('cachedProductCategoryLabel', () => {
  it('decodes the full CategoryData shape', () => {
    expect(cachedProductCategoryLabel(JSON.stringify(productCategory()))).toBe('Soins visage');
  });

  it('decodes a cache written before newer DTO fields existed (id/name/slug are enough)', () => {
    expect(
      cachedProductCategoryLabel(
        JSON.stringify({ id: 12, name: 'Soins visage', slug: 'soins-visage' }),
      ),
    ).toBe('Soins visage');
    expect(
      cachedProductCategoryLabel(
        JSON.stringify({ id: 12, name: 'Soins visage', slug: 'soins-visage', path: '12', depth: 0 }),
      ),
    ).toBe('Soins visage');
  });

  it.each([
    'Boissons',
    '{Soin}',
    '{"name":"operator label"}',
    '["Soins"]',
    '{"id":"12","name":"x","slug":"x"}',
    // Leading whitespace: migration 68 matches `category LIKE '{%'`, so a row
    // like this is NOT rewritten on disk — the reader must not decode it
    // either, or the label flips depending on which path touched the row.
    ' {"id":12,"name":"x","slug":"x"}',
  ])('keeps operator labels that merely look like JSON: %s', (label) => {
    expect(cachedProductCategoryLabel(label)).toBe(label);
  });

  it('maps null to undefined', () => {
    expect(cachedProductCategoryLabel(null)).toBeUndefined();
  });
});

describe('productCategoryLabel / toPOSProduct', () => {
  it('projects the DTO name and passes strings through', () => {
    expect(productCategoryLabel(productCategory({ name: 'Boissons' }))).toBe('Boissons');
    expect(productCategoryLabel('Boissons')).toBe('Boissons');
    expect(productCategoryLabel(null)).toBeUndefined();
    expect(
      toPOSProduct({
        id: 'p',
        name: 'n',
        sku: 's',
        barcode: null,
        sale_price: '1.000',
        stock_quantity: 0,
        category: productCategory(),
      }).category,
    ).toBe('Soins visage');
  });
});
