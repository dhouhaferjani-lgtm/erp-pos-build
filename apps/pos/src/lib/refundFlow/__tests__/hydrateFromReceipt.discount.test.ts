import { describe, it, expect } from 'vitest';
import { hydrateFromReceipt } from '../hydrateFromReceipt';
import type { ReceiptTokenAccepted } from '@/types/refund';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';

/**
 * v3-refund-chain-integration spec §3.2 — `hydrateFromReceipt.ts`'s
 * discount-field carry-through fix: `discount_amount`/`discount_reason`
 * persisted per-line by `receiptService.ts:523-558` at sale time must
 * survive onto the hydrated (negative-signed) return `CartItem`, not just
 * the amount but the reason alongside it — `RefundReceiptV4Payload.ts`'s
 * normalization step depends on this to build a v4 payload whose
 * `line_discount_amount`/`line_discount_reason` correctly reflect the
 * ORIGINAL line's own discount, not a silently-dropped one.
 */

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
    cash_rounding_adjustment: null,
    cash_rounding_denomination: null,
    tolerance_shortfall: null,
    server_receipt_id: null,
    fiscal_schema_version: 2,
    is_training: 0,
    created_at: '2026-04-28T09:00:00Z',
    synced_at: '2026-04-28T09:01:00Z',
    sync_error: null,
  };
}

describe('hydrateFromReceipt — discount carry-through (spec §3.2)', () => {
  it('carries discount_amount AND discount_reason through onto the returned CartItem, unnegated', () => {
    const lines = JSON.stringify([
      {
        product_id: 'prod-1',
        name: 'Widget',
        sku: 'WGT-001',
        quantity: 1,
        unit_price: '10.00',
        line_total: '8.00',
        tax_rate: '20',
        tax_amount: '1.33',
        discount_amount: '2.00',
        discount_reason: 'loyalty discount',
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(items).toHaveLength(1);
    // Discount is a reduction regardless of direction -- NOT negated, unlike
    // line_total/tax_amount above.
    expect(items[0]?.discount_amount).toBe('2.00');
    expect(items[0]?.discount_reason).toBe('loyalty discount');
  });

  it('leaves discount_amount/discount_reason unset when the original line carried none', () => {
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

    expect(items).toHaveLength(1);
    expect(items[0]?.discount_amount).toBeUndefined();
    expect(items[0]?.discount_reason).toBeUndefined();
  });

  it('leaves discount_amount/discount_reason unset when explicitly null on the persisted line', () => {
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
        discount_amount: null,
        discount_reason: null,
      },
    ]);

    const items = hydrateFromReceipt(makeEvent(), makeOfflineReceipt(lines));

    expect(items).toHaveLength(1);
    expect(items[0]?.discount_amount).toBeUndefined();
    expect(items[0]?.discount_reason).toBeUndefined();
  });
});
