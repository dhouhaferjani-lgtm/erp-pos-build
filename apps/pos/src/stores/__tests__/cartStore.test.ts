import { describe, it, expect, beforeEach } from 'vitest';
import { useCartStore, computeTaxAmount } from '../cartStore';
import type { POSProduct, POSProductVariant } from '@/types/product';

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

  it('keeps the product quantity precision on a newly added cart line', () => {
    useCartStore.getState().addItem(makeProduct({ quantity_decimals: 2 }));

    expect(useCartStore.getState().items[0]!.product.quantity_decimals).toBe(2);
  });

  it('exposes gross subtotal + line-discount total separately from the cart discount (PaymentSummary §5.1 breakdown)', () => {
    const store = useCartStore.getState();
    store.addItem(makeProduct({ sale_price: '10.00' }));
    const id = useCartStore.getState().items[0]!.id;
    store.applyLineDiscount(id, { type: 'fixed', value: '2.00' });
    store.setTransactionDiscount({ type: 'fixed', value: '1.00' });

    const s = useCartStore.getState();
    expect(s.subtotalString()).toBe('8.00'); // net of the line discount
    expect(s.lineDiscountTotalString()).toBe('2.00'); // Remises produits
    expect(s.grossSubtotalString()).toBe('10.00'); // Sous-total (gross, before any discount)
    expect(s.discountAmountString()).toBe('1.00'); // Remise panier (cart-level)
    expect(s.total()).toBe(7); // 10 gross − 2 line − 1 cart
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

  it('C2 Day 2: keeps composite-id rows for the same sellable across categories as separate lines', () => {
    // Menu tenants emit one POSProduct per (sellable, category) composite
    // via `${sellable_id}_${menu_category_id}`. Two cart adds of the
    // SAME sellable from DIFFERENT categories must produce two cart
    // lines, not be deduped — the cashier is intentionally pricing
    // each as the category-specific row (e.g. "Cola @ Drinks" vs
    // "Cola @ Lunch combo"). The existing addItem dedup keys on
    // `product.id`, which for composite-id rows is already
    // `${sellable}_${category}` — distinct ids → distinct lines.
    useCartStore.getState().addItem(makeProduct({
      id: 'cola_drinks',
      name: 'Cola',
      category: 'Drinks',
      menu_category_id: 'cat-drinks',
      sale_price: '3.00',
    }));
    useCartStore.getState().addItem(makeProduct({
      id: 'cola_combos',
      name: 'Cola',
      category: 'Lunch combos',
      menu_category_id: 'cat-combos',
      sale_price: '1.50',
    }));

    const items = useCartStore.getState().items;
    expect(items).toHaveLength(2);
    expect(items[0]!.product.id).toBe('cola_drinks');
    expect(items[0]!.unit_price).toBe('3.00');
    expect(items[1]!.product.id).toBe('cola_combos');
    expect(items[1]!.unit_price).toBe('1.50');
  });

  it('C2 Day 2: still merges quantity when the SAME composite-id row is added twice', () => {
    const cola = makeProduct({
      id: 'cola_drinks',
      name: 'Cola',
      category: 'Drinks',
      menu_category_id: 'cat-drinks',
      sale_price: '3.00',
    });
    useCartStore.getState().addItem(cola);
    useCartStore.getState().addItem(cola);

    const items = useCartStore.getState().items;
    expect(items).toHaveLength(1);
    expect(items[0]!.quantity).toBe(2);
    expect(items[0]!.line_total).toBe('6.00');
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

  it('sums fractional quantity × price without float drift (Big.js)', () => {
    // 0.1 + 0.2 = 0.30000000000000004 with Number arithmetic. Three €0.10
    // lines must subtotal to exactly 0.30, and the subtotalString accessor
    // must return the exact currency-scale string with no drift.
    useCartStore.getState().addItem(makeProduct({ id: 'p1', sale_price: '0.10' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p2', sale_price: '0.20' }));

    expect(useCartStore.getState().subtotalString()).toBe('0.30');
    expect(useCartStore.getState().subtotal()).toBe(0.3);
  });

  it('multiplies a fractional quantity by unit price exactly', () => {
    // 1.5 kg × €2.99 = €4.485 → €4.49 at EUR scale 2 (round-half-up).
    useCartStore.getState().addItem(makeProduct({ id: 'kg', sale_price: '2.99' }));
    const itemId = useCartStore.getState().items[0]!.id;
    useCartStore.getState().updateQuantity(itemId, 1.5);

    const item = useCartStore.getState().items[0]!;
    expect(item.quantity).toBe(1.5);
    expect(item.line_total).toBe('4.49');
  });

  it('aggregates many fractional lines without accumulating drift', () => {
    // Ten €0.10 lines → €1.00 exactly. Number reduce drifts to 0.9999999999.
    for (let i = 0; i < 10; i++) {
      useCartStore.getState().addItem(makeProduct({ id: `f${String(i)}`, sale_price: '0.10' }));
    }
    expect(useCartStore.getState().subtotalString()).toBe('1.00');
    expect(useCartStore.getState().total()).toBe(1);
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

  describe('added-line pulse tracking (cart-always-foreground v1)', () => {
    it('stamps lastAddedLineId and bumps the nonce when a new line is added', () => {
      expect(useCartStore.getState().lastAddedLineId).toBeNull();
      expect(useCartStore.getState().lastAddedNonce).toBe(0);

      useCartStore.getState().addItem(makeProduct());
      const state = useCartStore.getState();
      expect(state.lastAddedLineId).toBe(state.items[0]!.id);
      expect(state.lastAddedNonce).toBe(1);
    });

    it('stamps the MERGED line id and bumps the nonce on a repeat add of the same product', () => {
      const product = makeProduct();
      useCartStore.getState().addItem(product);
      useCartStore.getState().addItem(product);

      const state = useCartStore.getState();
      expect(state.items).toHaveLength(1); // merged, not a second line
      expect(state.lastAddedLineId).toBe(state.items[0]!.id);
      expect(state.lastAddedNonce).toBe(2); // re-fires the pulse on the same line
    });

    it('routes addItemWithDefaults through the same pulse stamp', () => {
      useCartStore.getState().addItemWithDefaults(makeProduct());
      const state = useCartStore.getState();
      expect(state.lastAddedLineId).toBe(state.items[0]!.id);
      expect(state.lastAddedNonce).toBe(1);
    });

    it('stamps the pulse on updateLineModifiers — customize-EDIT confirm (Rev 2, U9)', () => {
      useCartStore.getState().addItem(makeProduct());
      const lineId = useCartStore.getState().items[0]!.id;

      useCartStore.getState().updateLineModifiers(lineId, []);
      const state = useCartStore.getState();
      expect(state.lastAddedLineId).toBe(lineId);
      expect(state.lastAddedNonce).toBe(2); // 1 from addItem, +1 from the edit
    });

    it('does NOT stamp the pulse when updateLineModifiers misses (vanished line stays a no-op)', () => {
      useCartStore.getState().addItem(makeProduct());
      useCartStore.getState().updateLineModifiers('no-such-line', []);
      expect(useCartStore.getState().lastAddedNonce).toBe(1); // unchanged
    });

    it('resets pulse state on clearCart and replaceCart (recalls must not pulse)', () => {
      useCartStore.getState().addItem(makeProduct());
      useCartStore.getState().clearCart('checkout');
      expect(useCartStore.getState().lastAddedLineId).toBeNull();
      expect(useCartStore.getState().lastAddedNonce).toBe(0);

      useCartStore.getState().addItem(makeProduct());
      useCartStore.getState().replaceCart([], undefined);
      expect(useCartStore.getState().lastAddedLineId).toBeNull();
      expect(useCartStore.getState().lastAddedNonce).toBe(0);
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

  describe('T2 variant identity', () => {
    function makeVariant(overrides: Partial<POSProductVariant> = {}): POSProductVariant {
      return {
        id: 'var-1',
        product_id: 'prod-1',
        variant_code: 'SHOE-39-BLK',
        sku: 'SHOE-39-BLK',
        barcode: '111',
        name_suffix: ' — 39 / Black',
        is_default: false,
        is_active: true,
        display_order: 0,
        price_override: null,
        image_url: null,
        stock_quantity: 7,
        ...overrides,
      };
    }

    it('stamps variant identity on the cart line when a variant is supplied', () => {
      const product = makeProduct({ id: 'prod-1', name: 'Shoe', has_variants: true });
      useCartStore.getState().addItem(product, undefined, makeVariant());

      const item = useCartStore.getState().items[0]!;
      expect(item.product.variant_id).toBe('var-1');
      expect(item.product.variant_name).toBe('Shoe — 39 / Black');
    });

    it('uses the variant price_override for unit price when present', () => {
      const product = makeProduct({ id: 'prod-1', sale_price: '10.00', has_variants: true });
      useCartStore
        .getState()
        .addItem(product, undefined, makeVariant({ price_override: '14.50' }));

      const item = useCartStore.getState().items[0]!;
      expect(item.unit_price).toBe('14.50');
      expect(item.line_total).toBe('14.50');
    });

    it('falls back to the product sale_price when variant price_override is null', () => {
      const product = makeProduct({ id: 'prod-1', sale_price: '10.00', has_variants: true });
      useCartStore
        .getState()
        .addItem(product, undefined, makeVariant({ price_override: null }));

      expect(useCartStore.getState().items[0]!.unit_price).toBe('10.00');
    });

    it('merges and increments the same variant of the same product', () => {
      const product = makeProduct({ id: 'prod-1', has_variants: true });
      useCartStore.getState().addItem(product, undefined, makeVariant({ id: 'var-1' }));
      useCartStore.getState().addItem(product, undefined, makeVariant({ id: 'var-1' }));

      const items = useCartStore.getState().items;
      expect(items).toHaveLength(1);
      expect(items[0]!.quantity).toBe(2);
    });

    it('keeps different variants of the same product as separate lines', () => {
      const product = makeProduct({ id: 'prod-1', has_variants: true });
      useCartStore
        .getState()
        .addItem(product, undefined, makeVariant({ id: 'var-1', name_suffix: ' — 39' }));
      useCartStore
        .getState()
        .addItem(product, undefined, makeVariant({ id: 'var-2', name_suffix: ' — 40' }));

      const items = useCartStore.getState().items;
      expect(items).toHaveLength(2);
      expect(items[0]!.product.variant_id).toBe('var-1');
      expect(items[1]!.product.variant_id).toBe('var-2');
    });
  });
});

describe('D0-3: applyLineDiscount / removeLineDiscount — bcmath precision (S2)', () => {
  beforeEach(() => {
    useCartStore.getState().clearCart();
  });

  it('percentage discount uses bcmath arithmetic (10.05% of €10.00 must not float-drift)', () => {
    // Discriminating case: parseFloat('10.05') is slightly below 10.05 in IEEE-754.
    // float: (10.0 * parseFloat('10.05')) / 100 ≈ 1.00499... → toFixed(2) = '1.00' (wrong).
    // bcmath: bcdiv(bcmul('10.00', '10.05', 3), '100', 2) = 1.005 → round-half-up = '1.01'.
    // line_total:
    //   float: 10.0 - 1.00499... = 8.99500... → toFixed(2) drifts
    //   bcmath: bcsub('10.00', '1.01', 2) = '8.99'
    useCartStore.getState().addItem(makeProduct({ sale_price: '10.00' }));
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().applyLineDiscount(itemId, { type: 'percentage', value: '10.05' });

    const item = useCartStore.getState().items[0]!;
    // float gives '1.00' (drift); bcmath gives '1.01' (correct round-half-up of 1.005)
    expect(item.discount_amount).toBe('1.01');
    // bcmath: bcsub('10.00', '1.01', 2) = '8.99'
    expect(item.line_total).toBe('8.99');
  });

  it('fixed discount uses bcformat, not parseFloat (persists exact string at currency scale)', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '20.00' }));
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().applyLineDiscount(itemId, { type: 'fixed', value: '5.005' });

    const item = useCartStore.getState().items[0]!;
    // bcformat('5.005', 2) rounds half-up → '5.01'
    // float parseFloat('5.005').toFixed(2) ≈ '5.00' (5.005 in IEEE-754 is below 5.005)
    expect(item.discount_amount).toBe('5.01');
    expect(item.line_total).toBe('14.99');
  });

  it('removeLineDiscount restores line_total via bcmath when unit_price has sub-scale decimals', () => {
    // 3.335 × 3 = 10.005 → round-half-up at 2dp = '10.01'.
    // float: parseFloat('3.335') ≈ 3.33499... → 3.33499... * 3 = 10.00499... → toFixed(2) = '10.00' (wrong).
    // bcmath: bcmul('3.335', '3', 2) → Big(10.005).toFixed(2, ROUND_HALF_UP) = '10.01' (correct).
    // Such unit_prices arise when a server-side discount yields a price at higher precision.
    useCartStore.getState().replaceCart(
      [
        {
          id: 'prec-line',
          product: { id: 'p-prec', name: 'Precision Item', sku: 'SKU-P', price: '3.335' },
          quantity: 3,
          unit_price: '3.335',
          line_total: '10.01',
          tax_rate: '0',
          tax_amount: '0.00',
        },
      ],
      undefined,
    );

    // Apply then remove a fixed discount — removeLineDiscount must recompute grossTotal via bcmath.
    useCartStore.getState().applyLineDiscount('prec-line', { type: 'fixed', value: '1.00' });
    useCartStore.getState().removeLineDiscount('prec-line');

    const item = useCartStore.getState().items[0]!;
    // float gives '10.00' (drift); bcmath gives '10.01' (correct)
    expect(item.line_total).toBe('10.01');
    expect(item.discount_amount).toBeUndefined();
    expect(item.discount_type).toBeUndefined();
  });
});
