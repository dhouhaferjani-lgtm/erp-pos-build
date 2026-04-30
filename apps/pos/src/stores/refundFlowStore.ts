import { create } from 'zustand';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';
import type { ReceiptTokenAccepted } from '@/types/refund';

/**
 * Refund-flow Zustand slot — the typed event channel between the POS scan
 * dispatcher (Task 50) and the unified-cart hydration code (Task 52).
 *
 *   pendingScanResult  — set by the dispatcher when a scanned token resolves
 *                        to a sale receipt at THIS terminal. The Receipt-Scan
 *                        Confirmation Sheet renders from this slot. Cart is
 *                        NOT mutated yet.
 *   acceptedReceiptToken — promoted from `pendingScanResult` when the cashier
 *                        taps "Start refund". This is the typed event Task 52
 *                        subscribes to; it MUST call `clearAccepted()` after
 *                        consuming, otherwise a re-render would re-fire it.
 */
interface RefundFlowState {
  pendingScanResult: LocalReceiptQrIndexEntry | null;
  acceptedReceiptToken: ReceiptTokenAccepted | null;
}

interface RefundFlowActions {
  setPendingScanResult: (entry: LocalReceiptQrIndexEntry | null) => void;
  /** Promotes pendingScanResult → acceptedReceiptToken. No-op when nothing pending. */
  acceptPendingScan: () => void;
  /** Task 52 calls this after consuming the accepted-token event. */
  clearAccepted: () => void;
}

type RefundFlowStore = RefundFlowState & RefundFlowActions;

function toAcceptedEvent(entry: LocalReceiptQrIndexEntry): ReceiptTokenAccepted {
  return {
    receiptUuid: entry.receipt_uuid,
    receiptNumber: entry.receipt_number,
    receiptToken: entry.qr_token,
    postedAt: entry.posted_at,
    total: entry.total,
    currency: entry.currency,
  };
}

export const useRefundFlowStore = create<RefundFlowStore>()((set, get) => ({
  pendingScanResult: null,
  acceptedReceiptToken: null,

  setPendingScanResult: (entry) => {
    set({ pendingScanResult: entry });
  },

  acceptPendingScan: () => {
    const pending = get().pendingScanResult;
    if (pending === null) return;
    set({
      pendingScanResult: null,
      acceptedReceiptToken: toAcceptedEvent(pending),
    });
  },

  clearAccepted: () => {
    set({ acceptedReceiptToken: null });
  },
}));
