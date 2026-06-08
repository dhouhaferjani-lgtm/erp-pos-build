import { describe, it, expect } from 'vitest';
import type { CartItem } from '@/stores/cartStore';
import { buildSaleReceiptPayload } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { bcadd, bccomp } from '@/lib/decimal';
import { buildAccountChargeCart } from '../accountChargeCartMapper';

function cart(): CartItem[] {
  return [
    {
      id: 'line-1',
      product: { id: 'prod-1', name: 'Widget', sku: 'SKU-1', price: '119.000' },
      quantity: 1,
      unit_price: '119.000',   // gross / tax-inclusive
      tax_rate: '19.00',
      line_total: '119.000',
      tax_amount: '19.000',
    } as unknown as CartItem,
  ];
}

describe('buildAccountChargeCart', () => {
  it('authors gross/inclusive unit_price and net line_subtotal', () => {
    const { lines } = buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: null });
    expect(lines).toHaveLength(1);
    expect(lines[0]!.unitPrice).toBe('119.000');     // gross verbatim
    expect(lines[0]!.lineSubtotal).toBe('100.000');  // net
    expect(lines[0]!.lineVat).toBe('19.000');
    expect(lines[0]!.quantity).toBe('1.000');
    expect(lines[0]!.vatRate).toBe('19.00');
    expect(lines[0]!.productId).toBe('prod-1');
  });

  it('matches the SALE_RECEIPT line mapping for the same cart (no inclusive drift)', () => {
    const { lines } = buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: null });
    const sale = buildSaleReceiptPayload({
      receiptId: '00000000-0000-4000-8000-000000000001',
      terminalId: 't', operatorId: 'o', operatorName: 'O', shiftId: 's',
      currency: 'TND', eventTimeDevice: new Date('2026-06-04T00:00:00.000Z'), businessDate: '2026-06-04',
      cartItems: cart(), subtotalGross: '119.000', taxAmount: '19.000', total: '119.000',
      transactionDiscountAmount: '0', transactionDiscountReason: null, payments: [],
      consumptionMode: null, tableId: null, isTraining: false,
      seller: { name: 'S', taxNumber: '1234567AM000', countryCode: 'TN', street: '1 rue', city: 'Tunis', postalCode: '1000' },
      approvalReferences: [],
    });
    const s = sale.line_items[0]!;
    const a = lines[0]!;
    expect([a.unitPrice, a.lineSubtotal, a.lineVat, a.vatRate, a.quantity])
      .toEqual([s.unit_price, s.line_subtotal, s.line_vat, s.vat_rate, s.quantity]);
  });

  it('builds vat breakdown grouped by (rate, taxCategoryCode)', () => {
    const { vatBreakdown } = buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: null });
    expect(vatBreakdown).toEqual([
      { rate: '19.00', taxCategoryCode: '', netAmount: '100.000', vatAmount: '19.000', grossAmount: '119.000' },
    ]);
  });

  it('requires a line discount reason when a line discount is present', () => {
    const items = cart();
    (items[0] as unknown as { discount_amount: string }).discount_amount = '5.000';
    expect(() => buildAccountChargeCart({ cartItems: items, currency: 'TND', transactionDiscount: null }))
      .toThrow(/discount reason/i);
  });

  it('returns correct aggregate subtotal (net), vatTotal, total, transactionDiscountAmount with no discount', () => {
    const result = buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: null });
    expect(result.subtotal).toBe('100.000');
    expect(result.vatTotal).toBe('19.000');
    expect(result.total).toBe('119.000');
    expect(result.transactionDiscountAmount).toBe('0.000');
  });

  it('applies a fixed transaction discount off the gross total and satisfies subtotal+vatTotal==total+discount invariant', () => {
    const discount: import('@/stores/cartStore').CartTransactionDiscount = {
      type: 'fixed',
      value: '19.000',
      reason: 'promo',
    };
    const result = buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: discount });
    expect(result.subtotal).toBe('100.000');
    expect(result.vatTotal).toBe('19.000');
    expect(result.total).toBe('100.000');
    expect(result.transactionDiscountAmount).toBe('19.000');
    // Invariant: subtotal + vatTotal === total + transactionDiscountAmount (119.000 === 119.000)
    // Uses bc* string helpers — no JS float arithmetic (no-JS-float rule).
    const lhs = bcadd(result.subtotal, result.vatTotal);
    const rhs = bcadd(result.total, result.transactionDiscountAmount);
    expect(bccomp(lhs, rhs)).toBe(0);
  });

  it('throws when a transaction discount amount is present but reason is missing', () => {
    const discount: import('@/stores/cartStore').CartTransactionDiscount = {
      type: 'fixed',
      value: '19.000',
      reason: null as unknown as string,
    };
    expect(() => buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: discount }))
      .toThrow(/transaction discount reason/i);
  });

  it('sorts vatBreakdown by `rate|taxCategoryCode` — deterministic order for two-rate cart', () => {
    const twoRateCart: CartItem[] = [
      {
        id: 'line-high',
        product: { id: 'prod-high', name: 'HighVAT', sku: 'H1', price: '119.000' },
        quantity: 1,
        unit_price: '119.000',
        tax_rate: '19.00',
        line_total: '119.000',
        tax_amount: '19.000',
      } as unknown as CartItem,
      {
        id: 'line-low',
        product: { id: 'prod-low', name: 'LowVAT', sku: 'L1', price: '107.000' },
        quantity: 1,
        unit_price: '107.000',
        tax_rate: '07.00',
        line_total: '107.000',
        tax_amount: '7.000',
      } as unknown as CartItem,
    ];
    const { vatBreakdown } = buildAccountChargeCart({ cartItems: twoRateCart, currency: 'TND', transactionDiscount: null });
    expect(vatBreakdown).toHaveLength(2);
    // After bcformat the rates become '19.00' and '7.00'.
    // Lexicographic localeCompare: '19.00|' < '7.00|' because '1' < '7',
    // so the 19% group sorts before the 7% group — matching the SaleReceiptPayload canonical path.
    // Both mapper paths must agree on this order because vat_breakdown position is part of canonical bytes.
    expect(vatBreakdown[0]!.rate).toBe('19.00');
    expect(vatBreakdown[1]!.rate).toBe('7.00');
    // Verify sort key order is deterministic (stable localeCompare).
    const sortedKeys = vatBreakdown.map((b) => `${b.rate}|${b.taxCategoryCode}`);
    expect(sortedKeys).toEqual([...sortedKeys].sort((a, b) => a.localeCompare(b)));
  });
});
