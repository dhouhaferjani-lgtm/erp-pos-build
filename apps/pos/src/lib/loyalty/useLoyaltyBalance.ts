import { useEffect, useState } from 'react'
import { useHasModule } from '@/stores/productStore'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'
import { fetchLoyaltyBalance, type LoyaltyBalance } from './loyaltyApi'

/** Online-only: returns null unless module on + customer server-synced + the call succeeds. */
export function useLoyaltyBalance(customer: AttachedCheckoutCustomer | null): LoyaltyBalance | null {
  const hasLoyalty = useHasModule('Loyalty')
  const [balance, setBalance] = useState<LoyaltyBalance | null>(null)

  useEffect(() => {
    let active = true
    setBalance(null)
    const eligible = hasLoyalty && customer !== null && customer.customer_sync_status === 'synced'
    if (!eligible) return
    fetchLoyaltyBalance(customer)
      .then((b) => { if (active) setBalance(b) })
      .catch(() => { if (active) setBalance(null) }) // offline/network ⇒ no chrome
    return () => { active = false }
  }, [hasLoyalty, customer])

  return balance
}
