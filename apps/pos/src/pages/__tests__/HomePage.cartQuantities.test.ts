import { describe, expect, it } from 'vitest';
import { sumSaleQuantities } from '@/lib/cartChipQuantities';
import type { CartItem } from '@/types/cart';

/**
 * HomePage `cartQuantities` memo contract (adversarial review Major 2):
 * the in-cart chip shows units being SOLD. Return-kind lines (exchange flow,
 * hydrateFromReceipt — NEGATIVE quantities) must never poison the count.
 */
function makeItem(
  productId: string,
  quantity: number,
  kind?: CartItem['kind'],
): CartItem {
  return {
    id: `line-${productId}-${String(quantity)}-${kind ?? 'default'}`,
    product: { id: productId, name: `Product ${productId}`, sku: `SKU-${productId}`, price: '10.000' },
    quantity,
    unit_price: '10.000',
    tax_rate: '19.00',
    tax_amount: '0.000',
    line_total: '10.000',
    ...(kind !== undefined ? { kind } : {}),
  };
}

describe('sumSaleQuantities (HomePage cartQuantities memo)', () => {
  it('returns an empty map for an empty cart', () => {
    expect(sumSaleQuantities([])).toEqual({});
  });

  it('sums multiple sale lines of the same product (e.g. different modifiers)', () => {
    const map = sumSaleQuantities([makeItem('p1', 2), makeItem('p1', 3), makeItem('p2', 1)]);
    expect(map).toEqual({ p1: 5, p2: 1 });
  });

  it("treats kind: undefined as 'sale' (store convention `(kind ?? 'sale') === 'sale'`)", () => {
    const map = sumSaleQuantities([makeItem('p1', 2), makeItem('p1', 1, 'sale')]);
    expect(map).toEqual({ p1: 3 });
  });

  it('return-only product gets NO entry — chip falls back to the check glyph, never "-1"/"0"', () => {
    const map = sumSaleQuantities([makeItem('p1', -1, 'return')]);
    expect('p1' in map).toBe(false);
    expect(map).toEqual({});
  });

  it('sale 3 + return 1 of the same product shows 3 (units being sold), not the net 2', () => {
    const map = sumSaleQuantities([makeItem('p1', 3, 'sale'), makeItem('p1', -1, 'return')]);
    expect(map).toEqual({ p1: 3 });
  });

  it('mixed cart: return lines excluded, sale lines of other products unaffected', () => {
    const map = sumSaleQuantities([
      makeItem('p1', 2),
      makeItem('p2', -3, 'return'),
      makeItem('p3', 1, 'sale'),
    ]);
    expect(map).toEqual({ p1: 2, p3: 1 });
  });
});
