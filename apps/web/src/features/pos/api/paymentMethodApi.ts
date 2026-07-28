import { apiGet } from '@/lib/api'

/**
 * Payment Method from Treasury Module
 *
 * Represents a payment method configured for the tenant.
 */
export interface PaymentMethod {
  id: string
  code: string
  name: string
  is_physical: boolean
  /**
   * Canonical cash-ness predicate (cash-rounding spec §4.1). True implies
   * `code === 'CASH'` exactly — the server enforces the invariant on write.
   */
  is_cash_tender: boolean
  has_maturity: boolean
  requires_third_party: boolean
  is_push: boolean
  has_deducted_fees: boolean
  is_restricted: boolean
  fee_type: string | null
  fee_fixed: string
  fee_percent: string
  restriction_type: string | null
  is_active: boolean
  position: number
}

/**
 * Fetch all active payment methods for the current tenant.
 *
 * Returns payment methods ordered by position and name.
 */
export async function fetchPaymentMethods(): Promise<PaymentMethod[]> {
  return apiGet<PaymentMethod[]>('/payment-methods')
}
