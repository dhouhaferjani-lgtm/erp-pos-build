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

    const response = await api.get<{ data: PaginatedResponse<InventoryCounting> }>(url)
    return response.data.data
  },

  // Detail
  getDetail: async (id: number): Promise<InventoryCounting> => {
    return apiGet<InventoryCounting>(`${BASE_URL}/${String(id)}`)
  },

  // Create
  create: async (data: CreateCountingFormData): Promise<InventoryCounting> => {
    return apiPost<InventoryCounting>(BASE_URL, data)
  },

  // Activate
  activate: async (id: number): Promise<void> => {
    await apiPost(`${BASE_URL}/${String(id)}/activate`, {})
  },

  // Cancel
  cancel: async (id: number, reason: string): Promise<void> => {
    await apiPost(`${BASE_URL}/${String(id)}/cancel`, { reason })
  },

  // Finalize
  finalize: async (id: number): Promise<void> => {
    await apiPost(`${BASE_URL}/${String(id)}/finalize`, {})
  },

  // Reconciliation
  getReconciliation: async (id: number): Promise<ReconciliationData> => {
    return apiGet<ReconciliationData>(`${BASE_URL}/${String(id)}/reconciliation`)
  },

  // Trigger third count
  triggerThirdCount: async (countingId: number, itemIds: number[]): Promise<void> => {
    await apiPost(`${BASE_URL}/${String(countingId)}/trigger-third-count`, { item_ids: itemIds })
  },

  // Manual override
  manualOverride: async (
    itemId: number,
    quantity: string,
    notes: string
  ): Promise<void> => {
    await apiPost(`${BASE_URL}/items/${String(itemId)}/override`, {
      quantity,
      notes,
    })
  },

  // Opening-cost backfill (D3). `unitCost` is a canonical decimal STRING (6 d.p.
  // ceiling) — never parsed through a JS number.
  setOpeningCost: async (
    countingId: number,
    itemId: number,
    unitCost: string
  ): Promise<void> => {
    await apiPatch(
      `${BASE_URL}/${String(countingId)}/items/${String(itemId)}/opening-cost`,
      { unit_cost: unitCost }
    )
  },

  // Report
  getReport: async (id: number): Promise<DiscrepancyReport> => {
    return apiGet<DiscrepancyReport>(`${BASE_URL}/${String(id)}/report`)
  },

  // Export report
  exportReport: async (id: number, format: 'pdf' | 'xlsx'): Promise<Blob> => {
    const response = await api.get(`${BASE_URL}/${String(id)}/report/export`, {
      params: { format },
      responseType: 'blob',
    })
    return response.data as Blob
  },

  // Send reminder
  sendReminder: async (id: number): Promise<void> => {
    await apiPost(`${BASE_URL}/${String(id)}/send-reminder`, {})
  },
}
