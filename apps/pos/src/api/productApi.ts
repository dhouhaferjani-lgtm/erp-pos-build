import { apiGet, type ApiRequestOptions } from '@/lib/api';
import type { POSProduct, GetPOSProductsParams } from '@/types/product';
import type { CompanyConfig } from '@/types/companyConfig';
import type { ModifierGroup } from '@/types/modifier';
import { buildMenuCompositeId } from '@/lib/menu/compositeId';

export async function fetchPOSProducts(params?: GetPOSProductsParams): Promise<POSProduct[]> {
  if (!params) {
    const result = await apiGet<POSProduct[] | { data: POSProduct[] }>('/products');
    return Array.isArray(result) ? result : result.data;
  }
  const { limit, ...rest } = params;
  const result = await apiGet<POSProduct[] | { data: POSProduct[] }>('/products', { ...rest, per_page: limit });
  return Array.isArray(result) ? result : result.data;
}

/**
 * Look up products by barcode OR SKU. The backend filter at
 * `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:181-187`
 * matches `barcode` OR `sku` server-side, so this helper handles SKU lookups
 * too despite the legacy name. Returns up to 10 matches — collisions on a
 * single barcode/SKU code are allowed (UPC overlap, internal renumbering,
 * weight-embedded barcodes); callers should resolve with a chooser modal.
 *
 * T2.1 Step B: signature extended with optional `opts` so the foreground
 * scan resolver can apply a 5s per-call timeout (tunable) + thread an
 * AbortSignal for concurrent-scan cancellation. Default behavior unchanged
 * for callers that omit `opts`.
 */
export async function fetchProductByBarcode(
  barcode: string,
  opts?: ApiRequestOptions,
): Promise<POSProduct[]> {
  const result = await apiGet<POSProduct[] | { data: POSProduct[] }>(
    '/products',
    { barcode, per_page: 10 },
    opts,
  );
  return Array.isArray(result) ? result : result.data;
}

export async function fetchCompanyConfig(): Promise<CompanyConfig> {
  const config = await apiGet<CompanyConfig>('/company/config');
  // Persist offline so the cross-location gate survives a restart (H2).
  // Dynamic import avoids a productApi → authStore static cycle.
  try {
    const { useAuthStore } = await import('@/stores/authStore');
    const companyId = useAuthStore.getState().companyId;
    if (companyId) {
      const { persistCompanyConfig } = await import('@/lib/companyConfigCache');
      await persistCompanyConfig(companyId, config).catch(() => {});
    }
  } catch {
    // Best-effort — never break the fetch path
  }
  return config;
}

interface ActiveMenuCategory {
  id: string;
  name: string;
  position: number;
  items: ActiveMenuItem[];
}

interface ActiveMenuItem {
  id: string;
  sellable_id: string;
  sellable_type: string;
  name: string;
  code: string;
  barcode?: string | null;
  base_price: string;
  effective_price: string;
  image_url?: string | null;
  tax_rate?: string | null;
  display_order: number;
  is_available: boolean;
  modifier_groups?: ModifierGroup[];
}

interface ActiveMenuResponse {
  categories: ActiveMenuCategory[];
}

export async function fetchActiveMenu(): Promise<ActiveMenuResponse> {
  try {
    return await apiGet<ActiveMenuResponse>('/active-menu');
  } catch (error) {
    const { useAuthStore } = await import('@/stores/authStore');
    const { companyId } = useAuthStore.getState();
    if (!companyId) throw error;

    const { getDatabase } = await import('@/lib/db');
    const { getActiveMenu } = await import('@/lib/db/repositories/menuRepository');
    const db = await getDatabase(companyId);
    const cached = await getActiveMenu(db);
    if (cached.categories.length === 0) throw error;
    return cached as ActiveMenuResponse;
  }
}

/**
 * C2 — Menu-tenant catalog composite-ID flatten.
 *
 * The active-menu API returns a hierarchical (categories → items) structure
 * where each item carries a `sellable_id` (the underlying product UUID).
 * Pre-C2 this flattener emitted `id = sellable_id`, which collapsed the
 * same sellable cross-listed across two categories into a single row in
 * the local SQLite catalog (PK conflict on `id`).
 *
 * Post-C2 the emitted `POSProduct.id` is the composite
 * `${sellable_id}_${menu_category_id}`, so each (sellable, category) pair
 * surfaces as a distinct row. The bare `sellable_id` and `menu_category_id`
 * are also surfaced on the POSProduct so consumers (cart-line refund
 * lookup, sync wire-payload boundary) can recover the bare reference
 * without parsing the composite string.
 *
 * The wire-payload boundary at `syncService.receiptToPayload` unpacks the
 * composite back to `sellable_id` before sending to the server, so
 * `pos_receipt_lines.product_id` continues to satisfy the existing FK +
 * XOR constraints. Server-side schema is unchanged Day 1.
 */
export function flattenMenuToProducts(menu: ActiveMenuResponse): POSProduct[] {
  const products: POSProduct[] = [];

  for (const category of menu.categories) {
    for (const item of category.items) {
      if (!item.is_available) continue;
      products.push({
        id: buildMenuCompositeId(item.sellable_id, category.id),
        name: item.name,
        sku: item.code,
        barcode: item.barcode,
        sale_price: item.effective_price,
        stock_quantity: 999, // F&B made-to-order
        category: category.name,
        image_url: item.image_url ?? undefined,
        tax_rate: item.tax_rate ?? undefined,
        sellableType: (item.sellable_type as POSProduct['sellableType']) ?? 'product',
        modifier_groups: item.modifier_groups,
        position: item.display_order,
        sellable_id: item.sellable_id,
        menu_category_id: category.id,
      });
    }
  }

  return products;
}
