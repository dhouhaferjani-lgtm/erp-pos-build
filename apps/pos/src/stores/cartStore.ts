import { create } from 'zustand';
import type { CartItem, SelectedModifier } from '@/types/cart';
export type { CartItem } from '@/types/cart';
import type { POSProduct, POSProductVariant } from '@/types/product';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bcdiv, bcmul, bcsub, bcsum, bccomp, bcabs } from '@/lib/decimal';
import { useAuthStore } from '@/stores/authStore';
import type { PosOverrideEvidence } from '@/lib/operatorApproval/posOverrideAuthoring';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';

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

export interface LineDiscountInput {
  type: 'percentage' | 'fixed';
  value: string;
  reason?: string;
  approvalEvidence?: PosOverrideEvidence;
}

/**
 * Why the cart is being cleared. Drives the `cart_session_id` lifecycle and the
 * `pos.cart_discarded` audit emit: a `discard` clear is an operator dumping a
 * built-but-untendered sale (fraud-relevant → emitted); every other reason
 * (checkout success, hold, shift close, operator switch) ends the cart without
 * a discard signal. All reasons clear the session id.
 */
export type ClearCartReason = 'discard' | 'checkout' | 'hold' | 'shift_close' | 'operator_switch';

interface CartState {
  items: CartItem[];
  transactionDiscount?: CartTransactionDiscount;
  /**
   * Client-side correlation id for the in-progress sale. Used as the
   * `aggregate_id` of cart-scoped audit events so a discard / line-remove /
   * discount can be correlated. NOT a backend column. Owned like
   * `pendingIdempotencyKey`: generated on the first mutation of an empty cart,
   * regenerated on recall/replace, cleared on checkout/discard/hold/
   * shift-close/operator-switch.
   */
  cartSessionId: string | null;
  /**
   * Count of lines removed from the cart during the current cart session (i.e.
   * since the last `cartSessionId` was generated). Used as the
   * `line_count_removed_before` field in `pos.cart_discarded` — a key fraud
   * signal for "build sale, strip lines, discard" patterns. Reset to 0
   * whenever `cartSessionId` is cleared or regenerated.
   */
  cartLinesRemovedThisSession: number;
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
  /**
   * Clear the cart. `reason` defaults to `'discard'` (the cart's clear button) —
   * the only reason that emits `pos.cart_discarded`. Non-discard callers
   * (checkout success, hold, shift close, operator switch) MUST pass their
   * reason so the discard signal is not falsely emitted. All reasons reset the
   * `cart_session_id`.
   */
  clearCart: (reason?: ClearCartReason) => void;
  /** Apply/replace a line-level discount on a single cart line. */
  applyLineDiscount: (itemId: string, input: LineDiscountInput) => void;
  /** Remove the line-level discount from a single cart line. */
  removeLineDiscount: (itemId: string) => void;
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
  /** Current cart-session correlation id (null when the cart is empty/idle). */
  getCartSessionId: () => string | null;
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
  cartSessionId: null,
  cartLinesRemovedThisSession: 0,
};

/**
 * Returns the current cart-session id, generating one (uuid) when the cart is
 * idle (no id yet). Call at the top of every mutating action so the first
 * mutation of an empty cart opens a session. Pure id management — does not call
 * `set()`; the caller folds the returned id into its own `set()`.
 */
function ensureCartSessionId(current: string | null): string {
  return current ?? crypto.randomUUID();
}

