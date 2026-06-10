/**
 * Task 52 invariant: when the cashier cancels the scan-confirmation sheet,
 * neither `acceptedReceiptToken` nor the cart's return items are populated.
 *
 * The test proves the negative path — scan dispatched, Cancel clicked,
 * no cart mutation.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { useRefundFlowStore } from '../refundFlowStore';
import { useRefundCheckoutStore } from '../refundCheckoutStore';
import { useCartStore } from '../cartStore';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

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

beforeEach(() => {
  useRefundFlowStore.setState({ pendingScanResult: null, acceptedReceiptToken: null });
  useCartStore.getState().clearCart();
});

describe('Wiring: confirmation-sheet-decline must NOT hydrate the cart', () => {
  it('when Cancel is clicked (setPendingScanResult(null)), acceptedReceiptToken stays null', () => {
    // Simulate: scan dispatched → pending entry set
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    expect(useRefundFlowStore.getState().pendingScanResult).toEqual(ENTRY);

    // Simulate: cashier clicks Cancel on the confirmation sheet
    useRefundFlowStore.getState().setPendingScanResult(null);

    // acceptedReceiptToken must be null — the cart hydration path was never triggered
    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
  });

  it('cart has no return items after Cancel', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    useRefundFlowStore.getState().setPendingScanResult(null);

    expect(useCartStore.getState().returnItems()).toHaveLength(0);
  });

  it('acceptedReceiptToken is only set after acceptPendingScan is called', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    // do NOT call acceptPendingScan (that's the Cancel path)

    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
  });

  it('consumeAcceptedReceiptToken returns null after Cancel', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    useRefundFlowStore.getState().setPendingScanResult(null);

    const result = useRefundFlowStore.getState().consumeAcceptedReceiptToken();
    expect(result).toBeNull();
  });

  it('acceptedReceiptToken is set only after explicit acceptPendingScan', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    useRefundFlowStore.getState().acceptPendingScan(); // "Start refund"

    expect(useRefundFlowStore.getState().acceptedReceiptToken).not.toBeNull();
    expect(useRefundFlowStore.getState().acceptedReceiptToken?.receiptUuid).toBe(ENTRY.receipt_uuid);
  });
});

/**
 * Codex r1 M2 — receipt-scan gate while the refund checkout flow is active.
 *
 * The scanner stays enabled for any open shift, so a scanned receipt could
 * mount the confirmation sheet / hydrate the cart while refundCheckoutStore
 * is mid-flow (destination → … → submitting). Accepting a scan after the
 * one-time pre-submit fingerprint check would make the server settle the OLD
 * prepared lines while the live cart points at different ones.
 *
 * This is the store-level seam HomePage consults (HomePage adds the toast on
 * top — same gate, no render harness needed): while step !== 'idle', new
 * pending scans are rejected and a pending sheet's Accept is auto-rejected.
 */
describe('Wiring: receipt-scan gate while refund checkout is active (Codex r1 M2)', () => {
  beforeEach(() => {
    useRefundCheckoutStore.getState().reset();
    useRefundCheckoutStore.setState({ error: null });
  });

  it('setPendingScanResult(entry) is rejected while refundCheckoutStore.step !== idle', () => {
    useRefundCheckoutStore.setState({ step: 'approval' });

    useRefundFlowStore.getState().setPendingScanResult(ENTRY);

    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
  });

  it('acceptPendingScan auto-rejects (clears pending, emits nothing) when the checkout became active after the sheet opened', () => {
    // Sheet opened while idle …
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    expect(useRefundFlowStore.getState().pendingScanResult).toEqual(ENTRY);
    // … then the cashier pressed Pay and the refund checkout took over.
    useRefundCheckoutStore.setState({ step: 'submitting' });

    useRefundFlowStore.getState().acceptPendingScan();

    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
  });

  it('clearing the pending sheet (Cancel) is still allowed while the checkout is active', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    useRefundCheckoutStore.setState({ step: 'confirm' });

    useRefundFlowStore.getState().setPendingScanResult(null);

    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
  });

  it('the scan path is unaffected while the checkout is idle', () => {
    useRefundFlowStore.getState().setPendingScanResult(ENTRY);
    useRefundFlowStore.getState().acceptPendingScan();

    expect(useRefundFlowStore.getState().acceptedReceiptToken?.receiptUuid).toBe(ENTRY.receipt_uuid);
  });
});
