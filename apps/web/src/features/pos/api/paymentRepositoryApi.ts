import { apiGet } from '@/lib/api'

/**
 * Payment Repository from Treasury Module
 *
 * Represents a physical or virtual storage for payments
 * (e.g., cash register, safe, bank account).
 */
export interface PaymentRepository {
  id: string
  code: string
  name: string
  type: 'cash_register' | 'safe' | 'bank_account' | 'virtual'
  bank_name: string | null
  account_number: string | null
  iban: string | null
  bic: string | null
  balance: string
  is_active: boolean
}

/**
 * Fetch all active payment repositories for the current tenant.
 *
 * Returns repositories ordered by name.
 */
export async function fetchPaymentRepositories(): Promise<PaymentRepository[]> {
  return apiGet<PaymentRepository[]>('/payment-repositories')
}
