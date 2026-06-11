import { describe, it, expect } from 'vitest';
import { hydrateFromReceipt } from '../hydrateFromReceipt';
import type { ReceiptTokenAccepted } from '@/types/refund';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';

function makeEvent(overrides: Partial<ReceiptTokenAccepted> = {}): ReceiptTokenAccepted {
  return {
    receiptUuid: '550e8400-e29b-41d4-a716-446655440000',
    receiptNumber: 'R-0001',
    receiptToken: '1:key:550e8400-e29b-41d4-a716-446655440000:mac',
    postedAt: '2026-04-28T09:00:00Z',
    total: '1000',
    currency: 'EUR',
    ...overrides,
  };
}

function makeOfflineReceipt(linesJson: string): OfflineReceipt {
  return {
    id: '550e8400-e29b-41d4-a716-446655440000',
    idempotency_key: 'idem-1',
    receipt_number: 'R-0001',
    terminal_id: 'term-1',
    terminal_code: 'T01',
    operator_id: 'op-1',
    operator_name: 'Alice',
    lines: linesJson,
    subtotal: '10.00',
    tax_amount: '1.67',
    discount_amount: '0',
    total: '10.00',
    currency: 'EUR',
    fiscal_hash: 'hash',
    previous_hash: 'prev',
    hash_sequence: 1,
    transaction_discount_amount: null,
    transaction_discount_reason: null,
    tendered_amount: null,
    change_due: null,
    payment_method_id: 'pm-1',
    payment_repository_id: 'pr-1',
    status: 'synced',
    retry_count: 0,
    payments_json: '[]',
    consumption_mode: null,
    table_id: null,
    server_receipt_id: null,
    fiscal_schema_version: 2,
    is_training: 0,
    created_at: '2026-04-28T09:00:00Z',
    synced_at: '2026-04-28T09:01:00Z',
    sync_error: null,
  };
}

describe('hydrateFromReceipt', () => {
  it('returns one CartItem per receipt line with kind = "return"', () => {
    const lines = JSON.stringify([
      {
        product_id: 'prod-1',
        name: 'Widget',
        sku: 'WGT-001',
        quantity: 2,
        unit_price: '5.00',
        line_total: '10.00',
        tax_rate: '20',
        tax_amount: '1.67',
      },
    ]);
    const event = makeEvent();
    const receipt = makeOfflineReceipt(lines);

    const items = hydrateFromReceipt(event, receipt);

    expect(items).toHaveLength(1);
    expect(items[0]!.kind).toBe('return');
  });

  it('returns negative quantities', () => {
    const lines = JSON.stringify([
      {
        product_id: 'prod-1',
        name: 'Widget',
        sku: 'WGT-001',
        quantity: 3,
        unit_price: '5.00',
        line_total: '15.00',
        tax_rate: '0',
        tax_amount: '0.00',
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(items[0]!.quantity).toBe(-3);
  });

  it('returns negative line_total', () => {
    const lines = JSON.stringify([
      {
        product_id: 'prod-1',
        name: 'Widget',
        sku: 'WGT-001',
        quantity: 1,
        unit_price: '10.00',
        line_total: '10.00',
        tax_rate: '20',
        tax_amount: '1.67',
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(parseFloat(items[0]!.line_total)).toBeLessThan(0);
    expect(parseFloat(items[0]!.line_total)).toBeCloseTo(-10);
  });

  it('returns negative tax_amount', () => {
    const lines = JSON.stringify([
      {
        product_id: 'prod-1',
        name: 'Widget',
        sku: 'WGT-001',
        quantity: 1,
        unit_price: '10.00',
        line_total: '10.00',
        tax_rate: '20',
        tax_amount: '1.67',
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(parseFloat(items[0]!.tax_amount)).toBeCloseTo(-1.67);
  });

  it('maps product fields from the line', () => {
    const lines = JSON.stringify([
      {
        product_id: 'prod-abc',
        name: 'Fancy Widget',
        sku: 'FW-001',
        quantity: 1,
        unit_price: '25.00',
        line_total: '25.00',
        tax_rate: '10',
        tax_amount: '2.27',
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(items[0]!.product.id).toBe('prod-abc');
    expect(items[0]!.product.name).toBe('Fancy Widget');
    expect(items[0]!.product.sku).toBe('FW-001');
    expect(items[0]!.unit_price).toBe('25.00');
  });

  it('uses composite_item_id when product_id is absent', () => {
    const lines = JSON.stringify([
      {
        composite_item_id: 'combo-1',
        name: 'Combo Meal',
        sku: 'COMBO-001',
        quantity: 1,
        unit_price: '20.00',
        line_total: '20.00',
        tax_rate: '0',
        tax_amount: '0.00',
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(items[0]!.product.id).toBe('combo-1');
    expect(items[0]!.product.sellableType).toBe('composite_item');
  });

  it('falls back to a stable uuid-scoped id when neither product_id nor composite_item_id is present', () => {
    const lines = JSON.stringify([
      {
        name: 'Mystery Item',
        sku: 'MYST-001',
        quantity: 1,
        unit_price: '1.00',
        line_total: '1.00',
        tax_rate: '0',
        tax_amount: '0.00',
      },
    ]);

    const event = makeEvent({ receiptUuid: 'aaaa-bbbb' });
    const items = hydrateFromReceipt(event, makeOfflineReceipt(lines));

    expect(items[0]!.product.id).toContain('aaaa-bbbb');
  });

  it('generates unique cart line ids scoped to the receipt uuid', () => {
    const lines = JSON.stringify([
      { product_id: 'p1', name: 'A', sku: 'A', quantity: 1, unit_price: '1.00', line_total: '1.00', tax_rate: '0', tax_amount: '0.00' },
      { product_id: 'p2', name: 'B', sku: 'B', quantity: 2, unit_price: '2.00', line_total: '4.00', tax_rate: '0', tax_amount: '0.00' },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(items[0]!.id).not.toBe(items[1]!.id);
    expect(items[0]!.id).toContain('550e8400-e29b-41d4-a716-446655440000');
  });

  it('handles an empty lines array', () => {
    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt('[]'));
    expect(items).toHaveLength(0);
  });

  it('threads variant identity from the stored line onto the cart item', () => {
    const lines = JSON.stringify([
      {
        product_id: 'prod-1',
        variant_id: 'variant-9',
        variant_name: 'T-Shirt — Size M / Red',
        name: 'T-Shirt — Size M / Red',
        sku: 'TS-M-RED',
        quantity: 1,
        unit_price: '15.00',
        line_total: '15.00',
        tax_rate: '19.00',
        tax_amount: '2.85',
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(items[0]!.product.variant_id).toBe('variant-9');
    expect(items[0]!.product.variant_name).toBe('T-Shirt — Size M / Red');
  });

  it('omits variant fields when the stored line has none', () => {
    const lines = JSON.stringify([
      {
        product_id: 'prod-1',
        name: 'Widget A',
        sku: 'PROD-001',
        quantity: 1,
        unit_price: '10.00',
        line_total: '10.00',
        tax_rate: '19.00',
        tax_amount: '1.90',
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect('variant_id' in items[0]!.product).toBe(false);
    expect('variant_name' in items[0]!.product).toBe(false);
  });
});
