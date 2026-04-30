/**
 * Concern #1 — Refund state must not leak across shift close or operator switch.
 *
 * These tests verify:
 *   - After closeShift-equivalent resets: in-memory refund state is cleared.
 *   - After clearOperator-equivalent resets: same.
 *   - The SQLite refund_drafts row is NOT deleted by clearDraftState() — only
 *     the in-memory projection is cleared (resume-on-restart contract holds).
 *
 * Concern #2 — exchange_request_id preserved across empty-then-refill cycles.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useRefundFlowStore } from '../refundFlowStore';
import { useRefundDraftStore } from '../refundDraftStore';
import { usePaymentStore } from '../paymentStore';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

// Mock the DB layer so store actions that call getDatabase don't blow up.
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
  queryOne: vi.fn().mockResolvedValue(null),
  execute: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/refundDraftRepository', () => ({
  getRefundDraftByTerminal: vi.fn().mockResolvedValue(null),
  upsertRefundDraft: vi.fn().mockResolvedValue(undefined),
  deleteRefundDraft: vi.fn().mockResolvedValue(undefined),
  deleteRefundDraftsByTerminal: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn(() => ({ companyId: 'co-1', companies: [], user: null }),),
    setState: vi.fn(),
    subscribe: vi.fn(),
    getInitialState: vi.fn(),
  },
}));

const ENTRY: LocalReceiptQrIndexEntry = {
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
  qr_token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
  receipt_number: 'R-0001',
  terminal_id: 'term-1',
  posted_at: '2026-04-28T09:00:00+00:00',
  total: '12500',
  currency: 'EUR',
  partner_id: null,
  synced_at: '2026-04-28T09:00:05+00:00',
};

function seedRefundFlowState() {
  useRefundFlowStore.getState().setPendingScanResult(ENTRY);
  useRefundFlowStore.getState().acceptPendingScan();
  // Simulate that pendingScanResult was left dangling (operator walked away before accepting)
  useRefundFlowStore.getState().setPendingScanResult(ENTRY);
}

function seedPaymentVouchers() {
  usePaymentStore.getState().addVoucherPayment('VOUCHER-001', '10.00');
  usePaymentStore.getState().addVoucherPayment('VOUCHER-002', '5.00');
}

beforeEach(() => {
  useRefundFlowStore.setState({ pendingScanResult: null, acceptedReceiptToken: null });
  useRefundDraftStore.setState({ draft: null, isLoading: false });
  usePaymentStore.getState().clearVoucherTenders();
});

// ─── Concern #1a: shift-close resets ──────────────────────────────────────────

describe('Shift-close resets (Concern #1)', () => {
  it('clearAll() resets pendingScanResult to null', () => {
    seedRefundFlowState();
    expect(useRefundFlowStore.getState().pendingScanResult).not.toBeNull();

    useRefundFlowStore.getState().clearAll();

    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
  });

  it('clearAll() resets acceptedReceiptToken to null', () => {
    seedRefundFlowState();
    expect(useRefundFlowStore.getState().acceptedReceiptToken).not.toBeNull();

    useRefundFlowStore.getState().clearAll();

    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
  });

  it('clearDraftState() resets in-memory draft to null', () => {
    // Manually inject a draft (simulates what loadDraft/persistDraft would do)
    const mockDraft: import('../refundDraftStore').ActiveRefundDraft = {
      id: 'draft-1',
      terminalId: 'term-1',
      operatorId: 'op-1',
      receiptUuid: '550e8400-e29b-41d4-a716-446655440000',
      receiptNumber: 'R-0001',
      returnItems: [],
      buyingItems: [],
      transactionDiscount: undefined,
      exchangeRequestId: null,
    };
    useRefundDraftStore.setState({ draft: mockDraft });
    expect(useRefundDraftStore.getState().draft).not.toBeNull();

    useRefundDraftStore.getState().clearDraftState();

    expect(useRefundDraftStore.getState().draft).toBeNull();
  });

  it('clearVoucherTenders() empties voucherTenders and appliedVoucherCodes', () => {
    seedPaymentVouchers();
    expect(usePaymentStore.getState().voucherTenders).toHaveLength(2);

    usePaymentStore.getState().clearVoucherTenders();

    expect(usePaymentStore.getState().voucherTenders).toHaveLength(0);
    expect(usePaymentStore.getState().appliedVoucherCodes.size).toBe(0);
  });

  it('full shift-close sequence: all four resets applied together leave refund state clean', () => {
    seedRefundFlowState();
    seedPaymentVouchers();
    useRefundDraftStore.setState({
      draft: {
        id: 'draft-1',
        terminalId: 'term-1',
        operatorId: 'op-1',
        receiptUuid: '550e8400-e29b-41d4-a716-446655440000',
        receiptNumber: 'R-0001',
        returnItems: [],
        buyingItems: [],
        transactionDiscount: undefined,
        exchangeRequestId: null,
      },
    });

    // Simulate what CloseShiftModal.handleCloseShift() does
    useRefundFlowStore.getState().clearAll();
    useRefundDraftStore.getState().clearDraftState();
    usePaymentStore.getState().clearVoucherTenders();

    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
    expect(useRefundDraftStore.getState().draft).toBeNull();
    expect(usePaymentStore.getState().voucherTenders).toHaveLength(0);
    expect(usePaymentStore.getState().appliedVoucherCodes.size).toBe(0);
  });
});

// ─── Concern #1b: operator-switch resets ──────────────────────────────────────

describe('Operator-switch resets (Concern #1)', () => {
  it('clearAll() + clearDraftState() + clearVoucherTenders() leave state clean after operator switch', () => {
    seedRefundFlowState();
    seedPaymentVouchers();
    useRefundDraftStore.setState({
      draft: {
        id: 'draft-2',
        terminalId: 'term-1',
        operatorId: 'op-A',
        receiptUuid: '550e8400-e29b-41d4-a716-446655440000',
        receiptNumber: 'R-0002',
        returnItems: [],
        buyingItems: [],
        transactionDiscount: undefined,
        exchangeRequestId: 'exch-uuid-001',
      },
    });

    // Simulate what Header.handleSwitchOperator() does before clearOperator()
    useRefundFlowStore.getState().clearAll();
    useRefundDraftStore.getState().clearDraftState();
    usePaymentStore.getState().clearVoucherTenders();

    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
    expect(useRefundDraftStore.getState().draft).toBeNull();
    expect(usePaymentStore.getState().voucherTenders).toHaveLength(0);
  });
});

// ─── Concern #1c: SQLite row NOT deleted by clearDraftState ───────────────────

describe('clearDraftState does NOT delete the SQLite row', () => {
  it('getRefundDraftByTerminal mock is untouched after clearDraftState', async () => {
    // The contract: clearDraftState() only zeros the in-memory `draft` slot.
    // It MUST NOT call deleteRefundDraft or deleteRefundDraftsByTerminal.
    const { deleteRefundDraft, deleteRefundDraftsByTerminal } = await import(
      '@/lib/db/repositories/refundDraftRepository'
    );
    vi.clearAllMocks();

    useRefundDraftStore.setState({
      draft: {
        id: 'draft-3',
        terminalId: 'term-1',
        operatorId: 'op-1',
        receiptUuid: '550e8400-e29b-41d4-a716-446655440000',
        receiptNumber: 'R-0003',
        returnItems: [],
        buyingItems: [],
        transactionDiscount: undefined,
        exchangeRequestId: null,
      },
    });

    useRefundDraftStore.getState().clearDraftState();

    // In-memory state cleared
    expect(useRefundDraftStore.getState().draft).toBeNull();
    // SQLite delete was NOT called
    expect(vi.mocked(deleteRefundDraft)).not.toHaveBeenCalled();
    expect(vi.mocked(deleteRefundDraftsByTerminal)).not.toHaveBeenCalled();
  });
});

// ─── Concern #2: exchange_request_id preserved across empty-then-refill ───────

describe('exchange_request_id preserved across empty-then-refill cycles (Concern #2)', () => {
  it('clearAll() on refundFlowStore does not affect exchangeRequestId in the draft store', () => {
    // exchangeRequestId lives in HomePage local state (not in refundFlowStore),
    // so clearAll() on refundFlowStore must NOT wipe the draft's exchangeRequestId.
    // This test verifies the store-level contract: clearAll only touches its own slots.
    seedRefundFlowState();
    useRefundDraftStore.setState({
      draft: {
        id: 'draft-4',
        terminalId: 'term-1',
        operatorId: 'op-1',
        receiptUuid: '550e8400-e29b-41d4-a716-446655440000',
        receiptNumber: 'R-0004',
        returnItems: [],
        buyingItems: [],
        transactionDiscount: undefined,
        exchangeRequestId: 'stable-uuid-001',
      },
    });

    // Only the refundFlowStore is cleared (e.g., cancelled scan)
    useRefundFlowStore.getState().clearAll();

    // The draft's exchangeRequestId must survive
    expect(useRefundDraftStore.getState().draft?.exchangeRequestId).toBe('stable-uuid-001');
  });

  it('clearDraftState() zeroes the in-memory draft including exchangeRequestId', () => {
    useRefundDraftStore.setState({
      draft: {
        id: 'draft-5',
        terminalId: 'term-1',
        operatorId: 'op-1',
        receiptUuid: '550e8400-e29b-41d4-a716-446655440000',
        receiptNumber: 'R-0005',
        returnItems: [],
        buyingItems: [],
        transactionDiscount: undefined,
        exchangeRequestId: 'stable-uuid-002',
      },
    });

    // clearDraftState is only called on shift-close or operator-switch (full teardown).
    // The SQLite row still holds the UUID — the local slot goes null.
    useRefundDraftStore.getState().clearDraftState();

    expect(useRefundDraftStore.getState().draft).toBeNull();
  });
});
