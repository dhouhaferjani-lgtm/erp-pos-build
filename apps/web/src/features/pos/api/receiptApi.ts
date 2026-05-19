import { api, apiGet, apiPost } from '@/lib/api'

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
 * Knowingly retained per §14.2 (read-only + Phase 2+ reserved event types):
 *   - `getReceipt` / `getReceiptDetail` — read-only lookup
 *   - `printReceipt` / `downloadReceipt` — PDF rendering (read-only)
 *   - `processReturn` — REFUND_RECEIPT / PARTIAL_REFUND (Phase 2+ reserved)
 *
 * void is reached via a separate `voidReceipt` function elsewhere (or
 * inline `apiPost` in the void modal — not part of the §14.2 retirement).
 */

export interface ReceiptData {
  id: string
  receipt_number: string
  terminal_id: string
  cashier_name: string
  subtotal: string
  tax_amount: string
  total: string
  currency: string
  posted_at: string
  is_voided: boolean
  fiscal_hash: string
  chain_sequence: number
}

/**
 * Get receipt details
 */
export async function getReceipt(id: string): Promise<ReceiptData> {
  return apiGet<ReceiptData>(`/pos/receipts/${id}`)
}

// §14.2 — `createReceipt`, `processReceiptPayments`,
// `CreateReceiptRequest`, `CreateReceiptResponse`,
// `ProcessReceiptPaymentsRequest`, `ProcessReceiptPaymentsResponse` were
// deleted as part of the new-sale server-authoring disposition. Do NOT
// re-export them; new-sale flows belong on the device + `/sync/fiscal-events`.

/**
 * Receipt detail with lines (for return modal)
 */
export interface ReceiptDetailData {
  id: string
  receipt_number: string
  receipt_type: 'sale' | 'return'
  original_receipt_id: string | null
  return_reason: string | null
  terminal_id: string
  cashier_name: string
  subtotal: string
  tax_amount: string
  total: string
  currency: string
  posted_at: string
  is_voided: boolean
  fiscal_hash: string
  chain_sequence: number
  lines: Array<{
    id: string
    line_number: number
    product_id: string | null
    composite_item_id: string | null
    product_code: string
    product_name: string
    quantity: string
    unit: string
    unit_price: string
    line_total: string
    tax_rate: string
    tax_amount: string
    discount_amount: string
    returned_quantity: string
  }>
}

/**
 * Get receipt details with lines
 */
export async function getReceiptDetail(id: string): Promise<ReceiptDetailData> {
  return apiGet<ReceiptDetailData>(`/pos/receipts/${id}`)
}

/**
 * Request structure for processing a return
 */
export interface ProcessReturnRequest {
  terminal_id: string
  return_reason: 'defective' | 'wrong_item' | 'customer_changed_mind' | 'other'
  lines: Array<{
    line_id: string
    quantity: string
  }>
  notes?: string | undefined
}

/**
 * Response structure for a return
 */
export interface ProcessReturnResponse {
  id: string
  receipt_number: string
  receipt_type: 'return'
  original_receipt_id: string
  return_reason: string
  subtotal: string
  tax_amount: string
  total: string
  currency: string
  posted_at: string
  lines: Array<{
    product_name: string
    quantity: string
    unit_price: string
    line_total: string
  }>
}

/**
 * Process a partial or full return on a receipt.
 * Creates a new negative receipt referencing the original.
 */
export async function processReturn(
  receiptId: string,
  data: ProcessReturnRequest
): Promise<ProcessReturnResponse> {
  return apiPost<ProcessReturnResponse>(
    `/pos/receipts/${receiptId}/return`,
    data
  )
}

/**
 * Print receipt - returns PDF blob for browser print dialog
 */
export async function printReceipt(receiptId: string): Promise<Blob> {
  const response = await api.get(`/pos/receipts/${receiptId}/pdf`, {
    responseType: 'blob',
  })

  return response.data as Blob
}

/**
 * Download receipt - triggers browser download
 */
export async function downloadReceipt(receiptId: string): Promise<void> {
  const response = await api.get(`/pos/receipts/${receiptId}/pdf/download`, {
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
