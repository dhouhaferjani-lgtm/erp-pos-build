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
}

export interface GetPOSProductsParams {
  search?: string;
  category_id?: string;
  limit?: number;
  page?: number;
}
