import { create } from 'zustand';
import type { CartItem } from '@/types/cart';
import { useCartStore } from './cartStore';

export interface HeldTransaction {
  id: string;
  label: string;
  items: CartItem[];
  transactionDiscount?: { amount: string; reason?: string };
  subtotal: number;
  total: number;
  itemCount: number;
  heldAt: string;
}

interface HoldState {
  heldTransactions: HeldTransaction[];
  isLoading: boolean;
  error: string | null;
  holdCurrentCart: (label: string) => void;
  recallTransaction: (id: string) => HeldTransaction | undefined;
  discardTransaction: (id: string) => void;
}

export const useHoldStore = create<HoldState>()((set, get) => ({
  heldTransactions: [],
  isLoading: false,
  error: null,

  holdCurrentCart: (label: string) => {
    const cartState = useCartStore.getState();
    const items = cartState.items;
    if (items.length === 0) return;

    const transaction: HeldTransaction = {
      id: crypto.randomUUID(),
      label: label || new Date().toLocaleTimeString(),
      items: structuredClone(items),
      transactionDiscount: cartState.transactionDiscount
        ? { ...cartState.transactionDiscount }
        : undefined,
      subtotal: cartState.subtotal(),
      total: cartState.total(),
      itemCount: cartState.itemCount(),
      heldAt: new Date().toISOString(),
    };

    set((state) => ({
      heldTransactions: [...state.heldTransactions, transaction],
    }));

    // Clear the cart after holding
    cartState.clearCart();
  },

  recallTransaction: (id: string) => {
    const { heldTransactions } = get();
    const transaction = heldTransactions.find((t) => t.id === id);
    if (!transaction) return undefined;

    // Remove from held list
    set((state) => ({
      heldTransactions: state.heldTransactions.filter((t) => t.id !== id),
    }));

    return transaction;
  },

  discardTransaction: (id: string) => {
    set((state) => ({
      heldTransactions: state.heldTransactions.filter((t) => t.id !== id),
    }));
  },
}));
