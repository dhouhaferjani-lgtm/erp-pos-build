import type { ReactElement } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui/Badge'
import { useCartStore } from '@/stores/cartStore'
import { useLoyaltyBalance } from '@/lib/loyalty/useLoyaltyBalance'
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore'

interface Props { customer: AttachedCheckoutCustomer }

export function CustomerLoyaltyBadge({ customer }: Props): ReactElement | null {
  const { t } = useTranslation('pos')
  const balance = useLoyaltyBalance(customer)
  const total = useCartStore((s) => s.total())

  if (balance === null) return null // module off / offline / unsynced / call failed

  // Display-only estimate (never persisted): floor(cart TTC total × rate from the balance response).
  const rate = balance.rate
  const estimate = rate !== null ? Math.floor(total * Number(rate)) : null

  return (
    <div data-testid="loyalty-chrome" className="flex flex-wrap items-center gap-1">
      {balance.enrolled && (
        <>
          {balance.tier !== null && <Badge tone="action">{balance.tier}</Badge>}
          <Badge tone="neutral" data-testid="loyalty-balance">
            {t('loyalty.points', { amount: Math.floor(Number(balance.balance)) })}
          </Badge>
        </>
      )}
      {estimate !== null && (
        <Badge tone="success" data-testid="loyalty-estimate">
          {t('loyalty.earnEstimate', { points: estimate })}
        </Badge>
      )}
      {!balance.enrolled && (
        <span className="text-xs text-muted-foreground">{t('loyalty.joinsOnPurchase')}</span>
      )}
    </div>
  )
}
