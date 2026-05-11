/**
 * C2 Day 1 — receiptToPayload wire-boundary unpack.
 *
 * After Day 1 a Menu-tenant POSProduct.id is the colon-delimited composite
 * `${sellable_id}_${menu_category_id}`. The cart-line creator at
 * `receiptService.createOfflineReceipt` writes `item.product.id` raw into
 * `offline_receipts.lines[].product_id` (or `composite_item_id` for the
 * composite-item case). Local consumers (the productSalesAggregate hook
 * and ProductGrid sort) need composite-keyed values for the lookup against
 * in-memory POSProducts to work, so we deliberately KEEP the composite in
 * SQLite.
 *
 * The wire boundary is different. Server-side `pos_receipt_lines.product_id`
 * is a `foreignUuid` with `restrictOnDelete()` referencing `products.id`
 * (a bare UUID). A composite would fail the FK + the XOR check constraint.
 *
 * `receiptToPayload` is the wire mapper; this test pins the unpack:
 * composite `product_id` / `composite_item_id` are split via
 * `parseMenuCompositeId(...).sellableId`. Bare ids pass through unchanged
 * (standard-retail regression guard).
 *
 * Server-side category context restoration for refund flow is a Day-3
 * concern (open question #2 from the C2 kickoff) — Day 1 explicitly drops
 * `menu_category_id` on the wire to match today's behavior.
 */

import { describe, it, expect } from 'vitest';

interface WireLine {
  product_id?: string | null;
  composite_item_id?: string | null;
  name?: string;
  quantity?: number;
}

const SELLABLE = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
const CATEGORY = '11111111-2222-3333-4444-555555555555';

describe('receiptToPayload — composite-id wire-boundary unpack (C2 Day 1)', () => {
  it('unpacks a composite product_id to bare sellable_id on the wire', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      lines: JSON.stringify([
        {
          product_id: `${SELLABLE}_${CATEGORY}`,
          name: 'Coca (Drinks)',
          quantity: 1,
          unit_price: '3.00',
          line_total: '3.00',
          tax_amount: '0.00',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);
    const wireLines = wire.lines as WireLine[];

    expect(wireLines).toHaveLength(1);
    expect(wireLines[0]!.product_id).toBe(SELLABLE);
    // composite_item_id remains absent for the product-type line.
    expect(wireLines[0]!.composite_item_id).toBeUndefined();
  });

  it('unpacks a composite composite_item_id to bare sellable_id on the wire', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      lines: JSON.stringify([
        {
          product_id: undefined,
          composite_item_id: `${SELLABLE}_${CATEGORY}`,
          name: 'Combo Meal',
          quantity: 1,
          unit_price: '8.00',
          line_total: '8.00',
          tax_amount: '0.00',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);
    const wireLines = wire.lines as WireLine[];

    expect(wireLines).toHaveLength(1);
    expect(wireLines[0]!.composite_item_id).toBe(SELLABLE);
    expect(wireLines[0]!.product_id).toBeUndefined();
  });

  it('passes a bare-uuid product_id through unchanged (standard-retail regression)', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const bareId = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    const receipt = makeOfflineReceipt({
      lines: JSON.stringify([
        {
          product_id: bareId,
          name: 'Standard Widget',
          quantity: 2,
          unit_price: '10.00',
          line_total: '20.00',
          tax_amount: '0.00',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);
    const wireLines = wire.lines as WireLine[];

    expect(wireLines).toHaveLength(1);
    expect(wireLines[0]!.product_id).toBe(bareId);
  });

  it('handles a multi-line receipt mixing composite and bare ids', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const bareId = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

    const receipt = makeOfflineReceipt({
      lines: JSON.stringify([
        {
          product_id: `${SELLABLE}_${CATEGORY}`,
          name: 'Coca (Drinks)',
          quantity: 1,
          unit_price: '3.00',
          line_total: '3.00',
          tax_amount: '0.00',
        },
        {
          product_id: bareId,
          name: 'Standard Widget',
          quantity: 2,
          unit_price: '10.00',
          line_total: '20.00',
          tax_amount: '0.00',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);
    const wireLines = wire.lines as WireLine[];

    expect(wireLines).toHaveLength(2);
    expect(wireLines[0]!.product_id).toBe(SELLABLE);
    expect(wireLines[1]!.product_id).toBe(bareId);
  });

  it('C2 Day 3: surfaces menu_category_id alongside the unpacked bare product_id (composite path)', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      lines: JSON.stringify([
        {
          product_id: `${SELLABLE}_${CATEGORY}`,
          name: 'Coca (Drinks)',
          quantity: 1,
          unit_price: '3.00',
          line_total: '3.00',
          tax_amount: '0.00',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);
    const wireLines = wire.lines as Array<Record<string, unknown>>;

    expect(wireLines[0]!.product_id).toBe(SELLABLE);
    // Day 3 — server-side `pos_receipt_lines.menu_category_id` column
    // now persists this so the refund flow can rebuild the composite.
    expect(wireLines[0]!.menu_category_id).toBe(CATEGORY);
  });

  it('C2 Day 3: surfaces menu_category_id from a composite_item_id line', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      lines: JSON.stringify([
        {
          product_id: undefined,
          composite_item_id: `${SELLABLE}_${CATEGORY}`,
          name: 'Combo Meal (Lunch)',
          quantity: 1,
          unit_price: '8.00',
          line_total: '8.00',
          tax_amount: '0.00',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);
    const wireLines = wire.lines as Array<Record<string, unknown>>;

    expect(wireLines[0]!.composite_item_id).toBe(SELLABLE);
    expect(wireLines[0]!.menu_category_id).toBe(CATEGORY);
  });

  it('C2 Day 3: omits menu_category_id for bare-uuid lines (standard-retail tenants)', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const bareId = 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee';

    const receipt = makeOfflineReceipt({
      lines: JSON.stringify([
        {
          product_id: bareId,
          name: 'Bare Widget',
          quantity: 1,
          unit_price: '5.00',
          line_total: '5.00',
          tax_amount: '0.00',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);
    const wireLines = wire.lines as Array<Record<string, unknown>>;

    expect(wireLines[0]!.product_id).toBe(bareId);
    // No composite suffix → no menu_category_id. The field is absent
    // (not null) so the server validator's `nullable, uuid` rule
    // doesn't see a sentinel; the column persists as NULL.
    expect('menu_category_id' in wireLines[0]!).toBe(false);
  });

  it('preserves non-id line fields (name, quantity, totals) verbatim', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      lines: JSON.stringify([
        {
          product_id: `${SELLABLE}_${CATEGORY}`,
          name: 'Coca (Drinks)',
          sku: 'COCA',
          quantity: 1.5,
          unit_price: '3.00',
          line_total: '4.50',
          tax_rate: '7.00',
          tax_amount: '0.29',
          discount_amount: '0.00',
          modifiers: [],
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);
    const wireLines = wire.lines as Array<Record<string, unknown>>;

    expect(wireLines[0]!.name).toBe('Coca (Drinks)');
    expect(wireLines[0]!.sku).toBe('COCA');
    expect(wireLines[0]!.quantity).toBe(1.5);
    expect(wireLines[0]!.unit_price).toBe('3.00');
    expect(wireLines[0]!.line_total).toBe('4.50');
    expect(wireLines[0]!.tax_amount).toBe('0.29');
    expect(wireLines[0]!.modifiers).toEqual([]);
  });
});
