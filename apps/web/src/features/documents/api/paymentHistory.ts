import { apiGet } from '../../../lib/api'

/**
 * Single payment allocation record
 */
export interface PaymentAllocation {
  id: string
  payment_id: string
  payment_reference: string | null
  payment_date: string
  payment_method: string | null
  amount: string
  created_at: string
}

/**
 * Credit note allocation record
 */
export interface CreditNoteAllocation {
  id: string
  credit_note_id: string
  credit_note_number: string
  amount: string
  allocated_by: {
    id: string
    name: string
  } | null
  created_at: string
}

/**
 * Payment history for a document
 */
export interface PaymentHistory {
  document_id: string
  document_number: string
  total: string
  balance_due: string
  payment_status: 'unpaid' | 'partially_paid' | 'in_payment' | 'paid' | 'overpaid'
  outstanding_amount: string
  payment_allocations: PaymentAllocation[]
  credit_note_allocations: CreditNoteAllocation[]
}

/**
 * Fetch payment history for a document
 *
 * Returns all payment allocations for the document, including payment details
 * like payment method, date, reference, and amount allocated.
 *
 * @param documentId - The document UUID
 * @returns Payment history with all allocations
 */
export async function fetchPaymentHistory(documentId: string): Promise<PaymentHistory> {
  return apiGet<PaymentHistory>(`/documents/${documentId}/payments`)
}
