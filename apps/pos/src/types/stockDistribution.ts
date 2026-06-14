/**
 * One location's stock position in the cross-location stock distribution view.
 * Keys mirror the backend JSON wire shape from StockDistributionController (Task B5).
 * Numeric values are decimal strings at quantity scale 4.
 */
export interface StockDistributionRow {
  location_id: string;
  location_name: string;
  location_type: string;
  is_current: boolean;
  on_hand: string;           // decimal string, scale 4
  incoming_transfer: string; // decimal string, scale 4
}

/**
 * Cross-location stock distribution for one product (+ optional variant).
 * Returned by GET /api/v1/pos/products/{product}/stock-distribution (Task B5).
 * The `apiGet` client unwraps the `{ data: ... }` envelope, so this type
 * represents the inner payload directly.
 */
export interface StockDistribution {
  product_id: string;
  variant_id: string | null;
  variant_label: string | null;
  locations: StockDistributionRow[];
  totals: {
    on_hand: string;
    incoming_transfer: string;
  };
  as_of: string;
}
