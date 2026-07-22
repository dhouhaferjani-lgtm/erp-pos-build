import { api, apiGet, apiPost } from '@/lib/api'

export interface VatBreakdownEntry {
  rate: string
  net: string
  vat: string
  gross: string
}

export interface PaymentMethodEntry {
  type: string
  count: number
  amount: string
}

export interface ZReportReportData {
  sales_count: number
  gross_sales: string
  net_sales: string
  tax_amount: string
  refunds_count: number
  refunds_amount: string
  voided_count: number
  voided_amount: string
  opening_cash: string
  expected_cash: string
  actual_cash: string
  variance: string
  vat_breakdown: VatBreakdownEntry[]
  payment_methods: PaymentMethodEntry[]
}

export interface ZReportItem {
  id: string
  z_number: number
  terminal_id: string
  terminal_name?: string
  location_id?: string | null
  location_name?: string | null
  shift_id: string
  fiscal_hash: string
  previous_z_hash: string | null
  generated_by: string
  generated_at: string
  is_first_z_report: boolean
  formatted_z_number: string
  sales_count: number
  gross_sales: string
  opening_cash: string
  expected_cash: string
  actual_cash: string
  variance: string
  has_variance: boolean
  report_data: ZReportReportData
  terminal?: {
    id: string
    code: string
    name: string
  }
  shift?: {
    id: string
    shift_number: number
  }
  generated_by_user?: {
    id: string
    name: string
    email: string
  }
}

export interface ZReportListFilters {
  terminal_id?: string
  location_ids?: string[]
  from_date?: string
  to_date?: string
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
  is_valid: boolean
  broken_at_z_number: number | null
  broken_at_id: string | null
}

/**
 * Fetch paginated Z-reports with terminal and date filters.
 *
 * Backend: GET /api/v1/pos/reports/z
 *
 * Uses the raw axios client because the backend returns { data, meta } at the
 * top level (not wrapped in an outer `data` envelope), and we need both the
 * report list and the pagination metadata.
 */
export async function fetchZReports(filters?: ZReportListFilters): Promise<PaginatedZReports> {
  const params = new URLSearchParams()
  if (filters?.terminal_id) params.set('terminal_id', filters.terminal_id)
  filters?.location_ids?.forEach((id) => params.append('location_ids[]', id))
  if (filters?.from_date) params.set('from_date', filters.from_date)
  if (filters?.to_date) params.set('to_date', filters.to_date)
  if (filters?.page) params.set('page', String(filters.page))
  if (filters?.per_page) params.set('per_page', String(filters.per_page))

  const query = params.toString()
  const response = await api.get<PaginatedZReports>(`/pos/reports/z${query ? `?${query}` : ''}`)
  return response.data
}

/**
 * Fetch a single Z-report by Z number for a terminal.
 *
 * Backend: GET /api/v1/pos/reports/z/{zNumber}
 */
export async function fetchZReport(zNumber: string, terminalId: string): Promise<ZReportItem> {
  return apiGet<ZReportItem>(`/pos/reports/z/${zNumber}?terminal_id=${terminalId}`)
}

/**
 * Download a Z-report as PDF.
 *
 * Backend: GET /api/v1/pos/reports/z/{zNumber}/pdf
 *
 * Triggers a file download in the browser by fetching the PDF as a blob
 * and creating a temporary download link.
 */
export async function downloadZReportPdf(zNumber: string, terminalId: string): Promise<void> {
  const response = await api.get(`/pos/reports/z/${zNumber}/pdf`, {
    params: { terminal_id: terminalId },
    responseType: 'blob',
  })

  const blob = new Blob([response.data as BlobPart], { type: 'application/pdf' })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `z-report-Z${zNumber.padStart(4, '0')}.pdf`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(url)
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
