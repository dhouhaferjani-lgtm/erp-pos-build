import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { CartItem } from '@/types/cart';

// ── Mock the audit emit ──
const recordAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEvent(...args),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({} as import('@tauri-apps/plugin-sql').default),
}));

const upsertRefundDraft = vi.fn().mockResolvedValue(undefined);
const deleteRefundDraft = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/db/repositories/refundDraftRepository', () => ({
  upsertRefundDraft: (...a: unknown[]) => upsertRefundDraft(...a),
  getRefundDraftByTerminal: vi.fn().mockResolvedValue(null),
  deleteRefundDraft: (...a: unknown[]) => deleteRefundDraft(...a),
}));

import { useRefundDraftStore, type ActiveRefundDraft } from '../refundDraftStore';

function makeReturnItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: crypto.randomUUID(),
    product: { id: 'prod-r', name: 'Return', sku: 'SKU-R', price: '5.00' },
    quantity: -1,
    unit_price: '5.00',
    line_total: '-5.00',
    tax_rate: '0',
    tax_amount: '0.00',
    kind: 'return',
    ...overrides,
  };
}

function makeDraft(overrides: Partial<ActiveRefundDraft> = {}): ActiveRefundDraft {
  return {
    id: 'draft-1',
    terminalId: 'term-1',
    operatorId: 'op-1',
    receiptUuid: 'rcpt-uuid-1',
    receiptNumber: 'R-2026-0001',
    returnItems: [makeReturnItem(), makeReturnItem({ line_total: '-7.50' })],
    buyingItems: [],
    transactionDiscount: undefined,
    exchangeRequestId: null,
    ...overrides,
  };
}

function lastCallOfType(type: string): Record<string, unknown> | undefined {
  for (let i = recordAuditEvent.mock.calls.length - 1; i >= 0; i--) {
    const arg = recordAuditEvent.mock.calls[i]![0] as Record<string, unknown>;
    if (arg.type === type) return arg;
  }
  return undefined;
}

describe('refundDraftStore — audit emits', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    useRefundDraftStore.setState({ draft: null, isLoading: false, draftCreatedAt: null });
  });

  describe('pos.refund_draft_created (persistDraft first persist)', () => {
    it('emits with receipt_number, return_items_count, total on first persist', async () => {
      await useRefundDraftStore.getState().persistDraft('co-1', makeDraft());

      const call = lastCallOfType('pos.refund_draft_created')!;
      expect(call.aggregateType).toBe('RefundDraft');
      expect(call.aggregateId).toBe('draft-1');
      const payload = call.payload as Record<string, unknown>;
      expect(payload.receipt_number).toBe('R-2026-0001');
      expect(payload.return_items_count).toBe(2);
      expect(payload.total).toBeCloseTo(12.5); // |−5.00| + |−7.50|
    });

    it('does NOT re-emit on a re-persist of the same draft id', async () => {
      const draft = makeDraft();
      await useRefundDraftStore.getState().persistDraft('co-1', draft);
      // line edit → re-persist same id
      await useRefundDraftStore
        .getState()
        .persistDraft('co-1', { ...draft, returnItems: [...draft.returnItems, makeReturnItem()] });

      const created = recordAuditEvent.mock.calls.filter(
        (c) => (c[0] as Record<string, unknown>).type === 'pos.refund_draft_created',
      );
      expect(created).toHaveLength(1);
      expect(upsertRefundDraft).toHaveBeenCalledTimes(2);
    });

    it('emits again when a different draft id replaces the current draft', async () => {
      await useRefundDraftStore.getState().persistDraft('co-1', makeDraft({ id: 'draft-1' }));
      await useRefundDraftStore.getState().persistDraft('co-1', makeDraft({ id: 'draft-2' }));

      const created = recordAuditEvent.mock.calls.filter(
        (c) => (c[0] as Record<string, unknown>).type === 'pos.refund_draft_created',
      );
      expect(created).toHaveLength(2);
    });

    it('an emit failure does not break persistDraft', async () => {
      recordAuditEvent.mockRejectedValueOnce(new Error('audit down'));
      await expect(useRefundDraftStore.getState().persistDraft('co-1', makeDraft())).resolves.toBeUndefined();
      expect(useRefundDraftStore.getState().draft?.id).toBe('draft-1');
    });
  });

  describe('pos.refund_draft_discarded (discardDraft)', () => {
    it('emits held_duration_ms derived from the first-persist time', async () => {
      await useRefundDraftStore.getState().persistDraft('co-1', makeDraft());
      // simulate elapsed time
      useRefundDraftStore.setState({ draftCreatedAt: Date.now() - 4000 });

      await useRefundDraftStore.getState().discardDraft('co-1', 'draft-1');

      const call = lastCallOfType('pos.refund_draft_discarded')!;
      expect(call.aggregateType).toBe('RefundDraft');
      expect(call.aggregateId).toBe('draft-1');
      const payload = call.payload as Record<string, unknown>;
      expect(payload.held_duration_ms).toBeGreaterThanOrEqual(4000);
    });

    it('reports held_duration_ms null when created-at is unknown (hydrated draft)', async () => {
      useRefundDraftStore.setState({ draft: makeDraft(), draftCreatedAt: null });

      await useRefundDraftStore.getState().discardDraft('co-1', 'draft-1');

      const payload = lastCallOfType('pos.refund_draft_discarded')!.payload as Record<string, unknown>;
      expect(payload.held_duration_ms).toBeNull();
    });

    it('an emit failure does not break discardDraft', async () => {
      recordAuditEvent.mockRejectedValueOnce(new Error('audit down'));
      await expect(useRefundDraftStore.getState().discardDraft('co-1', 'draft-1')).resolves.toBeUndefined();
      expect(deleteRefundDraft).toHaveBeenCalledTimes(1);
      expect(useRefundDraftStore.getState().draft).toBeNull();
    });
  });
});
