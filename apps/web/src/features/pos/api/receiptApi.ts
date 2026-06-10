import { api, apiGet } from '@/lib/api'

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
 * Refund Phase 6 — the web-admin return surface (ReceiptSearchPage +
 * ReturnItemsModal) was quarantined; `getReceiptDetail` / `processReturn`
 * were deleted with it. The BACKEND `POST /pos/receipts/{id}/return`
 * route stays — the desktop POS refund flow uses it.
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
