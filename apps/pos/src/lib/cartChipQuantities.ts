import { formatAvailableQty } from '@/lib/stock/stockGate';
import type { CartItem } from '@/types/cart';

/**
 * Display formatter for the in-cart chip quantity (adversarial review
 * Minor 3). The input is already a NUMBER (`CartItem.quantity`) — this never
 * parses a string (precision contract: no parseFloat/Number() on quantities).
 *   - ≥ 100 collapses to '99+' so the pill cannot crawl across the 72px
 *     vitrine tile;
 *   - integers render as-is;
 *   - fractional quantities (float summing can yield 0.30000000000000004)
 *     are pinned to the 4dp quantity scale via toFixed(4), then trailing
 *     zeros are stripped by the existing `formatAvailableQty` trimmer
 *     ('2.5000' → '2.5').
 */
export function formatChipQuantity(quantity: number): string {
  if (quantity >= 100) {
    return '99+';
  }
  if (Number.isInteger(quantity)) {
    return String(quantity);
  }
  return formatAvailableQty(quantity.toFixed(4));
}

/**
 * Per-product quantity map for the ProductCard in-cart count chip
 * (HomePage `cartQuantities` memo).
 *
 * Chip display semantics (adversarial review Major 2): the chip shows units
 * being SOLD. Only sale-kind lines are summed — store convention is
 * `(item.kind ?? 'sale') === 'sale'` (see cartStore selectors). Exchange-flow
 * return lines carry NEGATIVE quantities (`hydrateFromReceipt`:
 * `-Math.abs(...)`) and live in the same `items` array; summing them would
 * let the chip display "-1", "0", or net-wrong counts mid-exchange.
 * Excluding them means:
 *   - return-only product → NO map entry → the chip falls back to its
 *     presence-only check glyph (driven by `isInCart`), never a negative;
 *   - sale 3 + return 1 of the same product → chip shows "3".
 * `cartProductIds` (the isInCart border) intentionally stays UNFILTERED —
 * pre-existing behavior, out of scope here.
 *
 * Display only — plain JS number addition mirrors the cart store's own
 * quantity arithmetic (`CartItem.quantity` is a number); never money math,
 * no parseFloat/Number() parsing (precision contract).
 */
export function sumSaleQuantities(items: readonly CartItem[]): Record<string, number> {
  const map: Record<string, number> = {};
  for (const item of items) {
    if ((item.kind ?? 'sale') !== 'sale') continue;
    map[item.product.id] = (map[item.product.id] ?? 0) + item.quantity;
  }
  return map;
}