export const useCartStore = create<CartStore>()((set, get) => ({
  ...initialState,

  addItem: (
    product: POSProduct,
    selectedModifiers?: SelectedModifier[],
    variant?: POSProductVariant,
  ) => {
    set((state) => {
      const prevSessionId = state.cartSessionId;
      const cartSessionId = ensureCartSessionId(prevSessionId);
      // If a new session was just generated (first mutation of an empty cart),
      // reset the removed-line counter for this session.
      const cartLinesRemovedThisSession =
        prevSessionId === null ? 0 : state.cartLinesRemovedThisSession;
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
          return { items: newItems, cartSessionId, cartLinesRemovedThisSession };
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
        // The cart-line `sku` stays the PRODUCT sku — the canonical line item
        // keeps the parent identity in `name`/`sku`/`product_id`. Since
        // SaleReceiptV2 (M4) the variant identity travels IN the signed
        // canonical bytes as dedicated `variant_id`/`variant_sku`/
        // `variant_name` line fields (null for non-variant lines), so the
        // sealed record identifies the exact article the ticket printed.
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
        cartProduct.variant_sku = variant.sku;
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

      return { items: [...state.items, newItem], cartSessionId, cartLinesRemovedThisSession };
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

    // Snapshot BEFORE the set() for the audit emit (old qty + line context).
    const existing = get().items.find((item) => item.id === itemId);
    const cartSessionId = get().cartSessionId;

    set((state) => ({
      items: state.items.map((item) =>
        item.id === itemId ? recalcLineTotal(item, quantity) : item,
      ),
    }));

    // Best-effort audit emit OUTSIDE the updater — never throws into the action.
    if (existing) {
      void recordAuditEvent({
        type: 'pos.cart_quantity_updated',
        aggregateType: 'PosSale',
        aggregateId: cartSessionId ?? itemId,
        payload: {
          line_id: itemId,
          product_id: existing.product.id,
          old_qty: existing.quantity,
          new_qty: quantity,
          kind: existing.kind ?? 'sale',
        },
      }).catch(() => {});
    }
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
    // Snapshot the removed line BEFORE the set() for the audit emit.
    const removed = get().items.find((item) => item.id === itemId);
    const cartSessionId = get().cartSessionId;

    set((state) => {
      const nextItems = state.items.filter((item) => item.id !== itemId);
      const lineWasRemoved = nextItems.length < state.items.length;
      // When removing the last line, null the session id (the cart is now empty
      // / idle). A new sale later will get a fresh session id. Also increment
      // the removed-line counter only when a line actually left the cart.
      const nextSessionId = nextItems.length === 0 ? null : state.cartSessionId;
      const nextRemovedCount = lineWasRemoved
        ? (nextItems.length === 0 ? 0 : state.cartLinesRemovedThisSession + 1)
        : state.cartLinesRemovedThisSession;
      return {
        items: nextItems,
        cartSessionId: nextSessionId,
        cartLinesRemovedThisSession: nextRemovedCount,
      };
    });

    if (removed) {
      void recordAuditEvent({
        type: 'pos.cart_line_removed',
        aggregateType: 'PosSale',
        aggregateId: cartSessionId ?? itemId,
        payload: {
          product_id: removed.product.id,
          qty: removed.quantity,
          unit_price: removed.unit_price,
          line_total: removed.line_total,
          kind: removed.kind ?? 'sale',
        },
      }).catch(() => {});
    }
  },

  clearCart: (reason: ClearCartReason = 'discard') => {
    // Snapshot the cart BEFORE clearing — the discard emit needs the pre-clear
    // state, and any reason that ends a non-empty cart should still reset the
    // session id.
    const state = get();
    const hadItems = state.items.length > 0;
    const cartSessionId = state.cartSessionId;
    const lineCount = state.items.length;
    const subtotal = state.subtotal();
    const discountTotal = state.discountAmount();
    const hadReturnItems = state.items.some((i) => (i.kind ?? 'sale') === 'return');
    const linesRemovedBefore = state.cartLinesRemovedThisSession;

    set({ items: [], transactionDiscount: undefined, cartSessionId: null, cartLinesRemovedThisSession: 0 });

    // Only a genuine discard (operator dumping a built sale) is fraud-relevant.
    // Checkout/hold/shift-close/operator-switch end the cart without a discard
    // signal. Never emit for an already-empty cart.
    if (reason === 'discard' && hadItems) {
      void recordAuditEvent({
        type: 'pos.cart_discarded',
        aggregateType: 'PosSale',
        aggregateId: cartSessionId ?? crypto.randomUUID(),
        payload: {
          line_count: lineCount,
          subtotal,
          discount_total: discountTotal,
          had_return_items: hadReturnItems,
          line_count_removed_before: linesRemovedBefore,
        },
      }).catch(() => {});
    }
  },

  applyLineDiscount: (itemId, input) => {
    const decimals = getDecimals();
    const cartSessionId = get().cartSessionId;

    set((state) => ({
      items: state.items.map((item) => {
        if (item.id !== itemId) return item;
        const grossTotal = parseFloat(item.unit_price) * item.quantity;
        let discountAmount = 0;
        if (input.type === 'percentage') {
          discountAmount = (grossTotal * parseFloat(input.value)) / 100;
        } else {
          discountAmount = parseFloat(input.value);
        }
        const lineTotal = Math.max(0, grossTotal - discountAmount);
        return {
          ...item,
          discount_type: input.type,
          discount_percent: input.type === 'percentage' ? input.value : undefined,
          discount_amount: discountAmount.toFixed(decimals),
          discount_reason: input.reason || undefined,
          discount_approval_evidence: input.approvalEvidence,
          line_total: lineTotal.toFixed(decimals),
          tax_amount: computeTaxAmount(lineTotal, item.tax_rate),
        };
      }),
    }));

    const target = get().items.find((item) => item.id === itemId);
    if (target) {
      void recordAuditEvent({
        type: 'pos.line_discount_applied',
        aggregateType: 'PosSale',
        aggregateId: cartSessionId ?? itemId,
        payload: {
          line_id: itemId,
          product_id: target.product.id,
          discount_type: input.type,
          discount_amount: target.discount_amount ?? null,
          discount_percent: input.type === 'percentage' ? input.value : null,
          has_approval_evidence: input.approvalEvidence !== undefined,
        },
      }).catch(() => {});
    }
  },

  removeLineDiscount: (itemId) => {
    const decimals = getDecimals();
    set((state) => ({
      items: state.items.map((item) => {
        if (item.id !== itemId) return item;
        const grossTotal = parseFloat(item.unit_price) * item.quantity;
        return {
          ...item,
          discount_type: undefined,
          discount_percent: undefined,
          discount_amount: undefined,
          discount_reason: undefined,
          discount_approval_evidence: undefined,
          line_total: grossTotal.toFixed(decimals),
          tax_amount: computeTaxAmount(grossTotal, item.tax_rate),
        };
      }),
    }));
  },

  setTransactionDiscount: (discount) => {
    const cartSessionId = get().cartSessionId;
    set({ transactionDiscount: discount });

    // Emit only when SETTING a discount, not when clearing it.
    if (discount) {
      void recordAuditEvent({
        type: 'pos.transaction_discount_applied',
        aggregateType: 'PosSale',
        aggregateId: cartSessionId ?? crypto.randomUUID(),
        payload: {
          discount_type: discount.type,
          discount_amount: discount.type === 'fixed' ? discount.value : null,
          discount_percent: discount.type === 'percentage' ? discount.value : null,
          reason: discount.reason ?? null,
          has_approval_evidence: discount.approvalEvidence !== undefined,
        },
      }).catch(() => {});
    }
  },

  replaceCart: (items, transactionDiscount) => {
    // A recalled / replaced cart is a NEW correlation — regenerate the session
    // and reset the removed-line counter.
    set({ items, transactionDiscount, cartSessionId: crypto.randomUUID(), cartLinesRemovedThisSession: 0 });
  },

  replaceReturnItems: (newReturnItems) => {
    set((state) => ({
      items: [
        ...state.items.filter((i) => (i.kind ?? 'sale') !== 'return'),
        ...newReturnItems,
      ],
      // Replacing the return section is a new correlation — regenerate and reset
      // the removed-line counter.
      cartSessionId: crypto.randomUUID(),
      cartLinesRemovedThisSession: 0,
    }));
  },

  clearReturnItems: () => {
    set((state) => {
      const nextItems = state.items.filter((i) => (i.kind ?? 'sale') !== 'return');
      // If clearing return items empties the cart entirely, null the session id.
      const nextSessionId = nextItems.length === 0 ? null : state.cartSessionId;
      const nextRemovedCount = nextItems.length === 0 ? 0 : state.cartLinesRemovedThisSession;
      return {
        items: nextItems,
        cartSessionId: nextSessionId,
        cartLinesRemovedThisSession: nextRemovedCount,
      };
    });
  },

  addReturnItems: (newItems) => {
    set((state) => {
      const prevSessionId = state.cartSessionId;
      const cartSessionId = ensureCartSessionId(prevSessionId);
      // If this is the first mutation of an empty cart (new session), reset the
      // removed-line counter.
      const cartLinesRemovedThisSession =
        prevSessionId === null ? 0 : state.cartLinesRemovedThisSession;
      return {
        items: [...state.items, ...newItems],
        cartSessionId,
        cartLinesRemovedThisSession,
      };
    });
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

  getCartSessionId: () => get().cartSessionId,
}));
