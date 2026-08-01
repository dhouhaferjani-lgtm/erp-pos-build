import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * v3-refund-chain-integration spec §4.3/§7.2 — proves the append-first
 * write order (fiscal event -> refund_intents update -> offline_receipts
 * insert, all inside ONE write-gate transaction) and asserts the
 * `offline_receipts` insert's exact §7.2 column contract directly:
 * negative-signed lines/subtotal/tax_amount/total/discount_amount,
 * positive-magnitude payments_json, canonical-zero cash_rounding_*,
 * NULL tender/change/tolerance/transaction-discount, `receipt_kind:
 * 'refund'`, `fiscal_schema_version: 4`.
 */

const { appendMock, markRefundEventAppendedMock, insertOfflineReceiptMock, callOrder } = vi.hoisted(() => ({
  appendMock: vi.fn(),
  markRefundEventAppendedMock: vi.fn(),
  insertOfflineReceiptMock: vi.fn(),
  callOrder: [] as string[],
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/writeGate', () => ({
  withWriteTransaction: vi.fn((_lane: string, cb: (tx: unknown) => unknown) => cb({})),
}));

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn().mockResolvedValue({
    append: (...args: unknown[]) => {
      callOrder.push('append');
      return appendMock(...args);
    },
  }),
}));

vi.mock('@/lib/db/repositories/refundIntentRepository', () => ({
  markRefundEventAppended: (...args: unknown[]) => {
    callOrder.push('markRefundEventAppended');
    return markRefundEventAppendedMock(...args);
  },
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  insertOfflineReceipt: (...args: unknown[]) => {
    callOrder.push('insertOfflineReceipt');
    return insertOfflineReceiptMock(...args);
  },
}));

import { createRefundReceipt, type CreateRefundReceiptInput } from '../refundReceiptService';
import type { RefundLineInput } from '@/lib/fiscal/payloads/RefundReceiptV4Payload';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import type { CartItem } from '@/types/cart';

function returnCartItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 'return-line-0',
    product: { id: 'prod-1', name: 'Widget', sku: 'WGT-1', price: '12.00' },
    quantity: -1,
    unit_price: '12.00',
    line_total: '-11.00',
    tax_rate: '0.00',
    tax_amount: '-0.00',
    discount_amount: '1.00',
    discount_reason: 'loyalty discount',
    kind: 'return',
    ...overrides,
  };
}

function baseInput(): CreateRefundReceiptInput {
  const lines: RefundLineInput[] = [
    { cartItem: returnCartItem(), originalLineIndex: 0, disposition: 'restock' },
  ];
  const original: OriginalFiscalEventLocalView = {
    fiscalEventId: '99999999-9999-4999-8999-999999999999',
    lineItems: [],
    payments: [],
    trainingFlag: false,
    transactionDiscountAmount: '0.00',
  };

  return {
    tenantId: 'tenant-1',
    companyId: 'company-1',
    terminalId: 'terminal-1',
    terminalCode: 'T01',
    locationCode: 'MAIN',
    operatorId: 'operator-1',
    operatorName: 'Alice',
    shiftId: '22222222-2222-4222-8222-222222222222',
    businessDate: '2026-05-20',
    eventTimeDevice: new Date('2026-05-20T14:30:00.000Z'),
    currency: 'EUR',
    seller: {
      name: 'Default Seller S.A.',
      taxNumber: '12345678901234',
      countryCode: 'FR',
      street: '1 rue de la Paix',
      city: 'Paris',
      postalCode: '75001',
    },
    refundReason: 'customer return',
    lines,
    original,
    originalReceiptUuid: '00000000-0000-4000-8000-000000000001',
    originalBusinessDate: '2026-05-19',
    approvalReferences: [
      {
        approval_event_id: 'fe-approval-1',
        approval_id: 'approval-uuid',
        approval_scope: 'void_or_return_override',
        override_event_id: 'fe-override-1',
        policy_version: 'v1',
        supervisor_user_id: 'manager-9',
        target_reference_id: '00000000-0000-4000-8000-000000000001',
      },
    ],
    refundIntentId: 'refund-intent-1',
    paymentMethodId: 'pm-cash',
    paymentRepositoryId: 'pr-till-1',
  };
}

