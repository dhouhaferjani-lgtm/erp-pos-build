import { useState, type ReactElement } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { useCartStore } from '@/stores/cartStore'
import { useLoyaltyBalance } from '@/lib/loyalty/useLoyaltyBalance'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'
import { LoyaltyEnrollDialog } from './LoyaltyEnrollDialog'

interface Props { customer: AttachedCheckoutCustomer }

export function CustomerLoyaltyBadge({ customer }: Props): ReactElement | null {
  const { t } = useTranslation('pos')
  const { balance, refresh } = useLoyaltyBalance(customer)
  const total = useCartStore((s) => s.total())
  const [enrollOpen, setEnrollOpen] = useState(false)

  if (balance === null) return null // module off / offline / unsynced / call failed

  // Display-only estimate (never persisted): floor(cart TTC total × rate from the balance response).
  const rate = balance.rate
  const estimate = rate !== null ? Math.floor(total * Number(rate)) : null

  // Nothing to show when not enrolled and there is no rate (no active program estimate).
  if (!balance.enrolled && estimate === null) return null

  return (
    // min-w-0: lets this cluster shrink below its own content width inside
    // the customer chip's flex row (see CartCustomerControl) instead of
    // forcing the chip to overflow; flex-wrap then drops badges to a second
    // line rather than pushing width further.
    <div data-testid="loyalty-chrome" className="flex min-w-0 flex-wrap items-center gap-1">
      {balance.enrolled && (
        <>
          {balance.tier !== null && <Badge tone="action">{balance.tier}</Badge>}
          <Badge tone="neutral" data-testid="loyalty-balance">
            {t('loyalty.points', { amount: Math.floor(Number(balance.balance)) })}
          </Badge>
        </>
      )}
      {estimate !== null && (
        // Strategy A: success (green) is reserved for confirmed money/sync/
        // stock — this is a projected POINTS count (not money, not
        // confirmed), so it stays neutral like the points balance above.
        <Badge tone="neutral" data-testid="loyalty-estimate">
          {t('loyalty.earnEstimate', { points: estimate })}
        </Badge>
      )}
      {!balance.enrolled && (
        <>
          <Button variant="secondary" size="sm" onClick={() => setEnrollOpen(true)}>
            {t('loyalty.enroll')}
          </Button>
          <LoyaltyEnrollDialog
            open={enrollOpen}
            customer={customer}
            onClose={() => setEnrollOpen(false)}
            onEnrolled={() => { setEnrollOpen(false); refresh() }}
          />
        </>
      )}
    </div>
  )
}
