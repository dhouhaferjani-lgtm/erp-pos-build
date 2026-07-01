import { create } from 'zustand';
import type { CartItem } from '@/types/cart';
import { getDatabase } from '@/lib/db';
import {
  insertHeldTransaction,
  listHeldTransactions,
  deleteHeldTransaction,
  type HeldTransactionRow,
} from '@/lib/db/repositories/heldTransactionRepository';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useCartStore } from './cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';

export interface TransactionDiscount {
  type: 'percentage' | 'fixed';
  value: string;
  reason?: string;
}

export interface HeldTransaction {
  id: string;
  label: string;
  items: CartItem[];
  transactionDiscount?: TransactionDiscount;
  subtotal: string;
  total: string;
  itemCount: number;
  heldAt: string;
  /** Operator who parked the sale — used for cross-operator recall detection. */
  heldByOperatorId: string;
}

interface HoldState {
  heldTransactions: HeldTransaction[];
  isLoading: boolean;
  error: string | null;
  loadHeldTransactions: () => Promise<void>;
  holdCurrentCart: (label: string) => Promise<void>;
  recallTransaction: (id: string) => Promise<HeldTransaction | undefined>;
  discardTransaction: (id: string) => Promise<void>;
}

async function getDb(): Promise<import('@tauri-apps/plugin-sql').default> {
  const { companyId } = useAuthStore.getState();
  return getDatabase(companyId ?? '');
}

function rowToHeldTransaction(row: HeldTransactionRow): HeldTransaction {
  const items: CartItem[] = JSON.parse(row.items_json) as CartItem[];
  const transactionDiscount = row.transaction_discount_json
    ? (JSON.parse(row.transaction_discount_json) as TransactionDiscount)
    : undefined;
  return {
    id: row.id,
    label: row.label,
    items,
    transactionDiscount,
    subtotal: row.subtotal,
    total: row.total,
    itemCount: row.item_count,
    heldAt: row.held_at,
    heldByOperatorId: row.operator_id,
  };
}

export const useHoldStore = create<HoldState>()((set, get) => ({
  heldTransactions: [],
  isLoading: false,
  error: null,

  loadHeldTransactions: async () => {
    set({ isLoading: true, error: null });
    try {
      const terminalId = useTerminalStore.getState().terminal?.id;
      if (!terminalId) {
        set({ heldTransactions: [], isLoading: false });
        return;
      }
      const db = await getDb();
      const rows = await listHeldTransactions(db, terminalId);
      set({
        heldTransactions: rows.map(rowToHeldTransaction),
        isLoading: false,
      });
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      set({ isLoading: false, error: msg });
    }
  },

  holdCurrentCart: async (label: string) => {
    const cartState = useCartStore.getState();
    const items = cartState.items;
    if (items.length === 0) return;

    const terminalId = useTerminalStore.getState().terminal?.id;
    const operatorId = useOperatorStore.getState().operator?.id;
    if (!terminalId || !operatorId) {
      set({ error: 'Terminal or operator not ready' });
      return;
    }

    const resolvedLabel = label || new Date().toLocaleTimeString();
    const subtotal = cartState.subtotalString();
    const total = cartState.totalString();
    const itemCount = cartState.itemCount();
    const discountTotal = cartState.discountAmount();
    const heldAt = new Date().toISOString();
    const id = crypto.randomUUID();
    // Snapshot the cart session id BEFORE clearCart('hold') nulls it — this
    // correlates the parked sale with the prior cart session.
    const cartSessionId = cartState.getCartSessionId();

    const row: HeldTransactionRow = {
      id,
      terminal_id: terminalId,
      operator_id: operatorId,
      label: resolvedLabel,
      items_json: JSON.stringify(items),
      transaction_discount_json: cartState.transactionDiscount
        ? JSON.stringify(cartState.transactionDiscount)
        : null,
      subtotal,
      total,
      item_count: itemCount,
      held_at: heldAt,
    };

    try {
      const db = await getDb();
      await insertHeldTransaction(db, row);
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      set({ error: msg });
      return;
    }

    const heldTransaction: HeldTransaction = rowToHeldTransaction(row);
    set((state) => ({
      heldTransactions: [heldTransaction, ...state.heldTransactions],
      error: null,
    }));
    cartState.clearCart('hold');
    // T0.2 (Codex F-2): hold-then-clear ends the current cart submission
    // attempt. Drop the pending idempotency key so the next sale (or recall
    // of a different held cart) gets a fresh allocation. Without this, a
    // stale key from a half-attempted checkout-then-hold flow would leak.
    usePaymentStore.getState().discardPendingSubmission();

    // Best-effort audit emit OUTSIDE the set() — never throws into the action.
    // cart_session_id (snapshotted pre-clear) is carried in the payload so the
    // held sale correlates with the cart session that produced it.
    void recordAuditEvent({
      type: 'pos.sale_held',
      aggregateType: 'HeldSale',
      aggregateId: id,
      payload: {
        line_count: itemCount,
        total,
        discount_total: discountTotal,
        cart_session_id: cartSessionId,
      },
    }).catch(() => {});
  },

  recallTransaction: async (id: string) => {
    const found = get().heldTransactions.find((t) => t.id === id);
    if (!found) return undefined;

    try {
      const db = await getDb();
      await deleteHeldTransaction(db, id);
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      set({ error: msg });
      return undefined;
    }

    set((state) => ({
      heldTransactions: state.heldTransactions.filter((t) => t.id !== id),
    }));

    // Best-effort audit emit OUTSIDE the set() — never throws into the action.
    const currentOperatorId = useOperatorStore.getState().operator?.id ?? null;
    const heldDurationMs = Date.now() - new Date(found.heldAt).getTime();
    void recordAuditEvent({
      type: 'pos.sale_recalled',
      aggregateType: 'HeldSale',
      aggregateId: id,
      payload: {
        held_duration_ms: heldDurationMs,
        held_by_operator_id: found.heldByOperatorId,
        cross_operator: currentOperatorId !== found.heldByOperatorId,
        // cross_shift: held rows do not record the shift they were parked in,
        // so this cannot be derived from existing state — emit null rather than
        // inventing state. (See Task 9 note.)
        cross_shift: null,
      },
    }).catch(() => {});

    return found;
  },

  discardTransaction: async (id: string) => {
    // Snapshot the held row BEFORE deletion for the audit duration calc.
    const found = get().heldTransactions.find((t) => t.id === id);
    try {
      const db = await getDb();
      await deleteHeldTransaction(db, id);
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      set({ error: msg });
      return;
    }
    set((state) => ({
      heldTransactions: state.heldTransactions.filter((t) => t.id !== id),
    }));

    // Best-effort audit emit OUTSIDE the set() — never throws into the action.
    if (found) {
      const heldDurationMs = Date.now() - new Date(found.heldAt).getTime();
      void recordAuditEvent({
        type: 'pos.sale_hold_discarded',
        aggregateType: 'HeldSale',
        aggregateId: id,
        payload: { held_duration_ms: heldDurationMs },
      }).catch(() => {});
    }
  },
}));
