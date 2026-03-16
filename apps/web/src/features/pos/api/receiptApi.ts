import { api, apiGet, apiPost } from '@/lib/api'

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
    product_id?: string | undefined
    composite_item_id?: string | undefined
    quantity: number
    unit_price: string
    modifiers?: Array<{
      modifier_id: string
      modifier_group_id: string
      price_adjustment: string
    }> | undefined
    discount_type?: 'percentage' | 'fixed' | null | undefined
    discount_percent?: string | undefined
    discount_amount?: string | undefined
    discount_reason?: string | undefined
  }>
  customer_id?: string | undefined
  contact_id?: string | undefined
  notes?: string | undefined
  transaction_discount_amount?: string | undefined
  transaction_discount_reason?: string | undefined
  coupon_code?: string | undefined
  loyalty_discount_amount?: string | undefined
  loyalty_reward_id?: string | undefined
  consumption_mode?: string | undefined
  table_id?: string | undefined
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
    card_last_four?: string | undefined
    transaction_reference?: string | undefined
    authorization_code?: string | undefined
  }>
  customer_id?: string | undefined
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
