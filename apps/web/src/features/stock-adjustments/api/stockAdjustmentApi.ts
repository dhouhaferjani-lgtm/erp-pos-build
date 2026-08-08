import { api, apiGet, apiPost } from '@/lib/api'
import type {
  CreateStockAdjustmentInput,
  StockAdjustment,
  StockAdjustmentListFilters,
  StockAdjustmentListResponse,
  StockLevel,
  UpdateStockAdjustmentInput,
} from '../types'

const BASE_URL = '/stock-adjustments'

export interface PostStockAdjustmentOptions {
  acknowledge_stale?: boolean
  ignore_reservations?: boolean
}

export const stockAdjustmentApi = {
  list: async (filters: StockAdjustmentListFilters = {}): Promise<StockAdjustmentListResponse> => {
    const params = new URLSearchParams()
    if (filters.status && filters.status !== 'all') {
      params.append('status', filters.status)
    }
    if (filters.location_id) {
      params.append('location_id', filters.location_id)
    }
    if (filters.page) {
      params.append('page', String(filters.page))
    }
    if (filters.per_page) {
      params.append('per_page', String(filters.per_page))
    }

    // Paginated endpoint: `api.get`, NOT `apiGet` — apiGet unwraps
    // `response.data.data` and would drop the `meta` envelope the pagination
    // control needs.
    const response = await api.get<StockAdjustmentListResponse>(
      params.toString() ? `${BASE_URL}?${params.toString()}` : BASE_URL,
    )
    return response.data
  },

  show: async (id: string): Promise<StockAdjustment> => apiGet<StockAdjustment>(`${BASE_URL}/${id}`),

  create: async (input: CreateStockAdjustmentInput): Promise<StockAdjustment> =>
    apiPost<StockAdjustment>(BASE_URL, input),

  update: async (id: string, input: UpdateStockAdjustmentInput): Promise<StockAdjustment> => {
    const response = await api.patch<{ data: StockAdjustment }>(`${BASE_URL}/${id}`, input)
    return response.data.data
  },

  post: async (id: string, options: PostStockAdjustmentOptions = {}): Promise<StockAdjustment> =>
    apiPost<StockAdjustment>(`${BASE_URL}/${id}/post`, options),

  cancel: async (id: string, reason?: string): Promise<StockAdjustment> =>
    apiPost<StockAdjustment>(`${BASE_URL}/${id}/cancel`, { reason: reason ?? null }),

  correct: async (id: string): Promise<StockAdjustment> =>
    apiPost<StockAdjustment>(`${BASE_URL}/${id}/correct`, {}),

  /**
   * The FRESH authoring anchor.
   *
   * `observed_before` MUST come from this read, never from the stock-levels
   * LIST cache: the list has no staleTime discipline, so authoring from it would
   * make operators meet STOCK_MOVED_SINCE_AUTHORING for reasons unrelated to
   * their own authoring window — and they would learn to click "apply anyway",
   * inverting the guard's entire value.
   */
  stockLevel: async (productId: string, locationId: string): Promise<StockLevel> =>
    apiGet<StockLevel>(`/stock-levels/${productId}/${locationId}`),
}
