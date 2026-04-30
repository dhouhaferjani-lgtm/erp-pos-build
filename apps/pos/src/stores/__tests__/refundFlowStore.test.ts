import { describe, it, expect, beforeEach } from 'vitest';
import { useRefundFlowStore } from '../refundFlowStore';
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

const ENTRY2: LocalReceiptQrIndexEntry = {
  receipt_uuid: '660e8400-e29b-41d4-a716-446655440001',
  qr_token: '1:keyabc:660e8400-e29b-41d4-a716-446655440001:macblob',
  receipt_number: 'R-0002',
  terminal_id: 'term-1',
  posted_at: '2026-04-28T10:00:00+00:00',
  total: '5000',
  currency: 'EUR',
  partner_id: null,
  synced_at: '2026-04-28T10:00:05+00:00',
};

beforeEach(() => {
  useRefundFlowStore.setState({
    pendingScanResult: null,
    acceptedReceiptToken: null,
  });
  useCartStore.getState().clearCart();
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

  describe('consumeAcceptedReceiptToken (atomic read+clear)', () => {
    it('returns null without throwing when the accepted slot is already empty', () => {
      const value = useRefundFlowStore.getState().consumeAcceptedReceiptToken();
      expect(value).toBeNull();
      expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
    });

    it('returns the accepted token AND atomically clears the slot', () => {
      useRefundFlowStore.getState().setPendingScanResult(ENTRY);
      useRefundFlowStore.getState().acceptPendingScan();

      const value = useRefundFlowStore.getState().consumeAcceptedReceiptToken();

      expect(value).toEqual({
        receiptUuid: ENTRY.receipt_uuid,
        receiptNumber: ENTRY.receipt_number,
        receiptToken: ENTRY.qr_token,
        postedAt: ENTRY.posted_at,
        total: ENTRY.total,
        currency: ENTRY.currency,
      });
      // Slot is cleared in the SAME action — no race window where a re-render
      // could observe the value AND consume it again.
      expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
    });

    it('a re-render scenario (consume called twice) returns null on the second call', () => {
      useRefundFlowStore.getState().setPendingScanResult(ENTRY);
      useRefundFlowStore.getState().acceptPendingScan();

      const first = useRefundFlowStore.getState().consumeAcceptedReceiptToken();
      const second = useRefundFlowStore.getState().consumeAcceptedReceiptToken();

      expect(first).not.toBeNull();
      expect(second).toBeNull();
    });

    it('Bug 1: second scan in the same session produces a distinct token from the first', () => {
      // First scan: scan → accept → consume
      useRefundFlowStore.getState().setPendingScanResult(ENTRY);
      useRefundFlowStore.getState().acceptPendingScan();
      const first = useRefundFlowStore.getState().consumeAcceptedReceiptToken();
      expect(first?.receiptUuid).toBe(ENTRY.receipt_uuid);
      // Slot is cleared after consume — selector would now see null
      expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();

      // Second scan in the same session: a new receipt is scanned and accepted
      useRefundFlowStore.getState().setPendingScanResult(ENTRY2);
      useRefundFlowStore.getState().acceptPendingScan();
      // Selector would now see the new token (non-null) — React effect re-fires
      expect(useRefundFlowStore.getState().acceptedReceiptToken).not.toBeNull();
      expect(useRefundFlowStore.getState().acceptedReceiptToken?.receiptUuid).toBe(ENTRY2.receipt_uuid);

      // Consume second token
      const second = useRefundFlowStore.getState().consumeAcceptedReceiptToken();
      expect(second?.receiptUuid).toBe(ENTRY2.receipt_uuid);
      // Two distinct receipts processed
      expect(first?.receiptUuid).not.toBe(second?.receiptUuid);
    });
  });
});

describe('Resume sequence — Bug 3 fix: only one cart-mutation path fires', () => {
  function makeReturnLine(id: string): import('@/types/cart').CartItem {
    return {
      id,
      product: { id: `prod-${id}`, name: 'Return Item', sku: 'SKU', price: '10.00' },
      quantity: -1,
      unit_price: '10.00',
      line_total: '-10.00',
      tax_rate: '0',
      tax_amount: '0.00',
      kind: 'return',
    };
  }

  function makeSaleLine(id: string): import('@/types/cart').CartItem {
    return {
      id,
      product: { id: `prod-${id}`, name: 'Sale Item', sku: 'SKU', price: '20.00' },
      quantity: 1,
      unit_price: '20.00',
      line_total: '20.00',
      tax_rate: '0',
      tax_amount: '0.00',
      kind: 'sale',
    };
  }

  beforeEach(() => {
    useCartStore.getState().clearCart();
  });

  it('pure-refund resume: cart contains only return items (replaceReturnItems path)', () => {
    const returnItems = [makeReturnLine('r1'), makeReturnLine('r2')];

    // Simulate handleResumeDraft with no buyingItems (pure-refund path)
    useCartStore.getState().replaceReturnItems(returnItems);

    expect(useCartStore.getState().returnItems()).toHaveLength(2);
    expect(useCartStore.getState().saleItems()).toHaveLength(0);
  });

  it('exchange resume: cart contains both return and sale items (replaceCart path)', () => {
    const returnItems = [makeReturnLine('r1')];
    const buyingItems = [makeSaleLine('s1'), makeSaleLine('s2')];
    const discount = { type: 'fixed' as const, value: '5.00' };

    // Simulate handleResumeDraft with buyingItems (exchange path)
    useCartStore.getState().replaceCart([...returnItems, ...buyingItems], discount);

    expect(useCartStore.getState().returnItems()).toHaveLength(1);
    expect(useCartStore.getState().saleItems()).toHaveLength(2);
    expect(useCartStore.getState().transactionDiscount).toEqual(discount);
  });

  it('exchange resume: replaceCart does NOT leave stale return items from a previous replaceReturnItems call', () => {
    const returnItems = [makeReturnLine('r1')];
    const buyingItems = [makeSaleLine('s1')];

    // Bug 3 scenario: if replaceReturnItems were called first then replaceCart, the
    // second call must produce the final state — no duplicate return lines.
    useCartStore.getState().replaceReturnItems(returnItems);
    useCartStore.getState().replaceCart([...returnItems, ...buyingItems], undefined);

    // replaceCart replaces the entire cart — should not have 2 return lines
    expect(useCartStore.getState().returnItems()).toHaveLength(1);
    expect(useCartStore.getState().saleItems()).toHaveLength(1);
  });
});
