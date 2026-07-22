import { apiGet } from '@/lib/api'
import type { OffsetPaginationMeta } from '@/types/pagination'

export interface ShiftHistoryItem {
  id: string
  shift_number: number
  terminal_id: string
  terminal_code: string
  terminal_name: string
  cashier_id: string
  cashier_name: string
  status: 'OPEN' | 'CLOSED'
  opening_cash: string
  expected_cash: string | null
  actual_cash: string | null
  variance: string | null
  opened_at: string
  closed_at: string | null
  receipt_count: number
}

export interface ShiftHistoryFilters {
  terminal_id?: string | undefined
  status?: 'OPEN' | 'CLOSED' | undefined
  from_date?: string | undefined
  to_date?: string | undefined
  page?: number | undefined
  per_page?: number | undefined
}

export interface PaginatedShifts {
  data: ShiftHistoryItem[]
  meta: OffsetPaginationMeta
}

/**
 * Fetch paginated shift history with filters.
 *
 * Backend: GET /api/v1/pos/shifts
 * Note: This uses the raw response structure since it's paginated.
 */
export async function fetchShiftHistory(filters?: ShiftHistoryFilters): Promise<PaginatedShifts> {
  const params = new URLSearchParams()
  if (filters?.terminal_id) params.set('terminal_id', filters.terminal_id)
  if (filters?.status) params.set('status', filters.status)
  if (filters?.from_date) params.set('from_date', filters.from_date)
  if (filters?.to_date) params.set('to_date', filters.to_date)
  if (filters?.page) params.set('page', String(filters.page))
  if (filters?.per_page) params.set('per_page', String(filters.per_page))

  const query = params.toString()
  return apiGet<PaginatedShifts>(`/pos/shifts${query ? `?${query}` : ''}`)
}
