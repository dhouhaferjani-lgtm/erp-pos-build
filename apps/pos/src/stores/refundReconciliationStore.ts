/**
 * v3-refund-chain-integration spec §4.5 — the refresh signal that tells
 * `RefundPayoutReconciliationModal` to re-query `refund_intents` for
 * pending payout-confirmation / reprint rows.
 *
 * The modal itself queries on mount (app-start recovery, §4.5's own
 * requirement) AND whenever this epoch bumps — a tiny, store-driven signal
 * rather than prop-threading a callback through
 * `RefundCheckoutFlow`/`HomePage.tsx` (which would need to know about
 * reconciliation at all). `refundCheckoutStore.ts`'s v4 `approveAndSubmit`
 * bumps this immediately after a successful settle so the payout-
 * confirmation prompt appears right away, not only on the next app
 * restart.
 */
import { create } from 'zustand';

interface RefundReconciliationState {
  epoch: number;
  refresh: () => void;
}

export const useRefundReconciliationStore = create<RefundReconciliationState>()((set, get) => ({
  epoch: 0,
  refresh: () => {
    set({ epoch: get().epoch + 1 });
  },
}));
