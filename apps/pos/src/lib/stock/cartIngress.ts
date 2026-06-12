/**
 * Task 11 — gated cart-ingress funnel (the ONLY UI entry into the cart's
 * add / quantity-increase actions; L9: one guard, every ingress).
 *
 * The cart store API stays synchronous; this module is the async resolver
 * boundary in front of it: gate → toast → store action. Call sites
 * (HomePage handlers) fire these with `void` and never call the raw store
 * actions themselves — pinned structurally by homePageIngressPin.test.ts.
 *
 * Toasts use the app's established sonner + lib-level `i18n.t` idioms
 * (Header.tsx / api.ts). The Toaster mount lives in App.tsx (MainApp).
 *
 * Decrease paths and return-kind lines are NEVER gated: reducing a sale
 * line or growing a return never consumes stock.
 */
import { toast } from 'sonner';
import i18n from '@/lib/i18n';
import type { POSProduct, POSProductVariant } from '@/types/product';
import type { CartItem, SelectedModifier } from '@/types/cart';
import { bcsub } from '@/lib/decimal';
import { useCartStore } from '@/stores/cartStore';
import { gateStockForAdd, formatAvailableQty, type StockGateResult } from './stockGate';

/** Quantity scale — ALWAYS pass explicitly (decimal.ts defaults to 3). */
const QTY_SCALE = 4;

function surfaceGateToast(result: StockGateResult): void {
  if (!result.ok) {
    toast.error(
      i18n.t('pos:stock.blocked', { available: formatAvailableQty(result.available) }),
    );
  } else if (result.warn) {
    toast.warning(
      i18n.t('pos:stock.warned', { available: formatAvailableQty(result.available) }),
    );
  }
}

export interface AddItemGatedOptions {
  /** Explicit modifier selections (modifier modal confirm). */
  selectedModifiers?: SelectedModifier[];
  /** Picked variant (variant-picker confirm). */
  variant?: POSProductVariant;
  /** Route through `addItemWithDefaults` (tile tap on a modifier product). */
  withDefaults?: boolean;
}

/**
 * Gate then add one unit of (product, variant) to the cart.
 * Returns whether the line was actually added (false = blocked).
 */
export async function addItemGated(
  product: POSProduct,
  options: AddItemGatedOptions = {},
): Promise<boolean> {
  const result = await gateStockForAdd(
    product,
    options.variant?.id ?? null,
    '1',
    useCartStore.getState().items,
  );
  surfaceGateToast(result);
  if (!result.ok) {
    return false;
  }

  const cart = useCartStore.getState();
  if (options.withDefaults) {
    cart.addItemWithDefaults(product);
  } else {
    cart.addItem(product, options.selectedModifiers, options.variant);
  }
  return true;
}

/**
 * Fallback product slice when the catalog lookup misses (e.g. a recalled
 * hold whose product left the in-memory snapshot, or an API-fetched
 * recommendation). Keeps id + sellableType so the exemption rules still
 * apply; `is_physical` is absent → treated as physical (fail toward
 * enforcement, mirroring the availability selector's rule).
 */
function productSliceFromCartItem(item: CartItem): POSProduct {
  return {
    id: item.product.id,
    name: item.product.name,
    sku: item.product.sku,
    sale_price: item.unit_price,
    stock_quantity: 0,
    ...(item.product.sellableType !== undefined
      ? { sellableType: item.product.sellableType }
      : {}),
  };
}

/**
 * Gate then apply a cart-line quantity change.
 *
 * Only an INCREASE on a sale-kind line is gated — and only for the DELTA
 * (the availability selector already subtracts the line's current cart
 * quantity). Decreases, removals (qty 0) and return-kind lines pass straight
 * through to the store.
 *
 * @param resolveProduct  Catalog lookup (productStore) so the gate sees the
 *                        full product (is_physical / sellable_id); falls back
 *                        to a slice built from the cart line.
 */
export async function updateQuantityGated(
  itemId: string,
  newQuantity: number,
  resolveProduct: (productId: string) => POSProduct | undefined,
): Promise<boolean> {
  const cart = useCartStore.getState();
  const item = cart.items.find((line) => line.id === itemId);
  if (!item) {
    return false;
  }

  const isReturnLine = (item.kind ?? 'sale') === 'return';
  if (isReturnLine || newQuantity <= item.quantity) {
    cart.updateQuantity(itemId, newQuantity);
    return true;
  }

  // No float math on quantities: delta via decimal strings at scale 4.
  const requestedDelta = bcsub(String(newQuantity), String(item.quantity), QTY_SCALE);
  const product = resolveProduct(item.product.id) ?? productSliceFromCartItem(item);

  const result = await gateStockForAdd(
    product,
    item.product.variant_id ?? null,
    requestedDelta,
    cart.items,
  );
  surfaceGateToast(result);
  if (!result.ok) {
    return false;
  }

  useCartStore.getState().updateQuantity(itemId, newQuantity);
  return true;
}
