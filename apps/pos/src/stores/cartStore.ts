import { create } from 'zustand';
import type { CartItem, SelectedModifier } from '@/types/cart';
import type { POSProduct, POSProductVariant } from '@/types/product';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bcdiv, bcmul, bcsub, bcsum, bccomp, bcabs } from '@/lib/decimal';
import { useAuthStore } from '@/stores/authStore';
import type { PosOverrideEvidence } from '@/lib/operatorApproval/posOverrideAuthoring';

// Quantities carry up to 4 decimal places (weight/volume sales); money carries
// the currency scale. Multiply at quantity precision so a fractional qty never
// loses resolution before the currency-scale rounding at the line-total step.
const QUANTITY_SCALE = 4;

export interface CartTransactionDiscount {
  type: 'percentage' | 'fixed';
  value: string;
  reason?: string;
  approvalEvidence?: PosOverrideEvidence;
}

interface CartState {
  items: CartItem[];
  transactionDiscount?: CartTransactionDiscount;
}

interface CartActions {
  /**
   * Add a product to the cart.
   *
   * T2 — when `variant` is supplied the line carries that variant's identity
   * (`variant_id` / `variant_name`) and is priced at the variant's
   * `price_override` (falling back to the product `sale_price`). Variant lines
   * dedupe on `(product.id, variant_id)` so two distinct variants of the same
   * product stay as separate lines while re-adding the same variant
   * increments. The variant carries NO cost into the cart — inventory WAC
   * stays product-grain (spec §6.7) and the fiscal payload is untouched.
   */
  addItem: (
    product: POSProduct,
    selectedModifiers?: SelectedModifier[],
    variant?: POSProductVariant,
  ) => void;
  addItemWithDefaults: (product: POSProduct) => void;
  updateQuantity: (itemId: string, quantity: number) => void;
  updateLineModifiers: (lineId: string, newModifiers: SelectedModifier[]) => void;
  removeItem: (itemId: string) => void;
  clearCart: () => void;
  setTransactionDiscount: (discount: CartTransactionDiscount | undefined) => void;
  replaceCart: (
    items: CartItem[],
    transactionDiscount: CartTransactionDiscount | undefined,
  ) => void;
  /**
   * Atomically replace ALL return-kind items with `newReturnItems`.
   * Used by Task 52 hydration — replaces the whole Returning section in one shot.
   */
  replaceReturnItems: (newReturnItems: CartItem[]) => void;
  /** Remove all return-kind items (cancel the refund section). */
  clearReturnItems: () => void;
  /**
   * Append additional return-kind items (without clearing existing ones).
   * Prefer `replaceReturnItems` for initial hydration.
   */
  addReturnItems: (items: CartItem[]) => void;
}

interface CartDerived {
  subtotal: () => number;
  /** Exact subtotal as a currency-scale decimal string (no float boundary). */
  subtotalString: () => string;
  taxAmount: () => number;
  discountAmount: () => number;
  /** Exact transaction-discount amount as a currency-scale decimal string. */
  discountAmountString: () => string;
  total: () => number;
  itemCount: () => number;
  /** Items whose kind is 'return' (or normalised to 'return'). */
  returnItems: () => CartItem[];
  /** Items whose kind is 'sale' or undefined (default). */
  saleItems: () => CartItem[];
  /**
   * Net = sum(saleItems line_total) − abs(sum(returnItems line_total)).
   * Positive → cashier collects money; negative → cashier owes a refund.
   */
  netTotal: () => number;
}

type CartStore = CartState & CartActions & CartDerived;

function getDecimals(): number {
  const state = useAuthStore.getState();
  const company = state.companies.find((c) => c.id === state.companyId);
  return getCurrencyDecimals(company?.currency ?? 'EUR');
}

export function computeTaxAmount(lineTotal: number | string, taxRate: string): string {
  const decimals = getDecimals();
  if (bccomp(taxRate, '0') <= 0) return (0).toFixed(decimals);
  // Tax-inclusive: extract tax from price that already includes it.
  //   net = lineTotal / (1 + rate/100); tax = lineTotal - net
  // Big.js arithmetic — no IEEE-754 drift on the division.
  const lineTotalStr = typeof lineTotal === 'number' ? String(lineTotal) : lineTotal;
  const factor = bcadd('1', bcdiv(taxRate, '100', QUANTITY_SCALE), QUANTITY_SCALE);
  const net = bcdiv(lineTotalStr, factor, decimals);
  return bcsub(lineTotalStr, net, decimals);
}

