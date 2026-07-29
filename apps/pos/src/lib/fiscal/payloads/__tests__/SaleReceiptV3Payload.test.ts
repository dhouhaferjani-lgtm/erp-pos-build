import { describe, expect, it } from 'vitest';
import { bcformat, bcsub, bcsum } from '@/lib/decimal';
import { SALE_RECEIPT_PAYLOAD_KEYS_V3 } from '@/lib/fiscal/FiscalEventEngine';
import { SaleReceiptAggregateInvariantError } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import type { BuildSaleReceiptPayloadInput } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import {
  assertSaleReceiptAggregatesV3,
  buildSaleReceiptV3Payload,
  V3_DENOMINATION_CAP_BY_SCALE,
  type SaleReceiptV3PayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptV3Payload';
import {
  computeRoundingAdjustment,
  DENOMINATION_CAP_BY_SCALE,
  roundCashTotal,
} from '@/lib/payment/cashRounding';
import type { CartItem } from '@/types/cart';

/**
 * SaleReceiptV3 (cash rounding, spec 2026-07-27 §4.4) — the `event_version = 3`
 * canonical SALE_RECEIPT.
 *
 * Fixtures are the V2 fixtures (`SaleReceiptV2Payload.test.ts:19-67`) switched
 * to TND (scale 3), which is the currency the rounding feature exists for.
 *
 * `makeInput` DERIVES `subtotalGross` / `total` from the cart lines rather than
 * hardcoding them: V1's aggregate assert requires `subtotalGross == total +
 * discount`, and V3 feeds V1 the EXACT total, so a fixture with a fixed
 * `subtotalGross` cannot serve two different exact totals.
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

function makeCartItem(lineTotal = '10.000'): CartItem {
  return {
    id: 'line-1',
    product: {
      id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      name: 'T-Shirt',
      sku: 'TSHIRT',
      price: lineTotal,
    },
    quantity: 1,
    unit_price: lineTotal,
    line_total: lineTotal,
    tax_rate: '0',
    tax_amount: '0.000',
  };
}

function makeInput(cartItems: CartItem[]): BuildSaleReceiptPayloadInput {
  const gross = bcsum(cartItems.map((item) => item.line_total), 3);

  return {
    receiptId: '11111111-1111-1111-1111-111111111111',
    terminalId: 'terminal-1',
    operatorId: 'op-1',
    operatorName: 'Alice',
    shiftId: 'shift-1',
    currency: 'TND',
    eventTimeDevice: new Date('2026-06-11T10:00:00.000Z'),
    businessDate: '2026-06-11',
    cartItems,
    subtotalGross: gross,
    taxAmount: '0.000',
    total: gross,
    transactionDiscountAmount: '0.000',
    transactionDiscountReason: null,
    payments: [{ methodCode: 'CASH', amount: gross }],
    isTraining: false,
    seller: makeSeller(),
  };
}

const NO_ROUNDING = {
  exactTotal: '10.000',
  roundedTotal: '10.000',
  adjustment: '0.000',
  denomination: '0.000',
};

describe('buildSaleReceiptV3Payload — normative build order', () => {
  it('signs the ROUNDED total and both sibling fields', () => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem('9.997')]), {
      exactTotal: '9.997',
      roundedTotal: '10.000',
      adjustment: '0.003',
      denomination: '0.050',
    });

    expect(payload.total).toBe('10.000');
    expect(payload.cash_rounding_adjustment).toBe('0.003');
    expect(payload.cash_rounding_denomination).toBe('0.050');
    // subtotal + vat == (total - adj) + discount
    expect(payload.subtotal).toBe('9.997');
  });

  it('signs a NEGATIVE adjustment when the exact total rounded down', () => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem('10.020')]), {
      exactTotal: '10.020',
      roundedTotal: '10.000',
      adjustment: '-0.020',
      denomination: '0.050',
    });

    expect(payload.total).toBe('10.000');
    expect(payload.cash_rounding_adjustment).toBe('-0.020');
    expect(payload.subtotal).toBe('10.020');
  });

  it('carries canonical zeros when rounding did not apply', () => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem()]), NO_ROUNDING);
    expect(payload.total).toBe('10.000');
    expect(payload.cash_rounding_adjustment).toBe('0.000');
    expect(payload.cash_rounding_denomination).toBe('0.000');
  });

  it('keeps every V2 key and shape (strict superset)', () => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem()]), NO_ROUNDING);
    expect(payload.line_items[0]).toHaveProperty('variant_id');
    expect(Object.keys(payload).sort()).toEqual([...SALE_RECEIPT_PAYLOAD_KEYS_V3].sort());
  });
});

describe('buildSaleReceiptV3Payload — canonical zero is never signed', () => {
  // big.js does NOT normalize every negative zero: `new Big('-0.0004')
  // .toFixed(3)` is the STRING '-0.000', which the server rejects as
  // `payload_money_negative_zero`. The builder must canonicalize.
  it.each([
    ['-0', '0.000'],
    ['-0.000', '0.000'],
    ['-0.0004', '0.000'],
    ['-0.00000001', '0.000'],
  ])('normalizes an adjustment of %s to %s', (adjustment, expected) => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem()]), {
      ...NO_ROUNDING,
      adjustment,
    });

    expect(payload.cash_rounding_adjustment).toBe(expected);
    expect(payload.cash_rounding_adjustment.startsWith('-')).toBe(false);
  });

  it('normalizes a negative-zero denomination', () => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem()]), {
      ...NO_ROUNDING,
      denomination: '-0.0001',
    });

    expect(payload.cash_rounding_denomination).toBe('0.000');
  });
});

describe('assertSaleReceiptAggregatesV3 — binds in normative order', () => {
  function payloadWith(overrides: Partial<SaleReceiptV3PayloadInput>): SaleReceiptV3PayloadInput {
    return {
      ...buildSaleReceiptV3Payload(makeInput([makeCartItem()]), NO_ROUNDING),
      ...overrides,
    } as SaleReceiptV3PayloadInput;
  }

  it('rejects a payload whose total was NOT replaced (build-order regression)', () => {
    // adj is non-zero but total is still the exact total: identity (1) breaks.
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '9.997', subtotal: '9.997', vat_total: '0.000',
        cash_rounding_adjustment: '0.003', cash_rounding_denomination: '0.050' }),
      3,
    )).toThrow(SaleReceiptAggregateInvariantError);
  });

  it('rejects adj != 0 with a zero denomination WITHOUT a division error', () => {
    let thrown: unknown;
    try {
      assertSaleReceiptAggregatesV3(
        payloadWith({ total: '10.000', subtotal: '9.997', vat_total: '0.000',
          cash_rounding_adjustment: '0.003', cash_rounding_denomination: '0.000' }),
        3,
      );
    } catch (error) {
      thrown = error;
    }
    expect(thrown).toBeInstanceOf(SaleReceiptAggregateInvariantError);
    expect(String(thrown)).not.toContain('Modulo by zero');
  });

  it('rejects |adj| > denomination / 2', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.000', subtotal: '9.970', vat_total: '0.000',
        cash_rounding_adjustment: '0.030', cash_rounding_denomination: '0.050' }),
      3,
    )).toThrow(/denomination/);
  });

  it('accepts |adj| exactly equal to denomination / 2 (the tie)', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.000', subtotal: '9.975', vat_total: '0.000',
        cash_rounding_adjustment: '0.025', cash_rounding_denomination: '0.050' }),
      3,
    )).not.toThrow();
  });

  it('rejects a total that is not a multiple of the denomination', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.010', subtotal: '10.000', vat_total: '0.000',
        cash_rounding_adjustment: '0.010', cash_rounding_denomination: '0.050' }),
      3,
    )).toThrow();
  });

  it('rejects a suppression payload (total 5.000, adj -95.000)', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '5.000', subtotal: '100.000', vat_total: '0.000',
        cash_rounding_adjustment: '-95.000', cash_rounding_denomination: '0.050' }),
      3,
    )).toThrow(SaleReceiptAggregateInvariantError);
  });

  it('rejects a denomination beyond the static cap', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.000', subtotal: '8.000', vat_total: '0.000',
        cash_rounding_adjustment: '2.000', cash_rounding_denomination: '5.000' }),
      3,
    )).toThrow();
  });

  it('rejects an unlisted currency scale outright', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.0000', subtotal: '9.9750', vat_total: '0.0000',
        cash_rounding_adjustment: '0.0250', cash_rounding_denomination: '0.0500' }),
      4,
    )).toThrow(/unsupported scale/);
  });

  it('rejects a NEGATIVE denomination even when the adjustment is canonical zero', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ cash_rounding_adjustment: '0.000', cash_rounding_denomination: '-0.050' }),
      3,
    )).toThrow(SaleReceiptAggregateInvariantError);
  });

  it('rejects an over-cap denomination even when the adjustment is canonical zero (fail-closed-at-signing regression)', () => {
    // The server (FiscalPayloadConstraintValidator.php:2839) runs
    // CashRoundingCaps::isWithinCap() UNCONDITIONALLY — outside the
    // `adjustment != 0` block — so a payload carrying `adjustment '0.000'`
    // with an over-cap denomination must never leave the device signed.
    // (The canonical-zero/zero pair itself is already pinned by "carries
    // canonical zeros when rounding did not apply" above, which builds AND
    // asserts NO_ROUNDING — denomination '0.000' must keep building cleanly.)
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.000', subtotal: '10.000', vat_total: '0.000',
        cash_rounding_adjustment: '0.000', cash_rounding_denomination: '2.000' }),
      3,
    )).toThrow(SaleReceiptAggregateInvariantError);
  });
});

describe('round trip: cashRounding output always satisfies the V3 binds', () => {
  // The catastrophic failure mode for this task is a payload the device
  // signs but the server quarantines. `cashRounding` is the ONLY producer of
  // the three rounding values, so its full output range must build cleanly.
  const denominations = ['0.005', '0.010', '0.050', '0.100', '0.500', '1.000'];
  const exactTotals = [
    '0.000', '0.001', '0.004', '0.005', '0.006', '0.024', '0.025', '0.026',
    '9.997', '10.000', '10.020', '10.025', '12.345', '99.999', '1234.567',
  ];

  it.each(denominations)('denomination %s rounds every fixture without tripping a bind', (denomination) => {
    for (const exactTotal of exactTotals) {
      const roundedTotal = roundCashTotal(exactTotal, denomination, 3);
      const adjustment = computeRoundingAdjustment(exactTotal, roundedTotal, 3);

      const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem(exactTotal)]), {
        exactTotal,
        roundedTotal,
        adjustment,
        denomination,
      });

      expect(payload.total).toBe(roundedTotal);
      expect(payload.cash_rounding_adjustment.startsWith('-0.000')).toBe(false);
      // The server's identity: subtotal + vat == (total - adj) + discount.
      expect(bcsub(payload.total, payload.cash_rounding_adjustment, 3)).toBe(
        bcformat(exactTotal, 3),
      );
    }
  });
});

describe('V3 denomination caps mirror the checkout module', () => {
  // The payload assert deliberately owns its OWN copy of the caps (a signed-
  // bytes concern must not be able to change because a checkout module did),
  // but the two must never silently diverge.
  it('V3_DENOMINATION_CAP_BY_SCALE equals cashRounding DENOMINATION_CAP_BY_SCALE', () => {
    expect(V3_DENOMINATION_CAP_BY_SCALE).toEqual(DENOMINATION_CAP_BY_SCALE);
  });
});
