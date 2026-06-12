/**
 * Task 10 — the availability selector: single source of truth for location
 * sellability on the POS (spec §4.4).
 *
 *   effectiveAvailable =
 *     serverAvailable (missing row ⇒ 0)
 *     − Σ unsynced-offline-receipt sale quantities for (product, variant)
 *     − Σ current-cart sale quantities for (product, variant)
 *   clamped ≥ 0.
 *
 * `null` means "not stock-managed" (exempt): non-product sellables
 * (Menu/composite items). Snapshot changes never evict cart lines — this
 * selector only gates FURTHER adds (Tasks 11/12 consume it).
 *
 * Exemption gap — `is_physical`: POSProduct (src/types/product.ts) does NOT
 * carry a physicality flag locally (the products sync does not project it),
 * so the spec's "non-physical product" exemption cannot be evaluated on the
 * terminal today. The only locally-available discriminator is
 * `sellableType` ('product' | 'composite_item'); when the local catalog
 * gains an `is_physical` projection, add `is_physical === false ⇒ null`
 * here and in `AvailabilityInputs.product`.
 *
 * Refunds/returns NEVER touch availability: refund records live in the
 * separate `local_refund_records` table (not read here), cart lines with
 * `kind: 'return'` are skipped, and a defensive non-positive-quantity filter
 * drops any negative line so an unsynced refund can never ADD availability
 * back before the server confirms it.
 *
 * All quantities are decimal STRINGS at scale 4 — no float ever touches a
 * quantity (persisted line/cart quantities are JSON numbers authored by the
 * cart; they are converted via `String(...)`, never parsed to float).
 */
import type Database from '@tauri-apps/plugin-sql';
import { bccomp, bcsub, bcsum } from '@/lib/decimal';
import { getStockFor } from '@/lib/db/repositories/locationStockRepository';
import { getUnsyncedReceiptLineBlobs } from '@/lib/db/repositories/offlineReceiptRepository';
import type { POSProduct } from '@/types/product';
import type { CartItem } from '@/types/cart';

/** Quantity scale — ALWAYS pass explicitly (decimal.ts defaults to 3). */
const QTY_SCALE = 4;

const ZERO = '0.0000';

// ──────────────────────────────────────────────────────────────────────────────
// Pure core
// ──────────────────────────────────────────────────────────────────────────────

export interface AvailabilityInputs {
  /**
   * Only `sellableType` is needed for the exemption rules the POS can
   * evaluate locally (see the `is_physical` gap note above).
   */
  product: Pick<POSProduct, 'sellableType'>;
  variantId: string | null;
  /** Local `location_stock` row; `null` when the location has no row. */
  stockRow: { available: string } | null;
  /** Σ unsynced offline-receipt sale quantities for (product, variant). */
  pendingSaleQty: string;
  /** Σ current-cart sale quantities for (product, variant). */
  cartQty: string;
}

/**
 * Pure availability computation. Returns a scale-4 decimal string, or `null`
 * when the product is not stock-managed (exempt).
 */
export function effectiveAvailable(input: AvailabilityInputs): string | null {
  // Exemption: non-product sellables (Menu/composite) are not stock-managed.
  // An absent sellableType means a standard product row (stock-managed).
  if (input.product.sellableType !== undefined && input.product.sellableType !== 'product') {
    return null;
  }

  const serverAvailable = input.stockRow?.available ?? '0';
  const afterPending = bcsub(serverAvailable, input.pendingSaleQty, QTY_SCALE);
  const afterCart = bcsub(afterPending, input.cartQty, QTY_SCALE);

  return bccomp(afterCart, '0') < 0 ? ZERO : afterCart;
}

// ──────────────────────────────────────────────────────────────────────────────
// Assembler
// ──────────────────────────────────────────────────────────────────────────────

/**
 * The slice of a cart line the selector needs. Structurally satisfied by
 * `CartItem` (src/types/cart.ts), so callers can pass
 * `useCartStore.getState().items` directly.
 */
export type AvailabilityCartLine = Pick<CartItem, 'quantity' | 'kind'> & {
  product: Pick<CartItem['product'], 'id' | 'variant_id'>;
};

/**
 * Persisted offline-receipt line, as authored by
 * `receiptService.createOfflineReceipt` (lines JSON blob):
 * `product_id` is absent for composite lines, `variant_id` is absent for
 * non-variant lines, and `quantity` is a JSON number.
 */
