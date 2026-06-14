import { apiGet } from '@/lib/api';
import type { StockDistribution } from '@/types/stockDistribution';

/**
 * Fetch the cross-location stock distribution for a product (+ optional variant).
 *
 * Maps to GET /api/v1/pos/products/{productId}/stock-distribution (Task B5).
 * `apiGet` unwraps the `{ data: ... }` envelope, so the returned value is the
 * inner StockDistribution object directly.
 *
 * @param productId - UUID of the product
 * @param variantId - UUID of the variant, or null for product-grain
 * @param currentLocationId - UUID of the caller's current location (marks is_current),
 *                            or null to omit (backend treats missing as empty string)
 */
export async function fetchStockDistribution(
  productId: string,
  variantId: string | null,
  currentLocationId: string | null,
): Promise<StockDistribution> {
  const params: Record<string, unknown> = {};
  if (variantId) params.variant_id = variantId;
  if (currentLocationId) params.current_location_id = currentLocationId;
  return apiGet<StockDistribution>(`/pos/products/${productId}/stock-distribution`, params);
}
