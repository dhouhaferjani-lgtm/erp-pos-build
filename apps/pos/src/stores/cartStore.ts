import { create } from 'zustand';
import type { CartItem, SelectedModifier } from '@/types/cart';
import type { POSProduct } from '@/types/product';
import { getCurrencyDecimals } from '@/lib/currency';
import { useAuthStore } from '@/stores/authStore';

interface CartState {
  items: CartItem[];
  transactionDiscount?: { amount: string; reason?: string };
}

interface CartActions {
  addItem: (product: POSProduct, selectedModifiers?: SelectedModifier[]) => void;
  addItemWithDefaults: (product: POSProduct) => void;
  updateQuantity: (itemId: string, quantity: number) => void;
  updateLineModifiers: (lineId: string, newModifiers: SelectedModifier[]) => void;
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

function getDecimals(): number {
  const state = useAuthStore.getState();
  const company = state.companies.find((c) => c.id === state.companyId);
  return getCurrencyDecimals(company?.currency ?? 'EUR');
}

export function computeTaxAmount(lineTotal: number, taxRate: string): string {
  const decimals = getDecimals();
  const rate = parseFloat(taxRate);
  if (rate <= 0) return (0).toFixed(decimals);
  const tax = lineTotal * rate / 100;
  return tax.toFixed(decimals);
}

function recalcLineTotal(item: CartItem, newQty: number): CartItem {
  const decimals = getDecimals();
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
      ? { discount_amount: discountAmount.toFixed(decimals) }
      : {}),
    line_total: lineTotal.toFixed(decimals),
    tax_amount: computeTaxAmount(lineTotal, item.tax_rate),
  };
}

function resolveDefaultModifiers(product: POSProduct): SelectedModifier[] {
  if (!product.modifier_groups?.length) return [];
  const defaults: SelectedModifier[] = [];
  for (const group of product.modifier_groups) {
    const activeModifiers = group.modifiers.filter((m) => m.is_active);
    const defaultMods = activeModifiers.filter((m) => m.is_default);
    if (defaultMods.length > 0) {
      for (const mod of defaultMods) {
        defaults.push({
          modifier_id: mod.id,
          modifier_group_id: group.id,
          name: mod.name,
          group_name: group.name,
          price_adjustment: mod.price_adjustment,
        });
      }
    } else if (group.is_required && activeModifiers.length > 0) {
      const first = activeModifiers[0]!;
      defaults.push({
        modifier_id: first.id,
        modifier_group_id: group.id,
        name: first.name,
        group_name: group.name,
        price_adjustment: first.price_adjustment,
      });
    }
  }
  return defaults;
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
      const decimals = getDecimals();
      const priceValue = unitPrice.toFixed(decimals);

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

      const taxRate = product.tax_rate ?? '0';
      const newItem: CartItem = {
        id: crypto.randomUUID(),
        product: cartProduct,
        quantity: 1,
        unit_price: priceValue,
        line_total: unitPrice.toFixed(decimals),
        tax_rate: taxRate,
        tax_amount: computeTaxAmount(unitPrice, taxRate),
      };

      return { items: [...state.items, newItem] };
    });
  },

  addItemWithDefaults: (product: POSProduct) => {
    const defaults = resolveDefaultModifiers(product);
    get().addItem(product, defaults.length > 0 ? defaults : undefined);
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

  updateLineModifiers: (lineId: string, newModifiers: SelectedModifier[]) => {
    set((state) => ({
      items: state.items.map((item) => {
        if (item.id !== lineId) return item;
        const decimals = getDecimals();
        const basePrice = parseFloat(item.product.price) -
          (item.product.selectedModifiers?.reduce((s, m) => s + parseFloat(m.price_adjustment), 0) ?? 0);
        const newAdjustment = newModifiers.reduce((s, m) => s + parseFloat(m.price_adjustment), 0);
        const newUnitPrice = basePrice + newAdjustment;
        const priceValue = newUnitPrice.toFixed(decimals);
        const lineTotal = newUnitPrice * item.quantity;
        return {
          ...item,
          product: { ...item.product, selectedModifiers: newModifiers, price: priceValue },
          unit_price: priceValue,
          line_total: lineTotal.toFixed(decimals),
          tax_amount: computeTaxAmount(lineTotal, item.tax_rate),
        };
      }),
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
