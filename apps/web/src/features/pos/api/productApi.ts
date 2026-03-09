import { apiGet } from '@/lib/api'

export interface POSProduct {
  id: string
  name: string
  sku: string
  barcode?: string | null
  sale_price: string | null
  stock_quantity: number
  category?: string
  image_url?: string
  tax_rate?: string
}

export interface GetPOSProductsParams {
  search?: string
  category_id?: string
  limit?: number
  page?: number
}

export async function fetchPOSProducts(params?: GetPOSProductsParams): Promise<POSProduct[]> {
  // Backend automatically filters by company vertical via ProductController::index()
  // Map 'limit' to 'per_page' for backend compatibility
  const backendParams = params ? { ...params, per_page: params.limit } : undefined
  return apiGet<POSProduct[]>('/products', backendParams)
}

/**
 * Fetch products by exact barcode or SKU match.
 *
 * Uses the `barcode` filter parameter which does exact matching
 * on both the `barcode` and `sku` columns (OR).
 */
export async function fetchProductByBarcode(barcode: string): Promise<POSProduct[]> {
  return apiGet<POSProduct[]>('/products', { barcode, per_page: 10 })
}
