import { api } from '@/lib/api'
import type { AxiosResponse } from 'axios'

export interface MatrixCell {
  on_hand: string
  reserved: string
  available: string
  min_quantity: string | null
  max_quantity: string | null
  incoming?: string
}
export interface MatrixRow {
  product_id: string
  variant_id: string | null
  name: string
  sku: string
  is_variant_parent: boolean
  cells: Record<string, MatrixCell>
}
export interface StockMatrixResponse {
  data: MatrixRow[]
  meta: { current_page: number; last_page: number; total: number }
}
export interface RebalanceRow {
  product_id: string
  variant_id: string | null
  name: string
  sku: string
  deficits: Array<{ location_id: string; available: string; min_quantity: string | null }>
  surpluses: Array<{ location_id: string; available: string; max_quantity: string | null; excess: string }>
}

export async function getStockMatrix(params: { locationIds: string[]; search: string; page: number; perPage: number; includeIncoming: boolean }): Promise<StockMatrixResponse> {
  const query = new URLSearchParams()
  params.locationIds.forEach((id) => { query.append('location_ids[]', id) })
  if (params.search) query.set('search', params.search)
  query.set('page', String(params.page)); query.set('per_page', String(params.perPage))
  if (params.includeIncoming) query.set('include', 'incoming')
  const response: AxiosResponse<StockMatrixResponse> = await api.get(`/inventory/stock-matrix?${query.toString()}`)
  return response.data
}

export async function updateThresholds(body: { product_id: string; variant_id: string | null; location_id: string; min_quantity: string | null; max_quantity: string | null }): Promise<void> {
  await api.put('/inventory/stock-levels/thresholds', body)
}

export async function getRebalance(locationIds: string[]): Promise<{ data: RebalanceRow[] }> {
  const query = new URLSearchParams()
  locationIds.forEach((id) => query.append('location_ids[]', id))
  const response: AxiosResponse<{ data: RebalanceRow[] }> = await api.get(`/inventory/stock-matrix/rebalance?${query.toString()}`)
  return response.data
}
