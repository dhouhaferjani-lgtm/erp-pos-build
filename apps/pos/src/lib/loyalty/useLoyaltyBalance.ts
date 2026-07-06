import { useEffect, useState } from 'react'
import { useHasModule } from '@/stores/productStore'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'
import { fetchLoyaltyBalance, type LoyaltyBalance } from './loyaltyApi'

export interface UseLoyaltyBalanceResult {
  balance: LoyaltyBalance | null
  /** Re-fetches the balance (e.g. after `enrollLoyalty` resolves — the member
   * now resolves by partner id, so a fresh fetch reports `enrolled: true`
   * without needing the phone). */
  refresh: () => void
}

/** Online-only: balance is null unless module on + customer server-synced + the call succeeds. */
export function useLoyaltyBalance(customer: AttachedCheckoutCustomer | null): UseLoyaltyBalanceResult {
  const hasLoyalty = useHasModule('Loyalty')
  const [balance, setBalance] = useState<LoyaltyBalance | null>(null)
  const [nonce, setNonce] = useState(0)

  useEffect(() => {
    let active = true
    setBalance(null)
    const eligible = hasLoyalty && customer !== null && customer.customer_sync_status === 'synced'
    if (!eligible) return
    fetchLoyaltyBalance(customer)
      .then((b) => { if (active) setBalance(b) })
      .catch(() => { if (active) setBalance(null) }) // offline/network ⇒ no chrome
    return () => { active = false }
  }, [hasLoyalty, customer, nonce])

  return { balance, refresh: () => setNonce((n) => n + 1) }
}
