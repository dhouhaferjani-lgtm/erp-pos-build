import { describe, expect, it } from 'vitest';
import type { CartItem } from '@/types/cart';
import {
  mapRefundItemsToServerLines,
  type ServerReceiptLine,
} from '../refundLineMapping';

function refundItem(overrides: Partial<CartItem> & { quantity: number }): CartItem {
  return {
    id: 'return-item-1',
    product: {
      id: 'prod-1',
      name: 'Widget A',
      sku: 'PROD-001',
      price: '10.0000',
      ...(overrides.product ?? {}),
    },
    unit_price: '10.0000',
    line_total: '-10.0000',
    tax_rate: '19.00',
    tax_amount: '-1.9000',
    kind: 'return',
    ...overrides,
  };
}

function serverLine(overrides: Partial<ServerReceiptLine> = {}): ServerReceiptLine {
  return {
    id: 'line-1',
    product_id: 'prod-1',
    composite_item_id: null,
    product_code: 'PROD-001',
    product_name: 'Widget A',
    quantity: '5.0000',
    unit_price: '10.0000',
    returned_quantity: '0.0000',
    ...overrides,
  };
}

describe('mapRefundItemsToServerLines', () => {
  it('maps a single refund item onto its matching server line', () => {
    const result = mapRefundItemsToServerLines(
      [refundItem({ quantity: -2 })],
      [serverLine()],
    );

    expect(result).toEqual({
      ok: true,
      lines: [{ line_id: 'line-1', quantity: '2.0000' }],
    });
  });

  it('consumes duplicate-product lines greedily in server order', () => {
    // Receipt has the SAME product on two lines (2 + 3). Refund of 4 must
    // take 2 from the first line then 2 from the second.
    const result = mapRefundItemsToServerLines(
      [refundItem({ quantity: -4 })],
      [
        serverLine({ id: 'line-1', quantity: '2.0000' }),
        serverLine({ id: 'line-2', quantity: '3.0000' }),
      ],
    );

    expect(result).toEqual({
      ok: true,
      lines: [
        { line_id: 'line-1', quantity: '2.0000' },
        { line_id: 'line-2', quantity: '2.0000' },
      ],
    });
  });

  it('handles fractional scale-4 quantities without float drift', () => {
    const result = mapRefundItemsToServerLines(
      [refundItem({ quantity: -1.5 })],
      [serverLine({ quantity: '2.5000' })],
    );

    expect(result).toEqual({
      ok: true,
      lines: [{ line_id: 'line-1', quantity: '1.5000' }],
    });
  });

  it('skips quantity already returned (returned_quantity exhaustion)', () => {
    // line-1 fully returned already; line-2 partially returned (1 of 3 left).
    const result = mapRefundItemsToServerLines(
      [refundItem({ quantity: -1 })],
      [
        serverLine({ id: 'line-1', quantity: '2.0000', returned_quantity: '2.0000' }),
        serverLine({ id: 'line-2', quantity: '3.0000', returned_quantity: '2.0000' }),
      ],
    );

    expect(result).toEqual({
      ok: true,
      lines: [{ line_id: 'line-2', quantity: '1.0000' }],
    });
  });

  it('fails when the requested quantity exceeds the remaining returnable quantity', () => {
    const result = mapRefundItemsToServerLines(
      [refundItem({ quantity: -3 })],
      [serverLine({ quantity: '5.0000', returned_quantity: '3.0000' })],
    );

    expect(result).toEqual({
      ok: false,
      reason: 'INSUFFICIENT_RETURNABLE_QUANTITY',
      itemId: 'return-item-1',
    });
  });

  it('fails when no server line matches the item identity', () => {
    // Same product id + code but DIFFERENT unit price — not the same identity.
    const result = mapRefundItemsToServerLines(
      [refundItem({ quantity: -1, unit_price: '12.0000' })],
      [serverLine()],
    );

    expect(result).toEqual({
      ok: false,
      reason: 'NO_MATCHING_LINE',
      itemId: 'return-item-1',
    });
  });

  it('does not match on product_code alone when product_id differs', () => {
    const result = mapRefundItemsToServerLines(
      [refundItem({ quantity: -1 })],
      [serverLine({ product_id: 'prod-OTHER' })],
    );

    expect(result).toEqual({
      ok: false,
      reason: 'NO_MATCHING_LINE',
      itemId: 'return-item-1',
    });
  });

  it('compares unit prices numerically, not byte-wise', () => {
    // Cart carries '10.00', server carries '10.0000' — same value.
    const result = mapRefundItemsToServerLines(
      [refundItem({ quantity: -1, unit_price: '10.00' })],
      [serverLine()],
    );

    expect(result).toEqual({
      ok: true,
      lines: [{ line_id: 'line-1', quantity: '1.0000' }],
    });
  });

  it('tracks consumption across multiple refund items targeting the same lines', () => {
    // Two refund items for the same product: 2 + 2 against a 3-qty line and a
    // 2-qty line. The second item must NOT re-consume what the first took.
    const items = [
      refundItem({ id: 'item-1', quantity: -2 }),
      refundItem({ id: 'item-2', quantity: -2 }),
    ];
    const result = mapRefundItemsToServerLines(items, [
      serverLine({ id: 'line-1', quantity: '3.0000' }),
      serverLine({ id: 'line-2', quantity: '2.0000' }),
    ]);

    expect(result).toEqual({
      ok: true,
      lines: [
        // item-1 takes 2 from line-1; item-2 takes the remaining 1 from
        // line-1 + 1 from line-2; entries are merged per line_id.
        { line_id: 'line-1', quantity: '3.0000' },
        { line_id: 'line-2', quantity: '1.0000' },
      ],
    });
  });

  it('matches composite items via composite_item_id', () => {
    const result = mapRefundItemsToServerLines(
      [
        refundItem({
          quantity: -1,
          product: {
            id: 'combo-1',
            name: 'Breakfast Combo',
            sku: 'COMBO-001',
            price: '10.0000',
            sellableType: 'composite_item',
          },
        }),
      ],
      [
        serverLine({
          product_id: null,
          composite_item_id: 'combo-1',
          product_code: 'COMBO-001',
        }),
      ],
    );

    expect(result).toEqual({
      ok: true,
      lines: [{ line_id: 'line-1', quantity: '1.0000' }],
    });
  });

  it('matches identity-less lines (no product_id on either side) by code + price', () => {
    const result = mapRefundItemsToServerLines(
      [
        refundItem({
          quantity: -1,
          product: {
            // Synthetic id produced by hydrateFromReceipt for lines with no
            // product_id — never a server product uuid.
            id: '550e8400-e29b-41d4-a716-446655440000-line-0',
            name: 'Custom Item',
            sku: 'CUSTOM-01',
            price: '10.0000',
          },
        }),
      ],
      [
        serverLine({ product_id: null, composite_item_id: null, product_code: 'CUSTOM-01' }),
      ],
    );

    expect(result).toEqual({
      ok: true,
      lines: [{ line_id: 'line-1', quantity: '1.0000' }],
    });
  });

  it('ignores non-return cart items', () => {
    const saleItem = refundItem({ id: 'sale-1', quantity: 1, kind: 'sale' });
    const result = mapRefundItemsToServerLines([saleItem], [serverLine()]);

    expect(result).toEqual({ ok: true, lines: [] });
  });
});
