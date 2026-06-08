import { apiGet } from '@/lib/api';
import type { POSProductVariant } from '@/types/product';

/**
 * Server wire shape for a product variant (`/products/{productId}/variants`).
 * Mirrors the backend `App.Modules.Catalog.Application.DTOs.ProductVariantData`.
 * `cost_override` is advisory (spec §6.7) and intentionally dropped at this
 * boundary — the POS never reads or transmits it. `stock_quantity` is an
 * optional projection the backend may attach per variant; defaults to 0 when
 * absent so the picker always renders a concrete number.
 */
interface ProductVariantWire {
  id: string;
  product_id: string;
  variant_code: string;
  sku: string;
  barcode?: string | null;
  name_suffix: string;
  is_default: boolean;
  is_active: boolean;
  display_order: number;
  price_override?: string | null;
  image_url?: string | null;
  stock_quantity?: number | string | null;
}

function toStockQuantity(raw: ProductVariantWire['stock_quantity']): number {
  if (raw == null) return 0;
  const value = typeof raw === 'string' ? Number(raw) : raw;
  return Number.isFinite(value) ? value : 0;
}

function toVariant(wire: ProductVariantWire): POSProductVariant {
  return {
    id: wire.id,
    product_id: wire.product_id,
    variant_code: wire.variant_code,
    sku: wire.sku,
    barcode: wire.barcode ?? null,
    name_suffix: wire.name_suffix,
    is_default: wire.is_default,
    is_active: wire.is_active,
    display_order: wire.display_order,
    price_override: wire.price_override ?? null,
    image_url: wire.image_url ?? null,
    stock_quantity: toStockQuantity(wire.stock_quantity),
  };
}

/**
 * Fetch the active, sellable variants for a product, sorted by display order.
 * Only active variants are returned to the cashier — soft-deleted or disabled
 * variants never appear in the picker.
 */
export async function fetchProductVariants(
  productId: string,
): Promise<POSProductVariant[]> {
  const result = await apiGet<ProductVariantWire[] | { data: ProductVariantWire[] }>(
    `/products/${productId}/variants`,
  );
  const rows = Array.isArray(result) ? result : result.data;
  return rows
    .filter((row) => row.is_active)
    .map(toVariant)
    .sort((a, b) => a.display_order - b.display_order);
}
