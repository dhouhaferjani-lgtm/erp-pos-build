import { api, apiGet } from '@/lib/api'
import type { OffsetPaginationMeta } from '@/types/pagination'

/**
 * Fiscal Phase 1 §14.2 — new-sale server-authoring retired.
 *
 * The new-sale write methods `createReceipt` and `processReceiptPayments`
 * were deleted from this file. The web POS no longer authors SALE_RECEIPT
 * server-side; receipts are device-authored and ingested via
 * `POST /api/v1/pos/sync/fiscal-events`. The backend routes
 * `POST /api/v1/pos/receipts` and `POST /api/v1/pos/receipts/{id}/payments`
 * now return HTTP 410 Gone with `NEW_SALE_AUTHORING_RETIRED`.
 *
 * Knowingly retained per §14.2 (read-only):
 *   - `getReceipt` — read-only lookup
 *   - `printReceipt` / `downloadReceipt` — PDF rendering (read-only)
 *
 * `/pos/receipts` is the canonical read-only company receipt register.
 * Refund Phase 6 quarantined the old web-admin return controls;
 * `getReceiptDetail` / `processReturn` remain deleted. The BACKEND
 * `POST /pos/receipts/{id}/return` route stays for the desktop POS flow.
 */

export type ReceiptListItem = App.Modules.POS.Application.DTOs.ReceiptListItemData
export type ReceiptDetail = App.Modules.POS.Application.DTOs.ReceiptDetailData
export type RefundReceiptListItem = ReceiptListItem & App.Modules.POS.Application.DTOs.RefundReportingData
export type ReceiptFilterTerminal = App.Modules.POS.Application.DTOs.ReceiptFilterTerminalData
export type ReceiptFilterCashier = App.Modules.POS.Application.DTOs.ReceiptFilterCashierData

export type ReceiptInvoiceTypeCode = 'SALE' | 'TRAINING' | 'REFUND' | 'VOID'
export type ReceiptFiscalStatus = 'pending_seal' | 'fiscalized' | 'voided' | 'pending_sync' | 'synced' | 'sync_failed'

export interface ReceiptListFilters {
  location_ids?: string[]
  terminal_id?: string
  cashier_id?: string
  invoice_type_codes?: ReceiptInvoiceTypeCode[]
  fiscal_status?: ReceiptFiscalStatus
  include_training?: boolean
  receipt_number?: string
  from_date?: string
  to_date?: string
  page?: number
  per_page?: number
}

export interface ReceiptListMeta extends Omit<OffsetPaginationMeta, 'from' | 'to'> {
  from: string | null
  to: string | null
}

export interface ReceiptListResponse {
  data: ReceiptListItem[]
  meta: ReceiptListMeta
}

export interface RefundReceiptListResponse extends Omit<ReceiptListResponse, 'data'> {
  data: RefundReceiptListItem[]
}

export interface ReceiptFilterOptions {
  terminals: ReceiptFilterTerminal[]
  cashiers: ReceiptFilterCashier[]
}

export type ReceiptFilterOptionsFilters = Pick<ReceiptListFilters, 'location_ids' | 'from_date' | 'to_date'>

function appendArray(params: URLSearchParams, key: string, values: string[] | undefined): void {
  values?.forEach((value) => {
    params.append(`${key}[]`, value)
  })
}

function receiptParams(filters: ReceiptListFilters): URLSearchParams {
  const params = new URLSearchParams()
  appendArray(params, 'location_ids', filters.location_ids)
  appendArray(params, 'invoice_type_codes', filters.invoice_type_codes)
  if (filters.terminal_id) params.set('terminal_id', filters.terminal_id)
  if (filters.cashier_id) params.set('cashier_id', filters.cashier_id)
  if (filters.fiscal_status) params.set('fiscal_status', filters.fiscal_status)
  if (filters.include_training) params.set('include_training', 'true')
  if (filters.receipt_number) params.set('receipt_number', filters.receipt_number)
  if (filters.from_date) params.set('from_date', filters.from_date)
  if (filters.to_date) params.set('to_date', filters.to_date)
  if (filters.page) params.set('page', String(filters.page))
  if (filters.per_page) params.set('per_page', String(filters.per_page))
  return params
}

export async function fetchReceipts(filters: ReceiptListFilters): Promise<ReceiptListResponse> {
  const query = receiptParams(filters).toString()
  const response = await api.get<{ data: ReceiptListResponse }>(`/pos/receipts${query ? `?${query}` : ''}`)
  return response.data.data
}

export async function fetchRefundReceipts(filters: ReceiptListFilters): Promise<RefundReceiptListResponse> {
  const query = receiptParams({
    ...filters,
    invoice_type_codes: ['REFUND', 'VOID'],
  }).toString()
  const response = await api.get<{ data: RefundReceiptListResponse }>(`/pos/receipts?${query}`)
  return response.data.data
}

export async function fetchReceiptFilterOptions(filters: ReceiptFilterOptionsFilters): Promise<ReceiptFilterOptions> {
  const params = new URLSearchParams()
  appendArray(params, 'location_ids', filters.location_ids)
  if (filters.from_date) params.set('from_date', filters.from_date)
  if (filters.to_date) params.set('to_date', filters.to_date)
  const query = params.toString()
  const response = await api.get<{ data: ReceiptFilterOptions }>(`/pos/receipts/filter-options${query ? `?${query}` : ''}`)
  return response.data.data
}

/**
 * Get receipt details
 */
export async function getReceipt(id: string): Promise<ReceiptDetail> {
  return apiGet<ReceiptDetail>(`/pos/receipts/${id}`)
}

// §14.2 — `createReceipt`, `processReceiptPayments`,
// `CreateReceiptRequest`, `CreateReceiptResponse`,
// `ProcessReceiptPaymentsRequest`, `ProcessReceiptPaymentsResponse` were
// deleted as part of the new-sale server-authoring disposition. Do NOT
// re-export them; new-sale flows belong on the device + `/sync/fiscal-events`.

/**
 * Print receipt - returns PDF blob for browser print dialog
 */
export async function printReceipt(receiptId: string): Promise<Blob> {
  const response = await api.get<Blob>(`/pos/receipts/${receiptId}/pdf`, {
    responseType: 'blob',
  })

  return response.data
}

/**
 * Download receipt - triggers browser download
 */
export async function downloadReceipt(receiptId: string): Promise<void> {
  const response = await api.get<Blob>(`/pos/receipts/${receiptId}/pdf/download`, {
    responseType: 'blob',
  })

  const blob = new Blob([response.data], { type: 'application/pdf' })
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `receipt-${receiptId}.pdf`
  document.body.appendChild(a)
  a.click()
  window.URL.revokeObjectURL(url)
  document.body.removeChild(a)
}
