/**
 * SaleReceiptV2 — `event_version = 2` canonical SALE_RECEIPT payload (M4).
 *
 * V2 is a STRICT SUPERSET of V1: each `line_items[]` row gains
 * `variant_id` / `variant_name` / `variant_sku` (all `null` for
 * non-variant lines). The V1 builder (`buildSaleReceiptPayload`) is NEVER
 * mutated — Events are Immutable Forever — so V2 delegates to it and then
 * enriches the line items positionally from the same cart lines. Every V1
 * arithmetic/aggregate invariant therefore still runs for V2.
 *
 * Why: for a variant sale the V1 signed line carried the PARENT
 * name/sku/product_id at the VARIANT price, so the printed ticket (which
 * shows the variant) did not match the sealed record. Under NF525 the
 * signed record is the legal artefact; V2 folds the exact article sold
 * into the canonical bytes.
 */

import type { LineItemInput, SaleReceiptPayloadInput } from '@/lib/fiscal/FiscalEventEngine';
import {
  buildSaleReceiptPayload,
  SaleReceiptPayloadInputError,
  type BuildSaleReceiptPayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';

export interface LineItemV2Input extends LineItemInput {
  /** Concrete variant sold; null for non-variant lines. */
  readonly variant_id: string | null;
  /** Variant display label (product name + variant suffix); null for non-variant lines. */
  readonly variant_name: string | null;
  /** Variant SKU (what the ticket prints); null for non-variant lines. */
  readonly variant_sku: string | null;
}

export interface SaleReceiptV2PayloadInput extends Omit<SaleReceiptPayloadInput, 'line_items'> {
  readonly line_items: ReadonlyArray<LineItemV2Input>;
}

function orNull(value: string | undefined): string | null {
  return value === undefined || value === '' ? null : value;
}

export function buildSaleReceiptV2Payload(
  input: BuildSaleReceiptPayloadInput,
): SaleReceiptV2PayloadInput {
  const v1 = buildSaleReceiptPayload(input);

  // buildLineItems maps cartItems positionally (cartItems.map), so the V1
  // line at index i was built from input.cartItems[i].
  const lineItems: LineItemV2Input[] = v1.line_items.map((line, index) => {
    const item = input.cartItems[index];
    if (item === undefined) {
      throw new SaleReceiptPayloadInputError(
        `SaleReceiptV2 line/cart mismatch: V1 produced line ${index} with no matching cart item.`,
      );
    }

    // Empty strings from a stale/partial catalog sync are coerced to null
    // (Codex P2-2): the engine/server validators reject '' (non-empty
    // string or null), and a degraded variant label must never hard-fail
    // an otherwise-legal checkout at append time.
    const variantId = orNull(item.product.variant_id);
    const variantName = orNull(item.product.variant_name);
    const variantSku = orNull(item.product.variant_sku);

    // A variant identity without its anchor id must never be signed —
    // mirrors the server validator's coupling rule.
    if (variantId === null && (variantName !== null || variantSku !== null)) {
      throw new SaleReceiptPayloadInputError(
        `variant_name/variant_sku present without variant_id for product ${item.product.id}.`,
      );
    }

    return {
      ...line,
      variant_id: variantId,
      variant_name: variantName,
      variant_sku: variantSku,
    };
  });

  return {
    ...v1,
    line_items: lineItems,
  };
}
