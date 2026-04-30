import { describe, it, expect, beforeEach } from 'vitest';
import { useCartStore, computeTaxAmount } from '../cartStore';
import type { POSProduct } from '@/types/product';

function makeProduct(overrides: Partial<POSProduct> = {}): POSProduct {
  return {
    id: 'prod-1',
    name: 'Test Product',
    sku: 'SKU-001',
    sale_price: '10.00',
    stock_quantity: 50,
    ...overrides,
  };
}

describe('cartStore', () => {
  beforeEach(() => {
    useCartStore.getState().clearCart();
  });

  it('adds a new item to the cart', () => {
    const product = makeProduct();
    useCartStore.getState().addItem(product);

    const items = useCartStore.getState().items;
    expect(items).toHaveLength(1);
    expect(items[0]!.product.id).toBe('prod-1');
    expect(items[0]!.quantity).toBe(1);
    expect(items[0]!.unit_price).toBe('10.00');
    expect(items[0]!.line_total).toBe('10.00');
  });

  it('increments quantity when adding same product twice', () => {
    const product = makeProduct();
    useCartStore.getState().addItem(product);
    useCartStore.getState().addItem(product);

    const items = useCartStore.getState().items;
    expect(items).toHaveLength(1);
    expect(items[0]!.quantity).toBe(2);
    expect(items[0]!.line_total).toBe('20.00');
  });

  it('adds separate lines for different products', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'prod-1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2', name: 'Product 2' }));

    expect(useCartStore.getState().items).toHaveLength(2);
  });

  it('updates quantity of an existing item', () => {
    useCartStore.getState().addItem(makeProduct());
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().updateQuantity(itemId, 5);

    const item = useCartStore.getState().items[0]!;
    expect(item.quantity).toBe(5);
    expect(item.line_total).toBe('50.00');
  });

  it('removes item when quantity set to 0', () => {
    useCartStore.getState().addItem(makeProduct());
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().updateQuantity(itemId, 0);

    expect(useCartStore.getState().items).toHaveLength(0);
  });

  it('removes a specific item by id', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'prod-1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2' }));
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().removeItem(itemId);

    expect(useCartStore.getState().items).toHaveLength(1);
    expect(useCartStore.getState().items[0]!.product.id).toBe('prod-2');
  });

  it('clears all items from the cart', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'prod-1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2' }));

    useCartStore.getState().clearCart();

    expect(useCartStore.getState().items).toHaveLength(0);
  });

  it('calculates subtotal correctly', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'prod-1', sale_price: '10.00' }));
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2', sale_price: '25.50' }));

    expect(useCartStore.getState().subtotal()).toBeCloseTo(35.5);
  });

  it('calculates total with fixed transaction discount', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00' }));
    useCartStore.getState().setTransactionDiscount({ type: 'fixed', value: '10.00' });

    expect(useCartStore.getState().discountAmount()).toBeCloseTo(10);
    expect(useCartStore.getState().total()).toBeCloseTo(90);
  });

  it('calculates item count as sum of quantities', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'prod-1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'prod-1' })); // +1 qty
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2' }));

    expect(useCartStore.getState().itemCount()).toBe(3);
  });

  it('always adds new line for items with modifiers', () => {
    const product = makeProduct();
    const modifiers = [
      {
        modifier_id: 'mod-1',
        modifier_group_id: 'grp-1',
        name: 'Extra Cheese',
        group_name: 'Toppings',
        price_adjustment: '2.00',
      },
    ];

    useCartStore.getState().addItem(product);
    useCartStore.getState().addItem(product, modifiers);

    const items = useCartStore.getState().items;
    expect(items).toHaveLength(2);
    expect(items[0]!.unit_price).toBe('10.00');
    expect(items[1]!.unit_price).toBe('12.00'); // 10 + 2 modifier
  });

  it('total never goes below 0', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '5.00' }));
    useCartStore.getState().setTransactionDiscount({ type: 'fixed', value: '100.00' });

    expect(useCartStore.getState().total()).toBe(0);
  });

  it('recalculates percentage discount when items change', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'prod-1', sale_price: '100.00' }));
    useCartStore.getState().setTransactionDiscount({ type: 'percentage', value: '10' });
    expect(useCartStore.getState().discountAmount()).toBeCloseTo(10);
    expect(useCartStore.getState().total()).toBeCloseTo(90);

    // Add another item — discount should recalculate
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2', sale_price: '100.00' }));
    expect(useCartStore.getState().discountAmount()).toBeCloseTo(20); // 10% of 200
    expect(useCartStore.getState().total()).toBeCloseTo(180);
  });

  it('removes transaction discount when set to undefined', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00' }));
    useCartStore.getState().setTransactionDiscount({ type: 'percentage', value: '10' });
    expect(useCartStore.getState().discountAmount()).toBeCloseTo(10);

    useCartStore.getState().setTransactionDiscount(undefined);
    expect(useCartStore.getState().discountAmount()).toBe(0);
    expect(useCartStore.getState().total()).toBeCloseTo(100);
  });

  it('recalculates tax_amount when line discount is applied via setState', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00', tax_rate: '10' }));
    const item = useCartStore.getState().items[0]!;
    // Tax-inclusive: 100 with 10% → tax = 100 - 100/1.1 = 9.09
    expect(parseFloat(item.tax_amount)).toBeCloseTo(9.09);

    // Simulate line discount: 20% off → lineTotal = 80
    useCartStore.setState((state) => ({
      items: state.items.map((i) => {
        const grossTotal = parseFloat(i.unit_price) * i.quantity;
        const discountAmount = (grossTotal * 20) / 100;
        const lineTotal = Math.max(0, grossTotal - discountAmount);
        const rate = parseFloat(i.tax_rate);
        // Tax-inclusive extraction
        const taxAmount = lineTotal - lineTotal / (1 + rate / 100);
        return {
          ...i,
          discount_type: 'percentage' as const,
          discount_percent: '20',
          discount_amount: discountAmount.toFixed(2),
          line_total: lineTotal.toFixed(2),
          tax_amount: taxAmount.toFixed(2),
        };
      }),
    }));

    const updated = useCartStore.getState().items[0]!;
    // 80 with 10% inclusive: tax = 80 - 80/1.1 = 7.27
    expect(parseFloat(updated.tax_amount)).toBeCloseTo(7.27);
    // Total = subtotal (80), no tax added on top
    expect(useCartStore.getState().total()).toBeCloseTo(80);
  });

  it('calculates total correctly with tax-inclusive pricing', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00', tax_rate: '20' }));
    // line_total = 100 (tax-inclusive), tax_amount = 16.67 (extracted)
    // total = subtotal = 100 (NOT 100 + 16.67 = 116.67)
    expect(useCartStore.getState().total()).toBeCloseTo(100);
    expect(useCartStore.getState().taxAmount()).toBeCloseTo(16.67);
    expect(useCartStore.getState().subtotal()).toBeCloseTo(100);
  });

  describe('replaceCart', () => {
    it('atomically replaces items and transactionDiscount', () => {
      // seed some state
      useCartStore.getState().addItem(makeProduct());
      useCartStore.getState().setTransactionDiscount({ type: 'fixed', value: '1.00' });

      const replacementItems = [
        {
          id: 'line-1',
          product: { id: 'p-new', name: 'Foo', sku: 'FOO', price: '9.80' },
          quantity: 1,
          unit_price: '9.80',
          line_total: '9.80',
          tax_rate: '0',
          tax_amount: '0.00',
        },
        {
          id: 'line-2',
          product: { id: 'p-new-2', name: 'Bar', sku: 'BAR', price: '6.30' },
          quantity: 1,
          unit_price: '6.30',
          line_total: '6.30',
          tax_rate: '0',
          tax_amount: '0.00',
        },
      ];

      useCartStore.getState().replaceCart(replacementItems, undefined);

      const state = useCartStore.getState();
      expect(state.items).toEqual(replacementItems);
      expect(state.transactionDiscount).toBeUndefined();
      expect(state.subtotal()).toBeCloseTo(16.1);
      expect(state.total()).toBeCloseTo(16.1);
    });

    it('replaces items and carries a new transactionDiscount', () => {
      useCartStore.getState().replaceCart(
        [
          {
            id: 'line-1',
            product: { id: 'p-new', name: 'Foo', sku: 'FOO', price: '100.00' },
            quantity: 1,
            unit_price: '100.00',
            line_total: '100.00',
            tax_rate: '0',
            tax_amount: '0.00',
          },
        ],
        { type: 'fixed', value: '15.00', reason: 'Loyalty' },
      );

      const state = useCartStore.getState();
      expect(state.transactionDiscount).toEqual({ type: 'fixed', value: '15.00', reason: 'Loyalty' });
      expect(state.total()).toBeCloseTo(85);
    });

    it('clears the cart when called with an empty array and no discount', () => {
      useCartStore.getState().addItem(makeProduct());
      useCartStore.getState().replaceCart([], undefined);
      const state = useCartStore.getState();
      expect(state.items).toHaveLength(0);
      expect(state.transactionDiscount).toBeUndefined();
    });
  });
});

