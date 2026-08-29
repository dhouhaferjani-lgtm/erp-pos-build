import { describe, expect, it } from 'vitest';
import {
  buildSaleReceiptPayload,
  type BuildSaleReceiptPayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { buildSaleReceiptV2Payload } from '@/lib/fiscal/payloads/SaleReceiptV2Payload';
import type { BuyerBlockInput } from '@/lib/fiscal/FiscalEventEngine';
import type { CartItem } from '@/types/cart';

/**
 * V1/V2 byte-immutability pin for the v3 rollout.
 *
 * Events are Immutable Forever: introducing `event_version = 3` must not move
 * a single byte of what the V1 or V2 builders produce. The fixture below is
 * copied VERBATIM from `SaleReceiptV2Payload.test.ts:19-67` — the fixture is
 * the pin, so it must not be "improved" either.
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

function makeCartItem(overrides: Partial<CartItem['product']> = {}): CartItem {
  return {
    id: 'line-1',
    product: {
      id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      name: 'T-Shirt',
      sku: 'TSHIRT',
      price: '10.00',
      ...overrides,
    },
    quantity: 1,
    unit_price: '10.00',
    line_total: '10.00',
    tax_rate: '0',
    tax_amount: '0.00',
  };
}

function makeInput(cartItems: CartItem[]): BuildSaleReceiptPayloadInput {
  return {
    receiptId: '11111111-1111-1111-1111-111111111111',
    terminalId: 'terminal-1',
    operatorId: 'op-1',
    operatorName: 'Alice',
    shiftId: 'shift-1',
    currency: 'EUR',
    eventTimeDevice: new Date('2026-06-11T10:00:00.000Z'),
    businessDate: '2026-06-11',
    cartItems,
    subtotalGross: '10.00',
    taxAmount: '0.00',
    total: '10.00',
    transactionDiscountAmount: '0.00',
    transactionDiscountReason: null,
    payments: [{ methodCode: 'CASH', amount: '10.00' }],
    isTraining: false,
    seller: makeSeller(),
  };
}

describe('V1/V2 byte stability under the v3 rollout', () => {
  it('V1 output is unchanged (snapshot pin)', () => {
    expect(JSON.stringify(buildSaleReceiptPayload(makeInput([makeCartItem()])))).toMatchSnapshot();
  });

  it('V2 output is unchanged (snapshot pin)', () => {
    expect(JSON.stringify(buildSaleReceiptV2Payload(makeInput([makeCartItem()])))).toMatchSnapshot();
  });

  it('neither builder emits a cash-rounding key', () => {
    const v1 = buildSaleReceiptPayload(makeInput([makeCartItem()]));
    const v2 = buildSaleReceiptV2Payload(makeInput([makeCartItem()]));
    expect(Object.keys(v1)).not.toContain('cash_rounding_adjustment');
    expect(Object.keys(v1)).not.toContain('cash_rounding_denomination');
    expect(Object.keys(v2)).not.toContain('cash_rounding_adjustment');
    expect(Object.keys(v2)).not.toContain('cash_rounding_denomination');
  });

  it('preserves the null-buyer bytes when buyer is omitted or explicitly null', () => {
    const omitted = JSON.stringify(buildSaleReceiptPayload(makeInput([makeCartItem()])));
    const explicit = JSON.stringify(buildSaleReceiptPayload({
      ...makeInput([makeCartItem()]),
      buyer: null,
    } as BuildSaleReceiptPayloadInput & { buyer: null }));

    expect(explicit).toBe(omitted);
  });

  it('seals the supplied buyer block without adding or dropping a key in V1 and V2', () => {
    const buyer: BuyerBlockInput = {
      address: null,
      codice_fiscale: null,
      contact_id: null,
      customer_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      name: 'Acme SARL',
      tax_number: 'FR12345678901',
    };
    const input = {
      ...makeInput([makeCartItem()]),
      buyer,
    } as BuildSaleReceiptPayloadInput & { buyer: BuyerBlockInput };

    expect(buildSaleReceiptPayload(input).buyer).toEqual(buyer);
    expect(buildSaleReceiptV2Payload(input).buyer).toEqual(buyer);
    expect(Object.keys(buildSaleReceiptPayload(input).buyer ?? {}).sort()).toEqual([
      'address',
      'codice_fiscale',
      'contact_id',
      'customer_id',
      'name',
      'tax_number',
    ]);
  });
});
