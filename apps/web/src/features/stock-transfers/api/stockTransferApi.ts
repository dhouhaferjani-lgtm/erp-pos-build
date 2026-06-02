import { api, apiGet, apiPost } from '@/lib/api'
import type {
  CreateStockTransferInput,
  StockTransfer,
  StockTransferListFilters,
  StockTransferListResponse,
} from '../types'

const BASE_URL = '/stock-transfers'

export const stockTransferApi = {
  list: async (filters: StockTransferListFilters = {}): Promise<StockTransferListResponse> => {
    const params = new URLSearchParams()
    if (filters.status && filters.status !== 'all') {
      params.append('status', filters.status)
    }
    if (filters.source_location_id) {
      params.append('source_location_id', filters.source_location_id)
    }
    if (filters.destination_location_id) {
      params.append('destination_location_id', filters.destination_location_id)
    }
    if (filters.page) {
      params.append('page', String(filters.page))
    }
    if (filters.per_page) {
      params.append('per_page', String(filters.per_page))
    }

    // Paginated endpoint: do NOT use apiGet — we need to preserve the meta wrapper.
    const response = await api.get<StockTransferListResponse>(
      params.toString() ? `${BASE_URL}?${params.toString()}` : BASE_URL,
    )
    return response.data
  },

  show: async (id: string): Promise<StockTransfer> => {
    return apiGet<StockTransfer>(`${BASE_URL}/${id}`)
  },

  create: async (input: CreateStockTransferInput): Promise<StockTransfer> => {
    return apiPost<StockTransfer>(BASE_URL, input)
  },

  complete: async (id: string): Promise<StockTransfer> => {
    return apiPost<StockTransfer>(`${BASE_URL}/${id}/complete`, {})
  },

  cancel: async (id: string, reason?: string): Promise<StockTransfer> => {
    return apiPost<StockTransfer>(`${BASE_URL}/${id}/cancel`, { reason: reason ?? null })
  },
}
