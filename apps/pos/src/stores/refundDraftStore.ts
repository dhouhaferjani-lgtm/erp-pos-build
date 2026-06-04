import { create } from 'zustand';
import type { CartItem } from '@/types/cart';
import { getDatabase } from '@/lib/db';
import {
  upsertRefundDraft,
  getRefundDraftByTerminal,
  deleteRefundDraft,
  type RefundDraftRow,
} from '@/lib/db/repositories/refundDraftRepository';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';

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
  /**
   * Epoch-ms when the current in-memory draft id was first persisted this
   * session. Used to derive `held_duration_ms` for the discard audit emit.
   * Keyed implicitly by `draft.id`; reset on discard/clear. Not persisted —
   * a draft loaded from SQLite (different session) has no created-at and the
   * discard emit will report `held_duration_ms: null`.
   */
  draftCreatedAt: number | null;
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

export const useRefundDraftStore = create<RefundDraftStore>()((set, get) => ({
  draft: null,
  isLoading: false,
  draftCreatedAt: null,

  loadDraft: async (companyId, terminalId) => {
    set({ isLoading: true });
    try {
      const db = await getDatabase(companyId);
      const row = await getRefundDraftByTerminal(db, terminalId);
      // A draft hydrated from SQLite was not created this session — its
      // created-at is unknown, so leave draftCreatedAt null.
      set({ draft: row !== null ? rowToActive(row) : null, draftCreatedAt: null });
    } finally {
      set({ isLoading: false });
    }
  },

  persistDraft: async (companyId, payload) => {
    // Snapshot BEFORE the set() so the first-persist detection sees the prior
    // draft state. "First persist" = no in-memory draft yet, or a different
    // draft id (a brand-new draft replacing another). Re-persists of the same
    // draft id (line edits, exchange-id allocation) do NOT re-emit.
    const prior = get().draft;
    const isFirstPersist = prior === null || prior.id !== payload.id;

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
    set({
      draft: payload,
      draftCreatedAt: isFirstPersist ? Date.now() : get().draftCreatedAt,
    });

    if (isFirstPersist) {
      void recordAuditEvent({
        type: 'pos.refund_draft_created',
        aggregateType: 'RefundDraft',
        aggregateId: payload.id,
        payload: {
          receipt_number: payload.receiptNumber,
          return_items_count: payload.returnItems.length,
          total: refundDraftTotal(payload.returnItems),
        },
      }).catch(() => {});
    }
  },

  discardDraft: async (companyId, draftId) => {
    // Snapshot created-at BEFORE clearing for the duration calc.
    const createdAt = get().draftCreatedAt;
    const db = await getDatabase(companyId);
    await deleteRefundDraft(db, draftId);
    set({ draft: null, draftCreatedAt: null });

    void recordAuditEvent({
      type: 'pos.refund_draft_discarded',
      aggregateType: 'RefundDraft',
      aggregateId: draftId,
      payload: {
        // null when the draft was hydrated from SQLite in a later session
        // (created-at unknown) — see draftCreatedAt note.
        held_duration_ms: createdAt !== null ? Date.now() - createdAt : null,
      },
    }).catch(() => {});
  },

  clearDraftState: () => {
    set({ draft: null, draftCreatedAt: null });
  },
}));

/** Sum of the return-line totals (absolute) for the refund-draft audit payload. */
function refundDraftTotal(returnItems: CartItem[]): number {
  return returnItems.reduce((sum, item) => sum + Math.abs(parseFloat(item.line_total) || 0), 0);
}
