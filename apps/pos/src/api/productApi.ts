import { apiGet } from '@/lib/api';
import type { POSProduct, GetPOSProductsParams } from '@/types/product';
import type { CompanyConfig } from '@/types/companyConfig';
import type { ModifierGroup } from '@/types/modifier';

export async function fetchPOSProducts(params?: GetPOSProductsParams): Promise<POSProduct[]> {
  if (!params) {
    const result = await apiGet<POSProduct[] | { data: POSProduct[] }>('/products');
    return Array.isArray(result) ? result : result.data;
  }
  const { limit, ...rest } = params;
  const result = await apiGet<POSProduct[] | { data: POSProduct[] }>('/products', { ...rest, per_page: limit });
  return Array.isArray(result) ? result : result.data;
}

export async function fetchProductByBarcode(barcode: string): Promise<POSProduct[]> {
  const result = await apiGet<POSProduct[] | { data: POSProduct[] }>('/products', { barcode, per_page: 10 });
  return Array.isArray(result) ? result : result.data;
}

export async function fetchCompanyConfig(): Promise<CompanyConfig> {
  return apiGet<CompanyConfig>('/company/config');
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
  return apiGet<ActiveMenuResponse>('/active-menu');
}

export function flattenMenuToProducts(menu: ActiveMenuResponse): POSProduct[] {
  const products: POSProduct[] = [];

  for (const category of menu.categories) {
    for (const item of category.items) {
      if (!item.is_available) continue;
      products.push({
        id: item.sellable_id,
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
      });
    }
  }

  return products;
}