interface ParsedPendingLine {
  productId: string;
  /** '' = product grain (mirrors the location_stock sentinel). */
  variantKey: string;
  /** Decimal string — converted from the JSON value without float parsing. */
  quantity: string;
}

/** Plain decimal validation for defensively-accepted string quantities. */
const DECIMAL_RE = /^-?\d+(\.\d+)?$/;

function parsePendingLines(blob: string): ParsedPendingLine[] {
  let parsed: unknown;
  try {
    parsed = JSON.parse(blob);
  } catch {
    return [];
  }
  if (!Array.isArray(parsed)) return [];

  const lines: ParsedPendingLine[] = [];
  for (const entry of parsed) {
    if (typeof entry !== 'object' || entry === null) continue;
    const record = entry as Record<string, unknown>;

    // Composite lines persist `composite_item_id` instead — exempt sellables,
    // never stock-managed, so lines without a product_id are skipped.
    const productId = record['product_id'];
    if (typeof productId !== 'string' || productId === '') continue;

    const variantRaw = record['variant_id'];
    const variantKey = typeof variantRaw === 'string' ? variantRaw : '';

    const quantityRaw = record['quantity'];
    let quantity: string;
    if (typeof quantityRaw === 'number' && Number.isFinite(quantityRaw)) {
      quantity = String(quantityRaw);
    } else if (typeof quantityRaw === 'string' && DECIMAL_RE.test(quantityRaw.trim())) {
      quantity = quantityRaw.trim();
    } else {
      continue;
    }

    lines.push({ productId, variantKey, quantity });
  }
  return lines;
}

/**
 * Async assembler used by Tasks 11/12: reads the local stock snapshot and the
 * unsynced offline receipts, sums the (product, variant) cart lines, and
 * funnels everything through the pure core.
 *
 * C2 Menu-mode note: `product.id` may be a composite id while server stock
 * (and `location_stock`) is keyed by the bare sellable UUID carried in
 * `product.sellable_id`. The stock lookup uses the bare id; pending/cart line
 * matching accepts EITHER id, since local receipt lines and cart lines
 * persist the local (possibly composite) cart product id.
 */
export async function getEffectiveAvailable(
  db: Database,
  product: POSProduct,
  variantId: string | null,
  cartLines: ReadonlyArray<AvailabilityCartLine>,
): Promise<string | null> {
  // Exempt sellables short-circuit before any DB read.
  if (product.sellableType !== undefined && product.sellableType !== 'product') {
    return null;
  }

  // '' and null both mean the product grain (location_stock sentinel is '').
  const variantKey = variantId ?? '';
  const bareProductId = product.sellable_id ?? product.id;
  const matchingProductIds = new Set([product.id, bareProductId]);

  const stockRow = await getStockFor(db, bareProductId, variantKey);

  // Σ unsynced offline-receipt sale quantities for (product, variant).
  // Non-positive quantities are dropped: only sales subtract, and a negative
  // (refund-style) line must never add availability back (spec §4.4).
  const blobs = await getUnsyncedReceiptLineBlobs(db);
  const pendingQuantities: string[] = [];
  for (const blob of blobs) {
    for (const line of parsePendingLines(blob)) {
      if (!matchingProductIds.has(line.productId)) continue;
      if (line.variantKey !== variantKey) continue;
      if (bccomp(line.quantity, '0') <= 0) continue;
      pendingQuantities.push(line.quantity);
    }
  }
  const pendingSaleQty = bcsum(pendingQuantities, QTY_SCALE);

  // Σ current-cart sale quantities for (product, variant) — return-kind
  // lines (refund/exchange "Returning" section) never count.
  const cartQuantities: string[] = [];
  for (const line of cartLines) {
    if ((line.kind ?? 'sale') === 'return') continue;
    if (!matchingProductIds.has(line.product.id)) continue;
    if ((line.product.variant_id ?? '') !== variantKey) continue;
    cartQuantities.push(String(line.quantity));
  }
  const cartQty = bcsum(cartQuantities, QTY_SCALE);

  return effectiveAvailable({
    product,
    variantId: variantKey === '' ? null : variantKey,
    stockRow: stockRow ? { available: stockRow.available } : null,
    pendingSaleQty,
    cartQty,
  });
}
