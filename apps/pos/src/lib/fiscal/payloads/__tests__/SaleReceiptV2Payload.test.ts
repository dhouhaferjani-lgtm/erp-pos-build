import { describe, expect, it } from 'vitest';
import {
  buildSaleReceiptPayload,
  SaleReceiptPayloadInputError,
  type BuildSaleReceiptPayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { buildSaleReceiptV2Payload } from '@/lib/fiscal/payloads/SaleReceiptV2Payload';
import type { CartItem } from '@/types/cart';

/**
 * SaleReceiptV2 (M4) — variant line fidelity in the signed SALE_RECEIPT.
 *
 * V2 is a strict superset of V1: every line gains variant_id / variant_name /
 * variant_sku (null for non-variant lines). The V1 builder is never mutated;
 * V2 wraps it, so every V1 invariant still applies.
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

describe('buildSaleReceiptV2Payload', () => {
  it('folds the variant identity into the signed line for a variant sale', () => {
    const item = makeCartItem({
      variant_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      variant_name: 'T-Shirt — Red / L',
      variant_sku: 'TSHIRT-RED-L',
    });

    const payload = buildSaleReceiptV2Payload(makeInput([item]));

    const line = payload.line_items[0]!;
    expect(line.variant_id).toBe('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    expect(line.variant_name).toBe('T-Shirt — Red / L');
    expect(line.variant_sku).toBe('TSHIRT-RED-L');
    // Parent identity is preserved — V2 never repurposes the V1 fields.
    expect(line.name).toBe('T-Shirt');
    expect(line.sku).toBe('TSHIRT');
    expect(line.product_id).toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    // unit_price stays the variant price the cart line carries.
    expect(line.unit_price).toBe('10.00');
  });

  it('writes explicit nulls for non-variant lines (strict superset of V1)', () => {
    const payload = buildSaleReceiptV2Payload(makeInput([makeCartItem()]));

    const line = payload.line_items[0]!;
    expect(line.variant_id).toBeNull();
    expect(line.variant_name).toBeNull();
    expect(line.variant_sku).toBeNull();
  });

  it('is byte-equivalent to V1 plus the three variant keys for non-variant sales', () => {
    const input = makeInput([makeCartItem()]);

    const v1 = buildSaleReceiptPayload(input);
    const v2 = buildSaleReceiptV2Payload(input);

    const v2Line = v2.line_items[0]!;
    const { variant_id, variant_name, variant_sku, ...v2LineRest } = v2Line;
    expect(variant_id).toBeNull();
    expect(variant_name).toBeNull();
    expect(variant_sku).toBeNull();
    expect(v2LineRest).toEqual(v1.line_items[0]);

    const { line_items: v1Lines, ...v1Rest } = v1;
    const { line_items: v2Lines, ...v2Rest } = v2;
    expect(v2Rest).toEqual(v1Rest);
    expect(v2Lines).toHaveLength(v1Lines.length);
  });

  it('coerces empty-string variant fields from a stale catalog sync to null (never signs "")', () => {
    const item = makeCartItem({
      variant_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      variant_name: '',
      variant_sku: '',
    });

    const payload = buildSaleReceiptV2Payload(makeInput([item]));

    const line = payload.line_items[0]!;
    expect(line.variant_id).toBe('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    expect(line.variant_name).toBeNull();
    expect(line.variant_sku).toBeNull();
  });

  it('rejects an orphan variant identity (sku/name without variant_id)', () => {
    const item = makeCartItem({ variant_sku: 'TSHIRT-RED-L' });

    expect(() => buildSaleReceiptV2Payload(makeInput([item]))).toThrow(
      SaleReceiptPayloadInputError,
    );
  });

  it('still runs every V1 invariant (inconsistent line is rejected before signing)', () => {
    const item = makeCartItem();
    item.line_total = '99.00'; // breaks unit_price × qty − discount

    expect(() => buildSaleReceiptV2Payload(makeInput([item]))).toThrow(
      /Line arithmetic invariant violated/,
    );
  });

  it('maps variants positionally across a mixed cart', () => {
    const variantItem = makeCartItem({
      variant_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
      variant_name: 'T-Shirt — Blue / M',
      variant_sku: 'TSHIRT-BLU-M',
    });
    const plainItem = makeCartItem();
    const input = {
      ...makeInput([plainItem, variantItem]),
      subtotalGross: '20.00',
      total: '20.00',
      payments: [{ methodCode: 'CASH', amount: '20.00' }],
    };

    const payload = buildSaleReceiptV2Payload(input);

    expect(payload.line_items[0]!.variant_id).toBeNull();
    expect(payload.line_items[1]!.variant_id).toBe('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
    expect(payload.line_items[1]!.variant_sku).toBe('TSHIRT-BLU-M');
  });
});
