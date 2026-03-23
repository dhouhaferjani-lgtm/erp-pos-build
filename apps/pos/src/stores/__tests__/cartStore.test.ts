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

  it('calculates total with transaction discount', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00' }));
    useCartStore.getState().setTransactionDiscount({ amount: '10.00' });

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
    useCartStore.getState().setTransactionDiscount({ amount: '100.00' });

    expect(useCartStore.getState().total()).toBe(0);
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
