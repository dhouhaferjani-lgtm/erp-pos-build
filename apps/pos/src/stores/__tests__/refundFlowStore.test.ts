import { describe, it, expect, beforeEach } from 'vitest';
import { useRefundFlowStore } from '../refundFlowStore';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

const ENTRY: LocalReceiptQrIndexEntry = {
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
  qr_token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
  receipt_number: 'R-0001',
  terminal_id: 'term-1',
  posted_at: '2026-04-28T09:00:00+00:00',
  total: '12500',
  currency: 'EUR',
  synced_at: '2026-04-28T09:00:05+00:00',
};

beforeEach(() => {
  useRefundFlowStore.setState({
    pendingScanResult: null,
    acceptedReceiptToken: null,
  });
});

describe('refundFlowStore', () => {
  it('starts with both slots empty', () => {
    const state = useRefundFlowStore.getState();
    expect(state.pendingScanResult).toBeNull();
    expect(state.acceptedReceiptToken).toBeNull();
  });

  it('setPendingScanResult sets a pending entry', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    expect(useRefundFlowStore.getState().pendingScanResult).toEqual(ENTRY);
    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
  });

  it('setPendingScanResult(null) clears pending', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    useRefundFlowStore.getState().setPendingScanResult(null);
    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
  });

  it('acceptPendingScan promotes pending → accepted with the typed event payload, then clears pending', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    useRefundFlowStore.getState().acceptPendingScan();

    const state = useRefundFlowStore.getState();
    expect(state.pendingScanResult).toBeNull();
    expect(state.acceptedReceiptToken).toEqual({
      receiptUuid: ENTRY.receipt_uuid,
      receiptNumber: ENTRY.receipt_number,
      receiptToken: ENTRY.qr_token,
      postedAt: ENTRY.posted_at,
      total: ENTRY.total,
      currency: ENTRY.currency,
    });
  });

  it('acceptPendingScan is a no-op when nothing is pending', () => {
    useRefundFlowStore.getState().acceptPendingScan();
    const state = useRefundFlowStore.getState();
    expect(state.pendingScanResult).toBeNull();
    expect(state.acceptedReceiptToken).toBeNull();
  });

  it('clearAccepted clears the accepted token (Task 52 will call this after consuming)', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    useRefundFlowStore.getState().acceptPendingScan();
    useRefundFlowStore.getState().clearAccepted();

    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
  });
});
