import { describe, it, expect } from 'vitest';
import { diffProducts } from '../productDiff';
import type { POSProduct } from '@/types/product';

const makeProduct = (id: string, price: string): POSProduct => ({
  id, name: `Product ${id}`, sku: `SKU-${id}`, sale_price: price,
  stock_quantity: 10, category: 'Test',
});

describe('diffProducts', () => {
  it('returns same references for unchanged products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const result = diffProducts(current, fetched);
    expect(result.changed).toBe(false);
    expect(result.products[0]).toBe(current[0]); // same reference
    expect(result.products[1]).toBe(current[1]); // same reference
  });

  it('replaces only changed products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '25.00')];
    const result = diffProducts(current, fetched);
    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(current[0]); // unchanged — same ref
    expect(result.products[1]).not.toBe(current[1]); // changed — new ref
    expect(result.products[1]!.sale_price).toBe('25.00');
  });

  it('detects added products', () => {
    const current = [makeProduct('1', '10.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const result = diffProducts(current, fetched);
    expect(result.changed).toBe(true);
    expect(result.products).toHaveLength(2);
  });

  it('detects removed products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00')];
    const result = diffProducts(current, fetched);
    expect(result.changed).toBe(true);
    expect(result.products).toHaveLength(1);
    expect(result.products.find(p => p.id === '2')).toBeUndefined();
  });

  it('handles modifier_groups comparison via JSON', () => {
    const p1 = { ...makeProduct('1', '10.00'), modifier_groups: [{ id: 'mg1', name: 'Size', selection_type: 'single' as const, min_selections: 0, max_selections: 1, is_required: false, position: 0, modifiers: [] }] };
    const p2 = { ...makeProduct('1', '10.00'), modifier_groups: [{ id: 'mg1', name: 'Size', selection_type: 'single' as const, min_selections: 0, max_selections: 1, is_required: false, position: 0, modifiers: [] }] };
    const result = diffProducts([p1], [p2]);
    expect(result.changed).toBe(false);
    expect(result.products[0]).toBe(p1);
  });
});
