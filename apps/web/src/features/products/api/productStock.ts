import { apiGet } from '@/lib/api'

export type StockLocation = App.Modules.Inventory.Application.DTOs.StockLevelData

export interface StockTotals {
  quantity: string
  quantity_decimals: number
  reserved: string
  available: string
  incoming: string
  projected_available: string
}

export interface ProductStockResponse {
  locations: StockLocation[]
  totals: StockTotals
}

export async function getProductStock(productId: string, variantId?: string | null): Promise<ProductStockResponse> {
  return apiGet<ProductStockResponse>(
    `/products/${productId}/stock-levels`,
    variantId ? { variant_id: variantId } : undefined,
  )
}