function recalcLineTotal(item: CartItem, newQty: number): CartItem {
  const decimals = getDecimals();
  // unit_price is a money string; newQty may be fractional (scale 4). Multiply
  // at quantity precision, then round the line total to the currency scale.
  const grossTotal = bcmul(item.unit_price, String(newQty), decimals);
  let discountAmount = (0).toFixed(decimals);

  if (item.discount_type === 'percentage' && item.discount_percent) {
    discountAmount = bcdiv(
      bcmul(grossTotal, item.discount_percent, decimals),
      '100',
      decimals,
    );
  } else if (item.discount_amount && item.discount_type === 'fixed') {
    discountAmount = item.discount_amount;
  }

  // For sale lines (positive qty) clamp to 0 so discounts never invert the total.
  // For return lines (negative qty) the raw signed value is correct — do not clamp.
  const rawTotal = bcsub(grossTotal, discountAmount, decimals);
  const lineTotal =
    newQty >= 0 && bccomp(rawTotal, '0') < 0 ? (0).toFixed(decimals) : rawTotal;

  return {
    ...item,
    quantity: newQty,
    ...(item.discount_type === 'percentage'
      ? { discount_amount: discountAmount }
      : {}),
    line_total: lineTotal,
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

  addItem: (
    product: POSProduct,
    selectedModifiers?: SelectedModifier[],
    variant?: POSProductVariant,
  ) => {
    set((state) => {
      const hasModifiers = selectedModifiers && selectedModifiers.length > 0;

      // For items without modifiers, try to find and increment existing.
      // A variant line only merges with another line of the SAME variant of
      // the SAME product — distinct variants stay as distinct lines.
      if (!hasModifiers) {
        const existingIndex = state.items.findIndex(
          (item) =>
            item.product.id === product.id &&
            !item.product.selectedModifiers?.length &&
            (item.product.variant_id ?? null) === (variant?.id ?? null),
        );

        if (existingIndex !== -1) {
          const existing = state.items[existingIndex]!;
          const updated = recalcLineTotal(existing, existing.quantity + 1);
          const newItems = [...state.items];
          newItems[existingIndex] = updated;
          return { items: newItems };
        }
      }

      // Add new item. When a variant is supplied, its `price_override` (when
      // set) replaces the product base price; otherwise the product
      // `sale_price` is used. Modifier adjustments stack on top either way.
      const decimals = getDecimals();
      const variantBasePrice =
        variant && variant.price_override != null && variant.price_override !== ''
          ? variant.price_override
          : null;
      const basePrice = variantBasePrice ?? product.sale_price ?? '0';
      const modifierAdjustment = hasModifiers
        ? bcsum(selectedModifiers.map((m) => m.price_adjustment), decimals)
        : (0).toFixed(decimals);
      const priceValue = bcadd(basePrice, modifierAdjustment, decimals);

      const cartProduct: CartItem['product'] = {
        id: product.id,
        // The cart-line `sku` stays the PRODUCT sku so the fiscal canonical
        // payload (which reads `product.sku`) is byte-identical to a
        // no-variant sale — fiscal bytes are never touched by variant
        // selection. The variant identity (and thus the variant sku) is
        // carried out-of-band via `variant_id`; the server recovers the
        // variant sku from it.
        name: product.name,
        sku: product.sku,
        price: priceValue,
        ...(product.sellableType ? { sellableType: product.sellableType } : {}),
      };
      if (hasModifiers) {
        cartProduct.selectedModifiers = selectedModifiers;
      }
      if (variant) {
        cartProduct.variant_id = variant.id;
        cartProduct.variant_name = `${product.name}${variant.name_suffix}`;
      }

      const taxRate = product.tax_rate ?? '0';
      const newItem: CartItem = {
        id: crypto.randomUUID(),
        product: cartProduct,
        quantity: 1,
        unit_price: priceValue,
        line_total: priceValue,
        tax_rate: taxRate,
        tax_amount: computeTaxAmount(priceValue, taxRate),
      };

      return { items: [...state.items, newItem] };
    });
  },

  addItemWithDefaults: (product: POSProduct) => {
    const defaults = resolveDefaultModifiers(product);
    get().addItem(product, defaults.length > 0 ? defaults : undefined);
  },

  updateQuantity: (itemId: string, quantity: number) => {
    if (quantity === 0) {
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
        const currentAdjustment = bcsum(
          item.product.selectedModifiers?.map((m) => m.price_adjustment) ?? [],
          decimals,
        );
        const basePrice = bcsub(item.product.price, currentAdjustment, decimals);
        const newAdjustment = bcsum(newModifiers.map((m) => m.price_adjustment), decimals);
        const priceValue = bcadd(basePrice, newAdjustment, decimals);
        const lineTotal = bcmul(priceValue, String(item.quantity), decimals);
        return {
          ...item,
          product: { ...item.product, selectedModifiers: newModifiers, price: priceValue },
          unit_price: priceValue,
          line_total: lineTotal,
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

  replaceCart: (items, transactionDiscount) => {
    set({ items, transactionDiscount });
  },

  replaceReturnItems: (newReturnItems) => {
    set((state) => ({
      items: [
        ...state.items.filter((i) => (i.kind ?? 'sale') !== 'return'),
        ...newReturnItems,
      ],
    }));
  },

  clearReturnItems: () => {
    set((state) => ({
      items: state.items.filter((i) => (i.kind ?? 'sale') !== 'return'),
    }));
  },

  addReturnItems: (newItems) => {
    set((state) => ({
      items: [...state.items, ...newItems],
    }));
  },

  // Derived selectors keep their `number` return type (external store shape),
  // but aggregate with Big.js internally so summing many line totals / a
  // fractional-qty cart cannot accumulate IEEE-754 drift. The float boundary
  // is the final `Number(...)` only, on an already currency-scale-rounded
  // string.

  subtotalString: () => {
    const decimals = getDecimals();
    return bcsum(get().items.map((item) => item.line_total), decimals);
  },

  subtotal: () => {
    return Number(get().subtotalString());
  },

  taxAmount: () => {
    const decimals = getDecimals();
    const rawTax = bcsum(get().items.map((item) => item.tax_amount ?? '0'), decimals);
    // Adjust tax proportionally for transaction discount.
    const subtotal = get().subtotalString();
    const discount = get().discountAmountString();
    if (bccomp(discount, '0') > 0 && bccomp(subtotal, '0') > 0) {
      const net = bcsub(subtotal, discount, decimals);
      const ratio = bccomp(net, '0') > 0 ? bcdiv(net, subtotal, QUANTITY_SCALE) : '0';
      return Number(bcmul(rawTax, ratio, decimals));
    }
    return Number(rawTax);
  },

  discountAmountString: () => {
    const decimals = getDecimals();
    const discount = get().transactionDiscount;
    if (!discount) return (0).toFixed(decimals);
    const subtotal = get().subtotalString();
    const value = discount.value && discount.value.trim() !== '' ? discount.value : '0';
    const raw =
      discount.type === 'percentage'
        ? bcdiv(bcmul(subtotal, value, decimals), '100', decimals)
        : value;
    // Never discount more than the subtotal.
    return bccomp(raw, subtotal) > 0 ? subtotal : raw;
  },

  discountAmount: () => {
    return Number(get().discountAmountString());
  },

  total: () => {
    const decimals = getDecimals();
    const total = bcsub(get().subtotalString(), get().discountAmountString(), decimals);
    return bccomp(total, '0') < 0 ? 0 : Number(total);
  },

  itemCount: () => {
    return get().items.reduce((sum, item) => sum + item.quantity, 0);
  },

  returnItems: () => {
    return get().items.filter((i) => (i.kind ?? 'sale') === 'return');
  },

  saleItems: () => {
    return get().items.filter((i) => (i.kind ?? 'sale') === 'sale');
  },

  netTotal: () => {
    const decimals = getDecimals();
    const items = get().items;
    const saleTotal = bcsum(
      items
        .filter((i) => (i.kind ?? 'sale') === 'sale')
        .map((i) => i.line_total),
      decimals,
    );
    const returnTotal = bcsum(
      items
        .filter((i) => (i.kind ?? 'sale') === 'return')
        .map((i) => bcabs(i.line_total, decimals)),
      decimals,
    );

    // Apply transaction discount against the net (discount applies to saleItems only).
    const net = bcsub(saleTotal, returnTotal, decimals);
    return Number(bcsub(net, get().discountAmountString(), decimals));
  },
}));
