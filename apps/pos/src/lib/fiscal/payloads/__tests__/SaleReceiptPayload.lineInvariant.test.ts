import { describe, expect, it } from 'vitest';
import {
  buildSaleReceiptPayload,
  LineArithmeticInvariantError,
  type BuildSaleReceiptPayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import type { CartItem } from '@/types/cart';

/**
 * Device-side line-arithmetic invariant.
 *
 * The device authors the canonical SALE_RECEIPT and hashes it; the server
 * stores the bytes verbatim and verifies by re-hashing — it does NOT recompute
 * the arithmetic. So an internally-inconsistent line (e.g. a modifier
 * `price_adjustment` not folded into `unit_price`) would hash + verify fine yet
 * be arithmetically wrong. These tests pin the guarantee that such a line is
 * rejected BEFORE the payload is finalized/signed.
 *
 * Tax model is INCLUSIVE (computeTaxAmount extracts tax from a tax-inclusive
 * line_total). The canonical line carries:
 *   - line_total (gross)            == unit_price * quantity - line_discount_amount  (Big.RM half-up @ scale)
 *   - line_subtotal (net)           == line_total - tax_amount
 *   - line_vat                      == tax_amount
 * so line_subtotal + line_vat must equal line_total at scale.
 */

function makeSeller(): BuildSaleReceiptPayloadInput['seller'] {
  return {
    name: 'Test Cafe',
    taxNumber: '1234567A',
    countryCode: 'FR',
    street: '1 Rue de Test',
    city: 'Paris',
    postalCode: '75001',
  };
}

function makeBaseInput(
  cartItems: CartItem[],
  overrides: Partial<BuildSaleReceiptPayloadInput> = {},
): BuildSaleReceiptPayloadInput {
  return {
    receiptId: '11111111-1111-1111-1111-111111111111',
    terminalId: 'terminal-1',
    operatorId: 'op-1',
    operatorName: 'Alice',
    shiftId: 'shift-1',
    currency: 'EUR',
    eventTimeDevice: new Date('2026-05-29T10:00:00.000Z'),
    businessDate: '2026-05-29',
    cartItems,
    subtotalGross: '10.00',
    taxAmount: '0.00',
    total: '10.00',
    transactionDiscountAmount: '0.00',
    transactionDiscountReason: null,
    payments: [{ methodCode: 'CASH', amount: '10.00' }],
    isTraining: false,
    seller: makeSeller(),
    ...overrides,
  };
}

function makeLine(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 'cart-1',
    product: { id: 'prod-1', name: 'Espresso', sku: 'ESP', price: '10.00' },
    quantity: 1,
    unit_price: '10.00',
    line_total: '10.00',
    tax_rate: '0',
    tax_amount: '0.00',
    ...overrides,
  };
}

describe('SaleReceiptPayload line-arithmetic invariant', () => {
  it('(a) accepts a well-formed line (gross + net/tax decomposition consistent)', () => {
    // 20% inclusive VAT on 12.00 gross: net = 12.00 / 1.20 = 10.00, tax = 2.00.
    const line = makeLine({
      unit_price: '6.00',
      quantity: 2,
      line_total: '12.00',
      tax_rate: '20',
      tax_amount: '2.00',
    });

    expect(() =>
      buildSaleReceiptPayload(makeBaseInput([line], { subtotalGross: '12.00', taxAmount: '2.00', total: '12.00' })),
    ).not.toThrow();
  });

  it('(b) throws when line_total != unit_price * quantity - discount', () => {
    // unit_price 6.00 * qty 2 = 12.00, no discount, but line_total claims 11.00.
    const line = makeLine({
      unit_price: '6.00',
      quantity: 2,
      line_total: '11.00',
      tax_rate: '0',
      tax_amount: '0.00',
    });

    expect(() =>
      buildSaleReceiptPayload(makeBaseInput([line], { subtotalGross: '11.00', total: '11.00' })),
    ).toThrow(LineArithmeticInvariantError);
  });

  it('(c) accepts a modifier line when the adjustment IS folded into unit_price', () => {
    // base 8.00 + modifier +2.00 = unit_price 10.00; qty 1 => line_total 10.00.
    const line = makeLine({
      unit_price: '10.00',
      quantity: 1,
      line_total: '10.00',
      tax_rate: '0',
      tax_amount: '0.00',
      product: {
        id: 'prod-1',
        name: 'Latte',
        sku: 'LAT',
        price: '10.00',
        selectedModifiers: [
          {
            modifier_id: 'mod-1',
            modifier_group_id: 'grp-1',
            name: 'Extra shot',
            group_name: 'Extras',
            price_adjustment: '2.00',
          },
        ],
      },
    });

    expect(() => buildSaleReceiptPayload(makeBaseInput([line]))).not.toThrow();
  });

  it('(c) throws when a modifier adjustment is NOT folded into unit_price', () => {
    // unit_price still 8.00 (modifier +2.00 forgotten) but line_total computed
    // as if the modifier were applied (10.00). 8.00 * 1 != 10.00.
    const line = makeLine({
      unit_price: '8.00',
      quantity: 1,
      line_total: '10.00',
      tax_rate: '0',
      tax_amount: '0.00',
      product: {
        id: 'prod-1',
        name: 'Latte',
        sku: 'LAT',
        price: '8.00',
        selectedModifiers: [
          {
            modifier_id: 'mod-1',
            modifier_group_id: 'grp-1',
            name: 'Extra shot',
            group_name: 'Extras',
            price_adjustment: '2.00',
          },
        ],
      },
    });

    expect(() =>
      buildSaleReceiptPayload(makeBaseInput([line], { subtotalGross: '10.00', total: '10.00' })),
    ).toThrow(LineArithmeticInvariantError);
  });

  it('(c) gross invariant honors line_discount_amount', () => {
    // unit_price 6.00 * qty 2 = 12.00, minus 2.00 discount => line_total 10.00.
    const line = makeLine({
      unit_price: '6.00',
      quantity: 2,
      line_total: '10.00',
      tax_rate: '0',
      tax_amount: '0.00',
      discount_amount: '2.00',
      discount_reason: 'Loyalty',
    });

    expect(() => buildSaleReceiptPayload(makeBaseInput([line]))).not.toThrow();
  });

  it('(d) throws when net + tax decomposition does not equal line_total', () => {
    // Gross arithmetic is fine (6.00 * 2 = 12.00), but tax_amount (3.00) makes
    // net = 9.00, and 9.00 + 3.00 == 12.00 is consistent — so to break ONLY the
    // decomposition we keep gross valid and force tax_amount that, combined with
    // the derived subtotal, no longer reconstitutes line_total. Since
    // line_subtotal is DERIVED as line_total - tax_amount, the decomposition is
    // structurally consistent for any tax_amount; the failure mode is a
    // tax_amount that itself is not representable at scale (more decimals than
    // the currency allows), which makes the rounded subtotal + rounded tax
    // drift from line_total.
    const line = makeLine({
      unit_price: '6.00',
      quantity: 2,
      line_total: '12.00',
      tax_rate: '20',
      // 1.005 rounds to 1.01 at scale 2; subtotal = 12.00 - 1.005 -> rounds to
      // 10.99 (half-up) but actually 10.995 -> 11.00; 11.00 + 1.01 = 12.01 != 12.00.
      tax_amount: '1.005',
    });

    expect(() =>
      buildSaleReceiptPayload(makeBaseInput([line], { subtotalGross: '12.00', taxAmount: '1.01', total: '12.00' })),
    ).toThrow(LineArithmeticInvariantError);
  });
});