describe('tax recalculation regression', () => {
  beforeEach(() => {
    useCartStore.getState().clearCart();
  });

  it('taxAmount adjusts proportionally when a transaction discount is applied', () => {
    // €100 at 20% VAT (inclusive) → raw tax = 100 - 100/1.2 = 16.67
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00', tax_rate: '20' }));
    expect(useCartStore.getState().taxAmount()).toBeCloseTo(16.67);

    // 10% transaction discount: subtotal=100, discount=10, ratio=0.9
    // taxAmount = 16.67 * 0.9 ≈ 15.00
    useCartStore.getState().setTransactionDiscount({ type: 'percentage', value: '10' });
    expect(useCartStore.getState().taxAmount()).toBeCloseTo(15.0);
  });

  it('taxAmount restores fully when transaction discount is removed', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00', tax_rate: '20' }));
    useCartStore.getState().setTransactionDiscount({ type: 'percentage', value: '10' });
    expect(useCartStore.getState().taxAmount()).toBeCloseTo(15.0);

    useCartStore.getState().setTransactionDiscount(undefined);
    expect(useCartStore.getState().taxAmount()).toBeCloseTo(16.67);
    expect(useCartStore.getState().total()).toBeCloseTo(100);
  });

  it('taxAmount and subtotal restore after line discount is removed', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00', tax_rate: '20' }));
    const itemId = useCartStore.getState().items[0]!.id;

    // Apply 20% line discount: line_total = 80, tax = 80 - 80/1.2 = 13.33
    useCartStore.setState((state) => ({
      items: state.items.map((i) => {
        if (i.id !== itemId) return i;
        const gross = parseFloat(i.unit_price) * i.quantity;
        const discAmt = (gross * 20) / 100;
        const lineTotal = gross - discAmt;
        const rate = parseFloat(i.tax_rate);
        return {
          ...i,
          discount_type: 'percentage' as const,
          discount_percent: '20',
          discount_amount: discAmt.toFixed(2),
          line_total: lineTotal.toFixed(2),
          tax_amount: (lineTotal - lineTotal / (1 + rate / 100)).toFixed(2),
        };
      }),
    }));
    expect(useCartStore.getState().taxAmount()).toBeCloseTo(13.33);
    expect(useCartStore.getState().subtotal()).toBeCloseTo(80);

    // Simulate handleRemoveLineDiscount: clear discount fields, restore line_total
    const grossTotal = 100;
    useCartStore.setState((state) => ({
      items: state.items.map((i) => {
        if (i.id !== itemId) return i;
        const rate = parseFloat(i.tax_rate);
        return {
          ...i,
          discount_type: undefined,
          discount_percent: undefined,
          discount_amount: undefined,
          discount_reason: undefined,
          line_total: grossTotal.toFixed(2),
          tax_amount: (grossTotal - grossTotal / (1 + rate / 100)).toFixed(2),
        };
      }),
    }));
    expect(useCartStore.getState().taxAmount()).toBeCloseTo(16.67);
    expect(useCartStore.getState().subtotal()).toBeCloseTo(100);
  });

  it('taxAmount is zero after all items are cleared', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '50.00', tax_rate: '20' }));
    useCartStore.getState().setTransactionDiscount({ type: 'fixed', value: '5.00' });
    useCartStore.getState().clearCart();

    expect(useCartStore.getState().taxAmount()).toBe(0);
    expect(useCartStore.getState().total()).toBe(0);
    expect(useCartStore.getState().transactionDiscount).toBeUndefined();
  });
});

