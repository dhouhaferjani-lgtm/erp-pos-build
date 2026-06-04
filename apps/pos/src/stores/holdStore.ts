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
  subtotal: number;
  total: number;
  itemCount: number;
  heldAt: string;
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
    subtotal: parseFloat(row.subtotal),
    total: parseFloat(row.total),
    itemCount: row.item_count,
    heldAt: row.held_at,
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
    const subtotal = cartState.subtotal();
    const total = cartState.total();
    const itemCount = cartState.itemCount();
    const heldAt = new Date().toISOString();
    const id = crypto.randomUUID();

    const row: HeldTransactionRow = {
      id,
      terminal_id: terminalId,
      operator_id: operatorId,
      label: resolvedLabel,
      items_json: JSON.stringify(items),
      transaction_discount_json: cartState.transactionDiscount
        ? JSON.stringify(cartState.transactionDiscount)
        : null,
      subtotal: subtotal.toString(),
      total: total.toString(),
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
    return found;
  },

  discardTransaction: async (id: string) => {
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
  },
}));
