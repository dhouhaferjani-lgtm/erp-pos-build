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
