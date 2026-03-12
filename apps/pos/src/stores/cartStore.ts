import { create } from 'zustand';
import type { CartItem, SelectedModifier } from '@/types/cart';
import type { POSProduct } from '@/types/product';

interface CartState {
  items: CartItem[];
  transactionDiscount?: { amount: string; reason?: string };
}

interface CartActions {
  addItem: (product: POSProduct, selectedModifiers?: SelectedModifier[]) => void;
  updateQuantity: (itemId: string, quantity: number) => void;
  removeItem: (itemId: string) => void;
  clearCart: () => void;
  setTransactionDiscount: (discount: { amount: string; reason?: string } | undefined) => void;
}

interface CartDerived {
  subtotal: () => number;
  taxAmount: () => number;
  total: () => number;
  itemCount: () => number;
}

type CartStore = CartState & CartActions & CartDerived;

const DECIMALS = 2;

function recalcLineTotal(item: CartItem, newQty: number): CartItem {
  const grossTotal = parseFloat(item.unit_price) * newQty;
  let discountAmount = 0;

  if (item.discount_type === 'percentage' && item.discount_percent) {
    discountAmount = (grossTotal * parseFloat(item.discount_percent)) / 100;
  } else if (item.discount_amount && item.discount_type === 'fixed') {
    discountAmount = parseFloat(item.discount_amount);
  }

  const lineTotal = Math.max(0, grossTotal - discountAmount);

  return {
    ...item,
    quantity: newQty,
    ...(item.discount_type === 'percentage'
      ? { discount_amount: discountAmount.toFixed(DECIMALS) }
      : {}),
    line_total: lineTotal.toFixed(DECIMALS),
  };
}

const initialState: CartState = {
  items: [],
  transactionDiscount: undefined,
};

export const useCartStore = create<CartStore>()((set, get) => ({
  ...initialState,

  addItem: (product: POSProduct, selectedModifiers?: SelectedModifier[]) => {
    set((state) => {
      const hasModifiers = selectedModifiers && selectedModifiers.length > 0;

      // For items without modifiers, try to find and increment existing
      if (!hasModifiers) {
        const existingIndex = state.items.findIndex(
          (item) =>
            item.product.id === product.id &&
            !item.product.selectedModifiers?.length,
        );

        if (existingIndex !== -1) {
          const existing = state.items[existingIndex]!;
          const updated = recalcLineTotal(existing, existing.quantity + 1);
          const newItems = [...state.items];
          newItems[existingIndex] = updated;
          return { items: newItems };
        }
      }

      // Add new item
      const basePrice = parseFloat(product.sale_price ?? '0');
      const modifierAdjustment = hasModifiers
        ? selectedModifiers.reduce((sum, m) => sum + parseFloat(m.price_adjustment), 0)
        : 0;
      const unitPrice = basePrice + modifierAdjustment;
      const priceValue = unitPrice.toFixed(DECIMALS);

      const cartProduct: CartItem['product'] = {
        id: product.id,
        name: product.name,
        sku: product.sku,
        price: priceValue,
        ...(product.sellableType ? { sellableType: product.sellableType } : {}),
      };
      if (hasModifiers) {
        cartProduct.selectedModifiers = selectedModifiers;
      }

      const newItem: CartItem = {
        id: crypto.randomUUID(),
        product: cartProduct,
        quantity: 1,
        unit_price: priceValue,
        line_total: unitPrice.toFixed(DECIMALS),
        tax_amount: (0).toFixed(DECIMALS),
      };

      return { items: [...state.items, newItem] };
    });
  },

  updateQuantity: (itemId: string, quantity: number) => {
    if (quantity <= 0) {
      get().removeItem(itemId);
      return;
    }

    set((state) => ({
      items: state.items.map((item) =>
        item.id === itemId ? recalcLineTotal(item, quantity) : item,
      ),
    }));
  },

  removeItem: (itemId: string) => {
    set((state) => ({
      items: state.items.filter((item) => item.id !== itemId),
    }));
  },

  clearCart: () => {
    set({ items: [], transactionDiscount: undefined });
  },

  setTransactionDiscount: (discount) => {
    set({ transactionDiscount: discount });
  },

  subtotal: () => {
    return get().items.reduce((sum, item) => sum + parseFloat(item.line_total), 0);
  },

  taxAmount: () => {
    return get().items.reduce(
      (sum, item) => sum + parseFloat(item.tax_amount ?? '0'),
      0,
    );
  },

  total: () => {
    const subtotal = get().subtotal();
    const tax = get().taxAmount();
    const discount = get().transactionDiscount
      ? parseFloat(get().transactionDiscount!.amount)
      : 0;
    return Math.max(0, subtotal + tax - discount);
  },

  itemCount: () => {
    return get().items.reduce((sum, item) => sum + item.quantity, 0);
  },
}));
