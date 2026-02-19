import { apiGet, apiPost } from '@/lib/api'

export interface ZReportItem {
  id: string
  z_number: number
  terminal_id: string
  terminal_code: string
  shift_id: string
  generated_by: string
  generated_at: string
  total_sales: string
  total_tax: string
  receipt_count: number
  fiscal_hash: string
}

export interface ZReportListFilters {
  terminal_id?: string
  page?: number
  per_page?: number
}

export interface PaginatedZReports {
  data: ZReportItem[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface ChainVerificationResult {
  valid: boolean
  checked_count: number
  error_at?: number
}

/**
 * Fetch paginated Z-reports with terminal filter.
 *
 * Backend: GET /api/v1/pos/reports/z
 */
export async function fetchZReports(filters?: ZReportListFilters): Promise<PaginatedZReports> {
  const params = new URLSearchParams()
  if (filters?.terminal_id) params.set('terminal_id', filters.terminal_id)
  if (filters?.page) params.set('page', String(filters.page))
  if (filters?.per_page) params.set('per_page', String(filters.per_page))

  const query = params.toString()
  return apiGet<PaginatedZReports>(`/pos/reports/z${query ? `?${query}` : ''}`)
}

/**
 * Verify the Z-report hash chain integrity for a terminal.
 *
 * Backend: POST /api/v1/pos/reports/z/verify-chain
 */
export async function verifyZReportChain(terminalId: string): Promise<ChainVerificationResult> {
  return apiPost<ChainVerificationResult>('/pos/reports/z/verify-chain', {
    terminal_id: terminalId,
  })
}
