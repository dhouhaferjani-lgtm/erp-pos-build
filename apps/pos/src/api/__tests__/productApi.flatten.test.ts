/**
 * C2 Day 1 — flattenMenuToProducts emits composite IDs.
 *
 * Pre-C2: same sellable cross-listed in two menu categories collapsed in
 * the local SQLite catalog because both items emitted the same `id`
 * (= `sellable_id`). C2 fix: emit `${sellable_id}_${category_id}` as the
 * `POSProduct.id`, populate the new `sellable_id` and `menu_category_id`
 * fields, and let the productStore hold both rows distinctly.
 *
 * Standard-retail (non-Menu) products are not produced by this flattener
 * — they come from `fetchPOSProducts` — so there's no regression to guard
 * here; the regression case is exercised at the productRepository layer.
 */

import { describe, it, expect } from 'vitest';
import { flattenMenuToProducts } from '@/api/productApi';

const SELLABLE_COCA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const SELLABLE_FRIES = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
const CAT_DRINKS = 'cccc-1111-1111-1111-111111111111';
const CAT_COMBOS = 'cccc-2222-2222-2222-222222222222';
const CAT_SIDES = 'cccc-3333-3333-3333-333333333333';

function makeMenuItem(overrides: {
  id: string;
  sellable_id: string;
  name: string;
  effective_price: string;
}) {
  return {
    id: overrides.id,
    sellable_id: overrides.sellable_id,
    sellable_type: 'product',
    name: overrides.name,
    code: 'CODE',
    barcode: null,
    base_price: overrides.effective_price,
    effective_price: overrides.effective_price,
    image_url: null,
    tax_rate: '7.00',
    display_order: 0,
    is_available: true,
    modifier_groups: undefined,
  };
}

describe('flattenMenuToProducts — composite IDs (C2)', () => {
  it('emits TWO distinct rows when one sellable is cross-listed in two categories', () => {
    const menu = {
      categories: [
        {
          id: CAT_DRINKS,
          name: 'Drinks',
          position: 0,
          items: [
            makeMenuItem({
              id: 'menu-item-coca-drinks',
              sellable_id: SELLABLE_COCA,
              name: 'Coca',
              effective_price: '3.00',
            }),
          ],
        },
        {
          id: CAT_COMBOS,
          name: 'Combo Specials',
          position: 1,
          items: [
            makeMenuItem({
              id: 'menu-item-coca-combo',
              sellable_id: SELLABLE_COCA,
              name: 'Coca (Combo)',
              effective_price: '2.50',
            }),
          ],
        },
      ],
    };

    const products = flattenMenuToProducts(menu);

    expect(products).toHaveLength(2);
    const drinks = products.find((p) => p.menu_category_id === CAT_DRINKS);
    const combos = products.find((p) => p.menu_category_id === CAT_COMBOS);

    expect(drinks, 'Drinks-category POSProduct must exist').toBeDefined();
    expect(combos, 'Combo-Specials-category POSProduct must exist').toBeDefined();

    expect(drinks!.id).toBe(`${SELLABLE_COCA}_${CAT_DRINKS}`);
    expect(combos!.id).toBe(`${SELLABLE_COCA}_${CAT_COMBOS}`);
    expect(drinks!.sellable_id).toBe(SELLABLE_COCA);
    expect(combos!.sellable_id).toBe(SELLABLE_COCA);
    // Per-category effective price preserved.
    expect(drinks!.sale_price).toBe('3.00');
    expect(combos!.sale_price).toBe('2.50');
    // Display category surfaced from the parent category for receipt printing
    // / grouping.
    expect(drinks!.category).toBe('Drinks');
    expect(combos!.category).toBe('Combo Specials');
  });

  it('emits one row per (sellable, category) pair across multiple sellables and categories', () => {
    const menu = {
      categories: [
        {
          id: CAT_DRINKS,
          name: 'Drinks',
          position: 0,
          items: [
            makeMenuItem({
              id: 'i-drinks-coca',
              sellable_id: SELLABLE_COCA,
              name: 'Coca',
              effective_price: '3.00',
            }),
          ],
        },
        {
          id: CAT_SIDES,
          name: 'Sides',
          position: 1,
          items: [
            makeMenuItem({
              id: 'i-sides-fries',
              sellable_id: SELLABLE_FRIES,
              name: 'Fries',
              effective_price: '4.00',
            }),
          ],
        },
        {
          id: CAT_COMBOS,
          name: 'Combo Specials',
          position: 2,
          items: [
            makeMenuItem({
              id: 'i-combos-coca',
              sellable_id: SELLABLE_COCA,
              name: 'Coca (Combo)',
              effective_price: '2.50',
            }),
            makeMenuItem({
              id: 'i-combos-fries',
              sellable_id: SELLABLE_FRIES,
              name: 'Fries (Combo)',
              effective_price: '3.50',
            }),
          ],
        },
      ],
    };

    const products = flattenMenuToProducts(menu);

    // 2 unique sellables × different category coverage → 4 rows total.
    expect(products).toHaveLength(4);
    const ids = new Set(products.map((p) => p.id));
    expect(ids).toEqual(
      new Set([
        `${SELLABLE_COCA}_${CAT_DRINKS}`,
        `${SELLABLE_COCA}_${CAT_COMBOS}`,
        `${SELLABLE_FRIES}_${CAT_SIDES}`,
        `${SELLABLE_FRIES}_${CAT_COMBOS}`,
      ]),
    );
  });

  it('skips items where is_available is false', () => {
    const menu = {
      categories: [
        {
          id: CAT_DRINKS,
          name: 'Drinks',
          position: 0,
          items: [
            { ...makeMenuItem({ id: 'i1', sellable_id: SELLABLE_COCA, name: 'Coca', effective_price: '3.00' }), is_available: false },
          ],
        },
      ],
    };

    const products = flattenMenuToProducts(menu);

    expect(products).toHaveLength(0);
  });
});
