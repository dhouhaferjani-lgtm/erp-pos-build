/**
 * Pure line-mapping for the refund settlement flow (Task 53 / Phase 2).
 *
 * Maps the negative `kind: 'return'` cart items hydrated by
 * `hydrateFromReceipt` onto the ORIGINAL receipt's server lines fetched from
 * `GET /pos/receipts/{id}` — producing the `lines[]` payload that
 * `POST /pos/receipts/{id}/return` expects (`line_id` + positive quantity).
 *
 * Duplicate-product receipts (the same product on several lines) are handled
 * by matching the strongest available identity (product/composite id +
 * product_code + unit_price) and consuming each matching line's REMAINING
 * returnable quantity (quantity − returned_quantity) greedily, in server
 * line order.
 *
 * All quantity arithmetic is Big.js decimal math at scale 4 — no float math.
 * No side effects; no API calls; no user-facing strings (typed reasons only).
 */
import type { CartItem } from '@/types/cart';
import { bcabs, bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';

/** Quantity columns are decimal(_, 4) server-side. */
const QTY_SCALE = 4;

/**
 * Server receipt line as returned by `GET /pos/receipts/{id}` (subset the
 * mapper needs). Quantities are scale-4 decimal strings; `returned_quantity`
 * is injected by the controller (default '0.0000').
 */
export interface ServerReceiptLine {
  id: string;
  product_id: string | null;
  composite_item_id?: string | null;
  product_code: string | null;
  product_name: string;
  quantity: string;
  unit_price: string;
  returned_quantity: string;
}

/** One entry of the /return request's `lines[]` array. */
export interface MappedReturnLine {
  line_id: string;
  /** Positive scale-4 decimal string. */
  quantity: string;
}

export type LineMappingFailureReason =
  | 'NO_MATCHING_LINE'
  | 'INSUFFICIENT_RETURNABLE_QUANTITY';

export type LineMappingResult =
  | { ok: true; lines: MappedReturnLine[] }
  | { ok: false; reason: LineMappingFailureReason; itemId: string };

/**
 * Strongest-identity match between a refund cart item and a server line:
 * product identity (product_id, or composite_item_id for composite items,
 * or "both identity-less") AND product_code AND unit_price (numeric compare,
 * not byte compare — '10.00' === '10.0000').
 */
function identityMatches(item: CartItem, line: ServerReceiptLine): boolean {
  if (line.product_code !== item.product.sku) return false;
  if (bccomp(line.unit_price, item.unit_price) !== 0) return false;

  if (item.product.sellableType === 'composite_item') {
    return (line.composite_item_id ?? null) === item.product.id;
  }

  if (line.product_id !== null) {
    return line.product_id === item.product.id;
  }

  // Identity-less server line (no product_id, no composite_item_id) — e.g. a
  // custom line. The hydrated cart item carries a synthetic id in that case,
  // so fall back to the code + price identity already verified above.
  return (line.composite_item_id ?? null) === null;
}

/**
 * Map refund cart items (`kind: 'return'`, negative quantities) onto server
 * receipt lines. Non-return items are ignored. Consumption is tracked across
 * items so two items matching the same server lines cannot jointly exceed a
 * line's remaining returnable quantity. Output entries are merged per
 * line_id (the HTTP contract expects one entry per original line).
 */
export function mapRefundItemsToServerLines(
  items: CartItem[],
  serverLines: ServerReceiptLine[],
): LineMappingResult {
  /** quantity already consumed from each server line during THIS mapping. */
  const consumed = new Map<string, string>();
  /** merged output: line_id → total mapped quantity. */
  const mapped = new Map<string, string>();

  for (const item of items) {
    if (item.kind !== 'return') continue;

    let needed = bcabs(bcformat(item.quantity, QTY_SCALE), QTY_SCALE);
    if (bccomp(needed, '0') === 0) continue;

    const candidates = serverLines.filter((line) => identityMatches(item, line));
    if (candidates.length === 0) {
      return { ok: false, reason: 'NO_MATCHING_LINE', itemId: item.id };
    }

    for (const line of candidates) {
      if (bccomp(needed, '0') === 0) break;

      const alreadyConsumed = consumed.get(line.id) ?? '0.0000';
      const remaining = bcsub(
        bcsub(line.quantity, line.returned_quantity, QTY_SCALE),
        alreadyConsumed,
        QTY_SCALE,
      );
      if (bccomp(remaining, '0') <= 0) continue;

      const take = bccomp(remaining, needed) < 0 ? remaining : needed;
      consumed.set(line.id, bcadd(alreadyConsumed, take, QTY_SCALE));
      mapped.set(line.id, bcadd(mapped.get(line.id) ?? '0.0000', take, QTY_SCALE));
      needed = bcsub(needed, take, QTY_SCALE);
    }

    if (bccomp(needed, '0') > 0) {
      return {
        ok: false,
        reason: 'INSUFFICIENT_RETURNABLE_QUANTITY',
        itemId: item.id,
      };
    }
  }

  // Preserve server line order in the output for determinism.
  const lines: MappedReturnLine[] = [];
  for (const line of serverLines) {
    const quantity = mapped.get(line.id);
    if (quantity !== undefined) {
      lines.push({ line_id: line.id, quantity });
    }
  }

  return { ok: true, lines };
}
