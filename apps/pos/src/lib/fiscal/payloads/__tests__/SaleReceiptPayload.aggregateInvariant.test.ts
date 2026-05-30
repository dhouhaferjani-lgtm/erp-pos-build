import { describe, expect, it } from 'vitest';
import {
  buildSaleReceiptPayload,
  SaleReceiptAggregateInvariantError,
  type BuildSaleReceiptPayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import type { CartItem } from '@/types/cart';

/**
 * Device-side ticket-AGGREGATE invariant (NF525 VAT-declaration integrity).
 *
 * NF525 secures the ticket aggregates — subtotal / vat_total / total /
 * vat_breakdown — not per-line arithmetic re-validation. The device authors and
 * signs these; the server stores the bytes verbatim and re-hashes, so an
 * internally-inconsistent aggregate would hash + verify yet be fiscally wrong.
 * These tests pin the guarantee that the device refuses to finalize a payload
 * whose own aggregates do not add up:
 *   1. subtotal + vat_total == total (+ transaction discount)
 *   2. Σ vat_breakdown.net_amount == subtotal
 *   3. Σ vat_breakdown.vat_amount == vat_total
 *   4. per group: gross_amount == net_amount + vat_amount
 *
 * The cart is tax-INCLUSIVE: line_total is gross, line_subtotal = line_total −
 * tax_amount (net), line_vat = tax_amount.
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
    subtotalGross: '12.00',
    taxAmount: '2.00',
    total: '12.00',
    transactionDiscountAmount: '0.00',
    transactionDiscountReason: null,
    payments: [{ methodCode: 'CASH', amount: '12.00' }],
    isTraining: false,
    seller: makeSeller(),
    ...overrides,
  };
}

/** A 20%-inclusive taxed line: gross 12.00, net 10.00, vat 2.00. */
function makeTaxedLine(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 'cart-1',
    product: { id: 'prod-1', name: 'Espresso', sku: 'ESP', price: '12.00' },
    quantity: 1,
    unit_price: '12.00',
    line_total: '12.00',
    tax_rate: '20',
    tax_amount: '2.00',
    ...overrides,
  };
}

describe('SaleReceiptPayload aggregate invariant', () => {
  it('passes on a real, internally-consistent taxed payload (inclusive unit_price)', () => {
    const payload = buildSaleReceiptPayload(makeBaseInput([makeTaxedLine()]));

    // Sanity: the canonical payload carries gross unit_price + net subtotal.
    expect(payload.line_items[0]?.unit_price).toBe('12.00');
    expect(payload.line_items[0]?.line_subtotal).toBe('10.00');
    expect(payload.subtotal).toBe('10.00');
    expect(payload.vat_total).toBe('2.00');
    expect(payload.total).toBe('12.00');
  });

  it('throws when subtotal + vat_total != total (tampered total)', () => {
    // Line is self-consistent (gross 12.00), so the line invariant passes; only
    // the ticket total is wrong (13.00 != subtotal 10.00 + vat_total 2.00).
    expect(() =>
      buildSaleReceiptPayload(
        makeBaseInput([makeTaxedLine()], {
          total: '13.00',
          payments: [{ methodCode: 'CASH', amount: '13.00' }],
        }),
      ),
    ).toThrow(SaleReceiptAggregateInvariantError);
  });

  it('throws when declared vat_total (taxAmount) does not match the line tax sum', () => {
    // taxAmount 3.00 makes vat_total 3.00, but the single line carries 2.00 vat,
    // so Σ vat_breakdown.vat_amount (2.00) != vat_total (3.00). Keep total
    // consistent with subtotal + vat_total so check #1 passes and #3 fires.
    expect(() =>
      buildSaleReceiptPayload(
        makeBaseInput([makeTaxedLine()], {
          taxAmount: '3.00',
          subtotalGross: '13.00',
          total: '13.00',
          payments: [{ methodCode: 'CASH', amount: '13.00' }],
        }),
      ),
    ).toThrow(SaleReceiptAggregateInvariantError);
  });

  it('passes on a multi-line, multi-rate consistent payload', () => {
    // Line A: 20% inclusive gross 12.00 (net 10.00, vat 2.00).
    // Line B: 0% gross 5.00 (net 5.00, vat 0.00).
    const lineA = makeTaxedLine();
    const lineB = makeTaxedLine({
      id: 'cart-2',
      product: { id: 'prod-2', name: 'Water', sku: 'H2O', price: '5.00' },
      unit_price: '5.00',
      line_total: '5.00',
      tax_rate: '0',
      tax_amount: '0.00',
    });

    expect(() =>
      buildSaleReceiptPayload(
        makeBaseInput([lineA, lineB], {
          subtotalGross: '17.00',
          taxAmount: '2.00',
          total: '17.00',
          payments: [{ methodCode: 'CASH', amount: '17.00' }],
        }),
      ),
    ).not.toThrow();
  });
});
