import { apiGet, apiPost } from '@/lib/api'

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

/**
 * Request structure for creating a receipt
 */
export interface CreateReceiptRequest {
  terminal_id: string
  lines: Array<{
    product_id: string
    quantity: number
    unit_price: string
    discount_type?: 'percentage' | 'fixed' | null
    discount_percent?: string
    discount_amount?: string
    discount_reason?: string
  }>
  customer_id?: string
  transaction_discount_amount?: string
  transaction_discount_reason?: string
}

/**
 * Response structure when creating a receipt
 */
export interface CreateReceiptResponse {
  id: string
  receipt_number: string
  total: string
  subtotal: string
  tax_amount: string
  discount_amount: string
  currency: string
}

/**
 * Create a new POS receipt from cart items
 */
export async function createReceipt(
  data: CreateReceiptRequest
): Promise<CreateReceiptResponse> {
  return apiPost<CreateReceiptResponse>('/pos/receipts', data)
}

/**
 * Request structure for processing receipt payments
 */
export interface ProcessReceiptPaymentsRequest {
  payments: Array<{
    payment_method_id: string
    amount: number
    repository_id: string
    card_last_four?: string
    transaction_reference?: string
    authorization_code?: string
  }>
  customer_id?: string
}

/**
 * Response structure when processing payments
 */
export interface ProcessReceiptPaymentsResponse {
  receipt: {
    id: string
    receipt_number: string
    total: string
  }
  receipt_payments: Array<{
    id: string
    payment_method_id: string
    amount: string
  }>
  treasury_payments: Array<{
    id: string
    journal_entry_id: string
  }>
  change_due: string
}

/**
 * Process payments for a receipt
 *
 * Creates Treasury Payment records and GL entries.
 * Supports split payments across multiple payment methods.
 */
export async function processReceiptPayments(
  receiptId: string,
  data: ProcessReceiptPaymentsRequest
): Promise<ProcessReceiptPaymentsResponse> {
  return apiPost<ProcessReceiptPaymentsResponse>(
    `/pos/receipts/${receiptId}/payments`,
    data
  )
}

/**
 * Print receipt - returns PDF blob for browser print dialog
 */
export async function printReceipt(receiptId: string): Promise<Blob> {
  const response = await fetch(`/api/v1/pos/receipts/${receiptId}/pdf`, {
    headers: {
      Authorization: `Bearer ${localStorage.getItem('token')}`,
      'Accept-Language': localStorage.getItem('autoerp-language') ?? 'en',
    },
    credentials: 'include',
  })

  if (!response.ok) {
    throw new Error('Failed to generate receipt PDF')
  }

  return response.blob()
}

/**
 * Download receipt - triggers browser download
 */
export async function downloadReceipt(receiptId: string): Promise<void> {
  const response = await fetch(`/api/v1/pos/receipts/${receiptId}/pdf/download`, {
    headers: {
      Authorization: `Bearer ${localStorage.getItem('token')}`,
      'Accept-Language': localStorage.getItem('autoerp-language') ?? 'en',
    },
    credentials: 'include',
  })

  if (!response.ok) {
    throw new Error('Failed to download receipt PDF')
  }

  const blob = await response.blob()
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `receipt-${receiptId}.pdf`
  document.body.appendChild(a)
  a.click()
  window.URL.revokeObjectURL(url)
  document.body.removeChild(a)
}
