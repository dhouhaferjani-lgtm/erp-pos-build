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
 *                        taps "Start refund". The slot value is fine for
 *                        "is something pending?" subscriptions, but consumers
 *                        that *act on* the event MUST use
 *                        `consumeAcceptedReceiptToken()` (read+clear in one
 *                        atomic action) so a re-render cannot re-fire the
 *                        same event twice.
 */
interface RefundFlowState {
  pendingScanResult: LocalReceiptQrIndexEntry | null;
  acceptedReceiptToken: ReceiptTokenAccepted | null;
}

interface RefundFlowActions {
  setPendingScanResult: (entry: LocalReceiptQrIndexEntry | null) => void;
  /** Promotes pendingScanResult → acceptedReceiptToken. No-op when nothing pending. */
  acceptPendingScan: () => void;
  /**
   * Atomic read-and-clear of `acceptedReceiptToken`. Returns the typed event
   * payload (or null when the slot is empty) AND clears the slot in the same
   * action. Task 52 (and any other consumer) MUST use this rather than
   * reading `acceptedReceiptToken` directly + calling `clearAccepted()`,
   * because between the read and the clear a re-render could re-fire the
   * same event. Calling it on an empty slot returns null without throwing.
   */
  consumeAcceptedReceiptToken: () => ReceiptTokenAccepted | null;
  /**
   * Manual clear. Kept for callers that already have the event in hand
   * (e.g. from a previous read) and just need to reset the slot. Most
   * consumers should prefer `consumeAcceptedReceiptToken()` instead.
   */
  clearAccepted: () => void;
  /**
   * Full in-memory reset — clears both `pendingScanResult` and
   * `acceptedReceiptToken`. Called on shift close and operator switch so
   * dangling scan state from Operator A cannot bleed into Operator B's
   * session. Safe to call when both slots are already null.
   */
  clearAll: () => void;
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

  consumeAcceptedReceiptToken: () => {
    const accepted = get().acceptedReceiptToken;
    if (accepted === null) return null;
    set({ acceptedReceiptToken: null });
    return accepted;
  },

  clearAccepted: () => {
    set({ acceptedReceiptToken: null });
  },

  clearAll: () => {
    set({ pendingScanResult: null, acceptedReceiptToken: null });
  },
}));
