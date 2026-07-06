import { apiPost } from '@/lib/api'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'

export interface LoyaltyBalance {
  enrolled: boolean
  balance: string
  tier: string | null
  rate: string | null
}

export async function fetchLoyaltyBalance(customer: AttachedCheckoutCustomer): Promise<LoyaltyBalance> {
  // apiPost unwraps response.data → the endpoint's {data:{...}} returns the inner object.
  return apiPost<LoyaltyBalance>('/loyalty/pos/balance', {
    partner_id: customer.id,
    phone: customer.phone,
  })
}

/**
 * Same find-or-create + enroll endpoint as `fetchLoyaltyBalance`, but posts an
 * explicit phone the cashier typed rather than the (possibly null) attached
 * customer's phone — this is how a phone-less customer gets enrolled.
 */
export async function enrollLoyalty(customerId: string, phone: string): Promise<LoyaltyBalance> {
  return apiPost<LoyaltyBalance>('/loyalty/pos/balance', {
    partner_id: customerId,
    phone,
  })
}
