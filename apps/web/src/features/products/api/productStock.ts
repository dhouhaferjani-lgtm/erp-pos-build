import { apiGet } from '@/lib/api'

export interface StockLocation {
  id: string
  location_id: string
  location_name: string
  quantity: string
  reserved: string
  available: string
  incoming: string
  projected_available: string
  min_quantity: string | null
  max_quantity: string | null
  is_below_minimum: boolean
}

export interface StockTotals {
  quantity: string
  reserved: string
  available: string
  incoming: string
  projected_available: string
}

export interface ProductStockResponse {
  locations: StockLocation[]
  totals: StockTotals
}

export async function getProductStock(productId: string): Promise<ProductStockResponse> {
  return apiGet<ProductStockResponse>(`/products/${productId}/stock-levels`)
}
