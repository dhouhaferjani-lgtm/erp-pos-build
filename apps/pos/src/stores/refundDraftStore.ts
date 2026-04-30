import { create } from 'zustand';
import type { CartItem } from '@/types/cart';
import { getDatabase } from '@/lib/db';
import {
  upsertRefundDraft,
  getRefundDraftByTerminal,
  deleteRefundDraft,
  type RefundDraftRow,
} from '@/lib/db/repositories/refundDraftRepository';

export interface ActiveRefundDraft {
  id: string;
  terminalId: string;
  operatorId: string;
  receiptUuid: string;
  receiptNumber: string;
  returnItems: CartItem[];
  buyingItems: CartItem[];
  transactionDiscount: { type: 'percentage' | 'fixed'; value: string; reason?: string } | undefined;
  exchangeRequestId: string | null;
}

function rowToActive(row: RefundDraftRow): ActiveRefundDraft {
  return {
    id: row.id,
    terminalId: row.terminal_id,
    operatorId: row.operator_id,
    receiptUuid: row.receipt_uuid,
    receiptNumber: row.receipt_number,
    returnItems: JSON.parse(row.return_items_json) as CartItem[],
    buyingItems: JSON.parse(row.buying_items_json) as CartItem[],
    transactionDiscount:
      row.transaction_discount_json !== null
        ? (JSON.parse(row.transaction_discount_json) as {
            type: 'percentage' | 'fixed';
            value: string;
            reason?: string;
          })
        : undefined,
    exchangeRequestId: row.exchange_request_id,
  };
}

interface RefundDraftState {
  draft: ActiveRefundDraft | null;
  /** True while loadDraft is running. */
  isLoading: boolean;
}

interface RefundDraftActions {
  /** Load the latest draft for the given terminal from SQLite into state. */
  loadDraft: (companyId: string, terminalId: string) => Promise<void>;
  /**
   * Persist the current draft to SQLite and update in-memory state.
   * Creates or replaces the row identified by `payload.id`.
   */
  persistDraft: (companyId: string, payload: ActiveRefundDraft) => Promise<void>;
  /** Delete the draft from SQLite and clear in-memory state. */
  discardDraft: (companyId: string, draftId: string) => Promise<void>;
  /** Clear in-memory draft only (e.g. after a successful checkout). */
  clearDraftState: () => void;
}

type RefundDraftStore = RefundDraftState & RefundDraftActions;

export const useRefundDraftStore = create<RefundDraftStore>()((set) => ({
  draft: null,
  isLoading: false,

  loadDraft: async (companyId, terminalId) => {
    set({ isLoading: true });
    try {
      const db = await getDatabase(companyId);
      const row = await getRefundDraftByTerminal(db, terminalId);
      set({ draft: row !== null ? rowToActive(row) : null });
    } finally {
      set({ isLoading: false });
    }
  },

  persistDraft: async (companyId, payload) => {
    const db = await getDatabase(companyId);
    await upsertRefundDraft(db, {
      id: payload.id,
      terminalId: payload.terminalId,
      operatorId: payload.operatorId,
      receiptUuid: payload.receiptUuid,
      receiptNumber: payload.receiptNumber,
      returnItems: payload.returnItems,
      buyingItems: payload.buyingItems,
      transactionDiscount: payload.transactionDiscount,
      exchangeRequestId: payload.exchangeRequestId,
    });
    set({ draft: payload });
  },

  discardDraft: async (companyId, draftId) => {
    const db = await getDatabase(companyId);
    await deleteRefundDraft(db, draftId);
    set({ draft: null });
  },

  clearDraftState: () => {
    set({ draft: null });
  },
}));
