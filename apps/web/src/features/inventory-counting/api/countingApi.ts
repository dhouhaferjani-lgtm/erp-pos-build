import { api, apiGet, apiPatch, apiPost } from '@/lib/api'
import type {
  InventoryCounting,
  CountingDashboard,
  ReconciliationData,
  DiscrepancyReport,
  CreateCountingFormData,
  CountingFilters,
  PaginatedResponse,
} from '../types'

const BASE_URL = '/inventory/countings'

export const countingApi = {
  // Dashboard
  getDashboard: async (): Promise<CountingDashboard> => {
    return apiGet<CountingDashboard>(`${BASE_URL}/dashboard`)
  },

  // List
  list: async (filters: CountingFilters): Promise<PaginatedResponse<InventoryCounting>> => {
    const params = new URLSearchParams()

    if (filters.status && filters.status !== 'all') {
      params.append('status', filters.status)
    }
    if (filters.warehouse_id) {
      params.append('warehouse_id', filters.warehouse_id.toString())
    }
    if (filters.search) {
      params.append('search', filters.search)
    }
    if (filters.date_from) {
      params.append('date_from', filters.date_from)
    }
    if (filters.date_to) {
      params.append('date_to', filters.date_to)
    }
    if (filters.page) {
      params.append('page', filters.page.toString())
    }
    if (filters.per_page) {
      params.append('per_page', filters.per_page.toString())
    }
    if (filters.sort_by) {
      params.append('sort_by', filters.sort_by)
    }
    if (filters.sort_dir) {
      params.append('sort_dir', filters.sort_dir)
    }

    const queryString = params.toString()
    const url = queryString ? `${BASE_URL}?${queryString}` : BASE_URL

    const response = await api.get<PaginatedResponse<InventoryCounting>>(url)
    return response.data
  },

  // Detail
  getDetail: async (id: string): Promise<InventoryCounting> => {
    return apiGet<InventoryCounting>(`${BASE_URL}/${id}`)
  },

  // Create
  create: async (data: CreateCountingFormData): Promise<InventoryCounting> => {
    return apiPost<InventoryCounting>(BASE_URL, data)
  },

  // Activate
  activate: async (id: string): Promise<void> => {
    await apiPost(`${BASE_URL}/${id}/activate`, {})
  },

  // Cancel
  cancel: async (id: string, reason: string): Promise<void> => {
    await apiPost(`${BASE_URL}/${id}/cancel`, { reason })
  },

  // Finalize
  finalize: async (id: string): Promise<void> => {
    await apiPost(`${BASE_URL}/${id}/finalize`, {})
  },

  // Reconciliation
  getReconciliation: async (id: string): Promise<ReconciliationData> => {
    return apiGet<ReconciliationData>(`${BASE_URL}/${id}/reconciliation`)
  },

  // Trigger third count
  triggerThirdCount: async (countingId: string, itemIds: string[]): Promise<void> => {
    await apiPost(`${BASE_URL}/${countingId}/trigger-third-count`, { item_ids: itemIds })
  },

  // Manual override
  manualOverride: async (
    itemId: string,
    quantity: string,
    notes: string
  ): Promise<void> => {
    await apiPost(`${BASE_URL}/items/${itemId}/override`, {
      quantity,
      notes,
    })
  },

  // Opening-cost backfill (D3). `unitCost` is a canonical decimal STRING (6 d.p.
  // ceiling) — never parsed through a JS number.
  setOpeningCost: async (
    countingId: string,
    itemId: string,
    unitCost: string
  ): Promise<void> => {
    await apiPatch(
      `${BASE_URL}/${countingId}/items/${itemId}/opening-cost`,
      { unit_cost: unitCost }
    )
  },

  // Report
  getReport: async (id: string): Promise<DiscrepancyReport> => {
    return apiGet<DiscrepancyReport>(`${BASE_URL}/${id}/report`)
  },

  // Export report
  exportReport: async (id: string, format: 'pdf' | 'xlsx'): Promise<Blob> => {
    const response = await api.get(`${BASE_URL}/${id}/report/export`, {
      params: { format },
      responseType: 'blob',
    })
    return response.data as Blob
  },

  // Send reminder
  sendReminder: async (id: string): Promise<void> => {
    await apiPost(`${BASE_URL}/${id}/send-reminder`, {})
  },
}