describe('createRefundReceipt', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    callOrder.length = 0;
    appendMock.mockResolvedValue({
      id: 'fe-refund-1',
      sequence_number: 7,
      current_hash: 'h'.repeat(64),
      previous_hash: 'p'.repeat(64),
      canonical_bytes: '{"invoice_type_code":"REFUND"}',
      event_version: 4,
    });
    markRefundEventAppendedMock.mockResolvedValue(undefined);
    insertOfflineReceiptMock.mockResolvedValue(undefined);
  });

  it('spec §4.3 — append-first write order: engine.append, THEN refund_intents update, THEN offline_receipts insert', async () => {
    await createRefundReceipt(baseInput());

    expect(callOrder).toEqual(['append', 'markRefundEventAppended', 'insertOfflineReceipt']);
  });

  it('authors the fiscal event with source_event_class=refund_intents keyed by the intent id (§4.3)', async () => {
    await createRefundReceipt(baseInput());

    const [, request] = appendMock.mock.calls[0] as [unknown, { source_event_class: string; source_event_id: string; event_type: string }];
    expect(request.event_type).toBe('SALE_RECEIPT');
    expect(request.source_event_class).toBe('refund_intents');
    expect(request.source_event_id).toBe('refund-intent-1');
  });

  it('updates refund_intents with the fiscal event id ONLY known after append returns', async () => {
    await createRefundReceipt(baseInput());

    expect(markRefundEventAppendedMock).toHaveBeenCalledWith({}, 'refund-intent-1', 'fe-refund-1');
  });

  it('spec §7.2 — inserts offline_receipts with the exact column contract: negative-signed money, receipt_kind=refund, fiscal_schema_version=4', async () => {
    await createRefundReceipt(baseInput());

    const [, row] = insertOfflineReceiptMock.mock.calls[0] as [unknown, Record<string, unknown>];

    expect(row['idempotency_key']).toBe('refund-intent-1');
    expect(row['receipt_kind']).toBe('refund');
    expect(row['fiscal_schema_version']).toBe(4);
    expect(row['is_training']).toBe(0);
    expect(row['fiscal_hash']).toBe('h'.repeat(64));
    expect(row['previous_hash']).toBe('p'.repeat(64));
    expect(row['hash_sequence']).toBe(7);
    expect(row['canonical_bytes']).toBe('{"invoice_type_code":"REFUND"}');

    // Negative magnitude (§7.2's sign convention).
    expect(row['subtotal']).toBe('-11.00');
    expect(row['total']).toBe('-11.00');
    // A true zero stays canonically unsigned even after negation
    // (bcmul('0.00', '-1', ...) does not produce a stored '-0.00').
    expect(row['tax_amount']).toBe('0.00');
    // Negative-signed sum of the refunded lines' own per-line discounts.
    expect(row['discount_amount']).toBe('-1.00');

    // NULL/NULL -- never applicable to a refund payout (§7.2a).
    expect(row['transaction_discount_amount']).toBeNull();
    expect(row['transaction_discount_reason']).toBeNull();
    expect(row['tendered_amount']).toBeNull();
    expect(row['change_due']).toBeNull();
    expect(row['tolerance_shortfall']).toBeNull();

    // Canonical zero -- a refund payout never rounds.
    expect(row['cash_rounding_adjustment']).toBe('0.00');
    expect(row['cash_rounding_denomination']).toBe('0.00');

    expect(row['status']).toBe('pending');

    // payments_json -- POSITIVE magnitude (§7.2a), unlike lines/total above.
    const payments = JSON.parse(row['payments_json'] as string) as Array<Record<string, unknown>>;
    expect(payments).toHaveLength(1);
    expect(payments[0]).toMatchObject({
      method_code: 'CASH',
      amount: '11.00',
      payment_method_id: 'pm-cash',
      repository_id: 'pr-till-1',
      instrument_type: null,
    });

    // lines JSON -- negative-signed, mirrors the AVOIR's original print
    // shape (hydrateFromReceipt.ts's own convention, unchanged).
    const lines = JSON.parse(row['lines'] as string) as Array<Record<string, unknown>>;
    expect(lines).toHaveLength(1);
    expect(lines[0]).toMatchObject({
      product_id: 'prod-1',
      quantity: -1,
      line_total: '-11.00',
      unit_price: '12.00',
      discount_amount: '1.00',
      discount_reason: 'loyalty discount',
    });
  });

  it('returns the fiscal event, the fresh offline_receipts id, and the generated receipt number', async () => {
    const result = await createRefundReceipt(baseInput());

    expect(result.fiscalEvent.id).toBe('fe-refund-1');
    expect(result.offlineReceiptId).toMatch(/^[0-9a-f-]{36}$/);
    expect(result.receiptNumber).toMatch(/^MAIN-T01-\d{4}-00000007$/);
  });
});
