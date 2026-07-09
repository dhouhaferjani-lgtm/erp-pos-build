import type { POSProduct } from '@/types/product';

export interface NearExpirySlotProps {
  product: POSProduct;
  /**
   * Spec 2 near-expiry data for this product/location (earliest lot expiry
   * date, threshold policy, etc.) — the shape is not decided yet, hence
   * `unknown`. No caller passes this today; it exists only so Spec 2 can add
   * the prop without touching every call site.
   */
  nearExpiry?: unknown;
}

/**
 * Reserved render point for the Spec-2 near-expiry chip (a Rev 2 requirement
 * that was dropped from this branch's scope and tracked separately).
 *
 * Renders a near-expiry chip when the product's earliest lot expiry falls
 * within the configured warning threshold — NOT IMPLEMENTED YET. Intentionally
 * returns `null` today and changes no layout. Do not delete this call site
 * when touching the surrounding stock/price row in `ProductCard`,
 * `ProductListRow`, or `ProductTable` — it marks exactly where Spec 2 plugs
 * in, in all three tri-density views.
 */
export function NearExpirySlot(_props: NearExpirySlotProps) {
  return null;
}
