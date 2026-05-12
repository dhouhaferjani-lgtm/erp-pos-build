import type { ModifierGroup } from './modifier';

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
}

export interface GetPOSProductsParams {
  search?: string;
  category_id?: string;
  limit?: number;
  page?: number;
}