describe('computeTaxAmount', () => {
  it('extracts tax from tax-inclusive price', () => {
    // 100 with 20% VAT: net = 100/1.2 = 83.33, tax = 16.67
    expect(computeTaxAmount(100, '20')).toBe('16.67');
  });

  it('returns zero for zero tax rate', () => {
    expect(computeTaxAmount(100, '0')).toBe('0.00');
  });

  it('returns zero for zero line total', () => {
    expect(computeTaxAmount(0, '20')).toBe('0.00');
  });

  it('handles negative tax rate', () => {
    expect(computeTaxAmount(100, '-5')).toBe('0.00');
  });

  it('extracts 10% tax correctly', () => {
    // 2.000 TND with 10% VAT: net = 2/1.1 = 1.818, tax = 0.182
    expect(computeTaxAmount(2, '10')).toBe('0.18');
  });
});

describe('cartStore — return-line updateQuantity semantics (Bug 2 fix)', () => {
  function makeReturnLineAt(id: string, qty: number): import('@/types/cart').CartItem {
    // unit_price 10.00; line_total is already negative for return lines
    return {
      id,
      product: { id: `prod-${id}`, name: 'Item', sku: 'SKU', price: '10.00' },
      quantity: qty,
      unit_price: '10.00',
      line_total: (10 * qty).toFixed(2), // e.g. -20.00 for qty -2
      tax_rate: '0',
      tax_amount: '0.00',
      kind: 'return',
    };
  }

  beforeEach(() => {
    useCartStore.getState().clearCart();
  });

  it('increments a return line (makes qty more negative) without removing it', () => {
    // Hydrate with qty -2 (returning 2 units)
    const item = makeReturnLineAt('r1', -2);
    useCartStore.getState().replaceReturnItems([item]);
    const itemId = useCartStore.getState().returnItems()[0]!.id;

    // Simulate ReturnLineItem.handleIncrement: -(absQty + 1) = -(2 + 1) = -3
    useCartStore.getState().updateQuantity(itemId, -3);

    const updated = useCartStore.getState().returnItems()[0]!;
    expect(updated.quantity).toBe(-3);
    expect(updated.line_total).toBe('-30.00');
  });

  it('decrements a return line (makes qty less negative) correctly', () => {
    const item = makeReturnLineAt('r1', -3);
    useCartStore.getState().replaceReturnItems([item]);
    const itemId = useCartStore.getState().returnItems()[0]!.id;

    // -(absQty - 1) = -(3 - 1) = -2
    useCartStore.getState().updateQuantity(itemId, -2);

    const updated = useCartStore.getState().returnItems()[0]!;
    expect(updated.quantity).toBe(-2);
    expect(updated.line_total).toBe('-20.00');
  });

  it('removes a return line when quantity reaches exactly 0', () => {
    const item = makeReturnLineAt('r1', -1);
    useCartStore.getState().replaceReturnItems([item]);
    const itemId = useCartStore.getState().returnItems()[0]!.id;

    useCartStore.getState().updateQuantity(itemId, 0);

    expect(useCartStore.getState().returnItems()).toHaveLength(0);
  });

  it('still removes a sale line when quantity is set to 0 (existing behaviour preserved)', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'sale-1', sale_price: '20.00' }));
    const itemId = useCartStore.getState().saleItems()[0]!.id;

    useCartStore.getState().updateQuantity(itemId, 0);

    expect(useCartStore.getState().saleItems()).toHaveLength(0);
  });
});

