/**
 * SaleReceiptV5 (D-1 post-remise VAT base, owner ruling 2026-08-25) — the
 * `event_version = 5` canonical SALE_RECEIPT.
 *
 * What v5 changes: `subtotal` / `vat_total` / `vat_breakdown[]` are sealed NET
 * of the ticket-level remise, ventilated pro-rata per rate, and each breakdown
 * row carries its own `discount_allocated`. The aggregate identity flips with
 * it — v3 adds the discount BACK to reach the taxed base, v5 does not.
 */

import { describe, expect, it } from 'vitest';

import { bccomp, bcsum } from '@/lib/decimal';
import { SALE_RECEIPT_PAYLOAD_KEYS_V5, SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5 } from '@/lib/fiscal/FiscalEventEngine';
import { SaleReceiptAggregateInvariantError } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import type { BuildSaleReceiptPayloadInput } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { buildSaleReceiptV3Payload } from '@/lib/fiscal/payloads/SaleReceiptV3Payload';
import {
  assertSaleReceiptAggregatesV5,
  buildSaleReceiptV5Payload,
  type SaleReceiptV5PayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptV5Payload';
import type { CartItem } from '@/types/cart';

function makeSeller(): BuildSaleReceiptPayloadInput['seller'] {
  return {
    name: 'Test Cafe',
    taxNumber: '1234567A',
    countryCode: 'TN',
    street: '1 Rue de Test',
    city: 'Tunis',
    postalCode: '1000',
  };
}

/** A TTC line: `lineTotal` is tax-INCLUSIVE (POS `unit_price` semantics). */
function line(id: string, lineTotal: string, rate: string, vat: string): CartItem {
  return {
    id,
    product: {
      id: `aaaaaaaa-aaaa-4aaa-8aaa-${id.padStart(12, '0')}`,
      name: `Article ${id}`,
      sku: `SKU-${id}`,
      price: lineTotal,
    },
    quantity: 1,
    unit_price: lineTotal,
    line_total: lineTotal,
    tax_rate: rate,
    tax_amount: vat,
  };
}

function makeInput(
  cartItems: CartItem[],
  discount: string,
  reason: string | null,
): BuildSaleReceiptPayloadInput {
  const gross = bcsum(cartItems.map((i) => i.line_total), 3);
  const vat = bcsum(cartItems.map((i) => i.tax_amount), 3);
  const total = bcsum([gross, `-${discount}`], 3);

  return {
    receiptId: '11111111-1111-1111-1111-111111111111',
    terminalId: 'terminal-1',
    operatorId: 'op-1',
    operatorName: 'Alice',
    shiftId: 'shift-1',
    currency: 'TND',
    eventTimeDevice: new Date('2026-08-25T10:00:00.000Z'),
    businessDate: '2026-08-25',
    cartItems,
    subtotalGross: gross,
    taxAmount: vat,
    total,
    transactionDiscountAmount: discount,
    transactionDiscountReason: reason,
    payments: [{ methodCode: 'CASH', amount: total }],
    isTraining: false,
    seller: makeSeller(),
  };
}

const UNROUNDED = (total: string) => ({
  exactTotal: total,
  roundedTotal: total,
  adjustment: '0.000',
  denomination: '0.000',
});

/** 7 / 13 / 19 % + an exempt group; Σ gross = 640.000. */
function mixedRateCart(): CartItem[] {
  return [
    line('1', '238.000', '19', '38.000'),
    line('2', '226.000', '13', '26.000'),
    line('3', '107.000', '7', '7.000'),
    line('4', '69.000', '0', '0.000'),
  ];
}

describe('buildSaleReceiptV5Payload', () => {
  it('seals the 30-key v5 top-level set and 6-key vat_breakdown rows', () => {
    const cart = mixedRateCart();
    const payload = buildSaleReceiptV5Payload(
      makeInput(cart, '50.000', 'Geste commercial'),
      UNROUNDED('590.000'),
    );

    expect(Object.keys(payload).sort()).toEqual([...SALE_RECEIPT_PAYLOAD_KEYS_V5].sort());
    for (const row of payload.vat_breakdown) {
      expect(Object.keys(row).sort()).toEqual([...SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5].sort());
    }
  });

  it('seals the taxable base NET of the remise, ventilated pro-rata per rate', () => {
    const payload = buildSaleReceiptV5Payload(
      makeInput(mixedRateCart(), '50.000', 'Geste commercial'),
      UNROUNDED('590.000'),
    );

    expect(payload.vat_breakdown.map((r) => [
      r.rate, r.discount_allocated, r.net_amount, r.vat_amount, r.gross_amount,
    ])).toEqual([
      ['0.00', '5.391', '63.609', '0.000', '63.609'],
      ['13.00', '17.656', '184.375', '23.969', '208.344'],
      ['19.00', '18.594', '184.375', '35.031', '219.406'],
      ['7.00', '8.359', '92.188', '6.453', '98.641'],
    ]);
    expect(payload.subtotal).toBe('524.547');
    expect(payload.vat_total).toBe('65.453');
    expect(payload.total).toBe('590.000');
    expect(payload.transaction_discount_amount).toBe('50.000');
  });

  it('is strictly less VAT than the pre-D-1 v3 payload for the same cart', () => {
    const input = makeInput(mixedRateCart(), '50.000', 'Geste commercial');
    const v3 = buildSaleReceiptV3Payload(input, UNROUNDED('590.000'));
    const v5 = buildSaleReceiptV5Payload(input, UNROUNDED('590.000'));

    // The defect: v3 seals 71.000 of VAT on a 640.000 base the customer did
    // not pay; v5 seals 65.453 on the 590.000 they did.
    expect(v3.vat_total).toBe('71.000');
    expect(v5.vat_total).toBe('65.453');
    expect(bccomp(v5.vat_total, v3.vat_total)).toBeLessThan(0);
  });

  it('is byte-identical to v3 (minus discount_allocated) when there is no remise', () => {
    const input = makeInput(mixedRateCart(), '0.000', null);
    const v3 = buildSaleReceiptV3Payload(input, UNROUNDED('640.000'));
    const v5 = buildSaleReceiptV5Payload(input, UNROUNDED('640.000'));

    expect(v5.subtotal).toBe(v3.subtotal);
    expect(v5.vat_total).toBe(v3.vat_total);
    expect(v5.vat_breakdown.map(({ discount_allocated: _d, ...rest }) => rest))
      .toEqual(v3.vat_breakdown.map((r) => ({ ...r })));
    for (const row of v5.vat_breakdown) {
      expect(row.discount_allocated).toBe('0.000');
    }
  });

  it('seals a 100 %-comp as zero base / zero VAT with NO tender row', () => {
    const cart = mixedRateCart();
    const input = { ...makeInput(cart, '640.000', 'Comp direction'), payments: [] };
    const payload = buildSaleReceiptV5Payload(input, UNROUNDED('0.000'));

    expect(payload.total).toBe('0.000');
    expect(payload.subtotal).toBe('0.000');
    expect(payload.vat_total).toBe('0.000');
    expect(payload.payments).toEqual([]);
    expect(bcsum(payload.vat_breakdown.map((r) => r.discount_allocated), 3)).toBe('640.000');
  });

  it('refuses a payload whose Σ discount_allocated drifts from the declared discount', () => {
    const payload = buildSaleReceiptV5Payload(
      makeInput(mixedRateCart(), '50.000', 'Geste commercial'),
      UNROUNDED('590.000'),
    );
    const tampered: SaleReceiptV5PayloadInput = {
      ...payload,
      vat_breakdown: payload.vat_breakdown.map((r, i) =>
        i === 0 ? { ...r, discount_allocated: '5.392' } : r),
    };

    expect(() => assertSaleReceiptAggregatesV5(tampered, 3))
      .toThrow(SaleReceiptAggregateInvariantError);
  });

  it('refuses a remise with no reason', () => {
    expect(() => buildSaleReceiptV5Payload(
      makeInput(mixedRateCart(), '50.000', null),
      UNROUNDED('590.000'),
    )).toThrow();
  });

  it('refuses when the cart aggregates disagree with the line roll-up', () => {
    const input = { ...makeInput(mixedRateCart(), '0.000', null), taxAmount: '70.000' };

    expect(() => buildSaleReceiptV5Payload(input, UNROUNDED('640.000')))
      .toThrow(SaleReceiptAggregateInvariantError);
  });
});
