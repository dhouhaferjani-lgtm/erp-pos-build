import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import { queryOne, execute } from '@/lib/db';
import {
  upsertRefundDraft,
  getRefundDraftByTerminal,
  deleteRefundDraft,
  deleteRefundDraftsByTerminal,
  type RefundDraftPayload,
} from '../refundDraftRepository';
import type { CartItem } from '@/types/cart';

const RETURN_ITEMS: CartItem[] = [
  {
    id: 'return-uuid-0',
    product: { id: 'prod-1', name: 'Widget', sku: 'WGT-001', price: '10.00' },
    quantity: -1,
    unit_price: '10.00',
    line_total: '-10.00',
    tax_rate: '20',
    tax_amount: '-1.67',
    kind: 'return',
  },
];

const BUYING_ITEMS: CartItem[] = [];

const PAYLOAD: RefundDraftPayload = {
  id: 'draft-abc',
  terminalId: 'term-1',
  operatorId: 'op-1',
  receiptUuid: '550e8400-e29b-41d4-a716-446655440000',
  receiptNumber: 'R-0001',
  returnItems: RETURN_ITEMS,
  buyingItems: BUYING_ITEMS,
  transactionDiscount: undefined,
  exchangeRequestId: null,
};

describe('refundDraftRepository', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('upsertRefundDraft', () => {
    it('issues an INSERT … ON CONFLICT … DO UPDATE with all fields', async () => {
      await upsertRefundDraft(db, PAYLOAD);

      expect(execute).toHaveBeenCalledTimes(1);
      const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
      expect(sql).toMatch(/INSERT INTO refund_drafts/);
      expect(sql).toMatch(/ON CONFLICT\(id\) DO UPDATE SET/);
      expect(params).toEqual([
        PAYLOAD.id,
        PAYLOAD.terminalId,
        PAYLOAD.operatorId,
        PAYLOAD.receiptUuid,
        PAYLOAD.receiptNumber,
        JSON.stringify(PAYLOAD.returnItems),
        JSON.stringify(PAYLOAD.buyingItems),
        null, // transactionDiscount is undefined → null
        PAYLOAD.exchangeRequestId,
      ]);
    });

    it('serialises transactionDiscount when present', async () => {
      const payload: RefundDraftPayload = {
        ...PAYLOAD,
        transactionDiscount: { type: 'fixed', value: '5.00', reason: 'Loyalty' },
      };

      await upsertRefundDraft(db, payload);

      const [, , params] = vi.mocked(execute).mock.calls[0]!;
      expect(params![7]).toBe(JSON.stringify({ type: 'fixed', value: '5.00', reason: 'Loyalty' }));
    });

    it('stores exchangeRequestId when set', async () => {
      const payload: RefundDraftPayload = {
        ...PAYLOAD,
        exchangeRequestId: 'exch-123',
      };

      await upsertRefundDraft(db, payload);

      const [, , params] = vi.mocked(execute).mock.calls[0]!;
      expect(params![8]).toBe('exch-123');
    });
  });

  describe('getRefundDraftByTerminal', () => {
    it('queries by terminal_id and returns latest row', async () => {
      vi.mocked(queryOne).mockResolvedValue(null);

      const result = await getRefundDraftByTerminal(db, 'term-1');

      expect(queryOne).toHaveBeenCalledTimes(1);
      const [, sql, params] = vi.mocked(queryOne).mock.calls[0]!;
      expect(sql).toMatch(/SELECT \* FROM refund_drafts WHERE terminal_id = \$1/);
      expect(params).toEqual(['term-1']);
      expect(result).toBeNull();
    });
  });

  describe('deleteRefundDraft', () => {
    it('issues DELETE by id', async () => {
      await deleteRefundDraft(db, 'draft-abc');

      expect(execute).toHaveBeenCalledTimes(1);
      const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
      expect(sql).toMatch(/DELETE FROM refund_drafts WHERE id = \$1/);
      expect(params).toEqual(['draft-abc']);
    });
  });

  describe('deleteRefundDraftsByTerminal', () => {
    it('issues DELETE by terminal_id', async () => {
      await deleteRefundDraftsByTerminal(db, 'term-1');

      expect(execute).toHaveBeenCalledTimes(1);
      const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
      expect(sql).toMatch(/DELETE FROM refund_drafts WHERE terminal_id = \$1/);
      expect(params).toEqual(['term-1']);
    });
  });
});