describe('cartStore — refund/return sections (Task 52)', () => {
  function makeReturnItem(id: string, lineTotal: string): import('@/types/cart').CartItem {
    return {
      id,
      product: { id: `prod-${id}`, name: 'Item', sku: 'SKU', price: '10.00' },
      quantity: -1,
      unit_price: '10.00',
      line_total: lineTotal,
      tax_rate: '0',
      tax_amount: '0.00',
      kind: 'return',
    };
  }

  beforeEach(() => {
    useCartStore.getState().clearCart();
  });

  describe('returnItems() and saleItems() selectors', () => {
    it('returnItems returns only kind=return items', () => {
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-10.00')]);
      useCartStore.getState().addItem(makeProduct({ id: 'sale-1', sale_price: '5.00' }));

      expect(useCartStore.getState().returnItems()).toHaveLength(1);
      expect(useCartStore.getState().returnItems()[0]!.kind).toBe('return');
    });

    it('saleItems returns only kind=sale items (including undefined kind)', () => {
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-10.00')]);
      useCartStore.getState().addItem(makeProduct({ id: 'sale-1', sale_price: '5.00' }));

      const saleItems = useCartStore.getState().saleItems();
      expect(saleItems).toHaveLength(1);
      expect(saleItems[0]!.product.id).toBe('sale-1');
    });
  });

  describe('replaceReturnItems', () => {
    it('replaces all return items atomically', () => {
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-10.00')]);
      useCartStore.getState().replaceReturnItems([makeReturnItem('r2', '-5.00'), makeReturnItem('r3', '-3.00')]);

      const returnItems = useCartStore.getState().returnItems();
      expect(returnItems).toHaveLength(2);
      expect(returnItems.map((i) => i.id)).toEqual(['r2', 'r3']);
    });

    it('does not disturb sale items when replacing return items', () => {
      useCartStore.getState().addItem(makeProduct({ id: 'sale-1', sale_price: '20.00' }));
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-10.00')]);

      expect(useCartStore.getState().saleItems()).toHaveLength(1);
      expect(useCartStore.getState().returnItems()).toHaveLength(1);
    });
  });

  describe('clearReturnItems', () => {
    it('removes all return items but leaves sale items intact', () => {
      useCartStore.getState().addItem(makeProduct({ id: 's1', sale_price: '15.00' }));
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-5.00')]);

      useCartStore.getState().clearReturnItems();

      expect(useCartStore.getState().returnItems()).toHaveLength(0);
      expect(useCartStore.getState().saleItems()).toHaveLength(1);
    });
  });

  describe('addReturnItems', () => {
    it('appends additional return items without replacing existing ones', () => {
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-10.00')]);
      useCartStore.getState().addReturnItems([makeReturnItem('r2', '-5.00')]);

      expect(useCartStore.getState().returnItems()).toHaveLength(2);
    });
  });

  describe('netTotal()', () => {
    it('equals sale total when there are no return items', () => {
      useCartStore.getState().addItem(makeProduct({ id: 's1', sale_price: '50.00' }));

      expect(useCartStore.getState().netTotal()).toBeCloseTo(50);
    });

    it('equals negative return total when there are no sale items', () => {
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-30.00')]);

      // net = 0 (sale) − 30 (abs return) = -30
      expect(useCartStore.getState().netTotal()).toBeCloseTo(-30);
    });

    it('computes net correctly in exchange mode (sale > return)', () => {
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-10.00')]);
      useCartStore.getState().addItem(makeProduct({ id: 's1', sale_price: '25.00' }));

      // net = 25 − 10 = 15 → cashier charges 15
      expect(useCartStore.getState().netTotal()).toBeCloseTo(15);
    });

    it('computes net correctly in pure refund mode (return > sale)', () => {
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-40.00')]);
      // no sale items
      expect(useCartStore.getState().netTotal()).toBeCloseTo(-40);
    });

    it('returns 0 when sale total equals return total', () => {
      useCartStore.getState().replaceReturnItems([makeReturnItem('r1', '-20.00')]);
      useCartStore.getState().addItem(makeProduct({ id: 's1', sale_price: '20.00' }));

      expect(useCartStore.getState().netTotal()).toBeCloseTo(0);
    });

    it('items with undefined kind are treated as sale items in netTotal', () => {
      // replaceCart with items that don't have a kind field
      useCartStore.getState().replaceCart(
        [
          {
            id: 'line-no-kind',
            product: { id: 'p1', name: 'P', sku: 'P', price: '15.00' },
            quantity: 1,
            unit_price: '15.00',
            line_total: '15.00',
            tax_rate: '0',
            tax_amount: '0.00',
            // kind intentionally absent
          },
        ],
        undefined,
      );
      useCartStore.getState().addReturnItems([makeReturnItem('r1', '-5.00')]);

      expect(useCartStore.getState().netTotal()).toBeCloseTo(10);
    });
  });
});
