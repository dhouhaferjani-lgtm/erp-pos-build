import type { ModifierGroup } from './modifier';

/**
 * Task 20 — Parapharmacy metadata stored as a JSON blob in the SQLite
 * `products.parapharmacy_metadata` TEXT column.  Only populated for
 * products that belong to a parapharmacy vertical; all fields default to
 * empty arrays so callers can iterate without null checks.
 */
export interface ParapharmacyMeta {
  suitable_skin_types: string[];
  equivalent_product_ids: string[];
  complement_product_ids: string[];
  routine_refs: {
    routine_id: string;
    step_order: number;
    step_label: string;
  }[];
}

/**
 * T2 product variant as surfaced to the POS catalog. Mirrors the backend
 * `App.Modules.Catalog.Application.DTOs.ProductVariantData` wire shape
 * (`/products/{productId}/variants`) with the POS-only `stock_quantity`
 * projection so the cashier can see the variant's on-hand stock when picking.
 *
 * `cost_override` is intentionally omitted: it is advisory per spec §6.7 and
 * the POS never displays or transmits it. Inventory WAC stays product-grain.
 */
export interface POSProductVariant {
  id: string;
  product_id: string;
  variant_code: string;
  sku: string;
  barcode?: string | null;
  /** Suffix appended to the product name, e.g. " — 39 / Noir". */
  name_suffix: string;
  is_default: boolean;
  is_active: boolean;
  display_order: number;
  /** Absolute price override for this variant; falls back to the product `sale_price` when null. */
  price_override?: string | null;
  image_url?: string | null;
  /**
   * Variant-grain on-hand stock. Defaults to 0 when the backend has not
   * projected per-variant stock onto the row.
   */
  stock_quantity: number;
}

export interface POSProduct {
  id: string;
  name: string;
  sku: string;
  barcode?: string | null;
  sale_price: string | null;
  stock_quantity: number;
  category?: string;
  image_url?: string;
  tax_rate?: string;
  sellableType?: 'product' | 'composite_item';
  modifier_groups?: ModifierGroup[];
  /**
   * T2 — when true the product carries sellable variants and the cashier must
   * pick a specific variant before it can be added to the cart. Optional and
   * defaults to falsy so every non-variant product flows through the existing
   * add-to-cart path with zero behavioural change.
   */
  has_variants?: boolean;
  position?: number;
  /**
   * C2 — Menu-tenant composite primary key. Populated by
   * `flattenMenuToProducts` for Menu-mode tenants; left undefined for
   * standard-retail rows where `id` already IS the bare sellable UUID.
   * The wire-payload boundary at `syncService.receiptToPayload` unpacks
   * composite ids back to bare `sellable_id` so server-side
   * `pos_receipt_lines.product_id` continues to satisfy its foreign-key.
   */
  sellable_id?: string;
  menu_category_id?: string | null;
  /**
   * Task 20 — UUID of the product's brand (e.g. "Vichy", "Avène").
   * Sourced from `ProductData.brand_id` on the server.  Null for products
   * without a brand or for non-parapharmacy installs.
   */
  brand_id?: string | null;
  /**
   * Task 20 — Denormalised brand display label.  Avoids a join in the POS
   * and lets the UI render the brand name on the product card without an
   * additional lookup.  Null when the product has no brand.
   */
  brand_name?: string | null;
  /**
   * Task 20 — Structured parapharmacy attributes stored as a JSON blob.
   * Null for standard-retail (non-parapharmacy) products.
   */
  parapharmacy_metadata?: ParapharmacyMeta | null;
  /**
   * Task 10 — whether the product is a physical (stock-tracked) item.
   * `false` means the product is a service/non-physical and is exempt from
   * stock enforcement (availability selector returns `null`).
   *
   * Optional because the Menu flatten path does not set it; an absent value
   * is treated as `true` (physical / stock-checked) so we fail toward
   * enforcement rather than silently exempting an unknown product.
   *
   * Persisted in the local SQLite `products` table as an INTEGER (1 / 0)
   * via migration v51. Sourced from `ProductData.is_physical` on the server.
   */
  is_physical?: boolean;
  /**
   * UoM display precision — number of decimal places the product's unit of
   * measure renders/increments at (0 for pieces, 3 for kg, etc.). Sourced from
   * the server `/products` `quantity_decimals` field and persisted in the local
   * SQLite `products` table as a nullable INTEGER via migration v62.
   *
   * `null` / absent means the server did not project it (older backend); callers
   * fall back to canonical scale-4 display. See `lib/quantity.ts`.
   */
  quantity_decimals?: number | null;
}

export interface GetPOSProductsParams {
  search?: string;
  category_id?: string;
  limit?: number;
  page?: number;
}
