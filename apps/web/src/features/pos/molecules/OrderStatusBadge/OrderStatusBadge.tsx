import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'

export interface OrderStatusBadgeProps {
  status: 'open' | 'sent_to_kitchen' | 'ready' | 'closed' | 'cancelled'
}

const statusStyles: Record<string, string> = {
  open: tokens.badge.blue,
  sent_to_kitchen: tokens.badge.yellow,
  ready: tokens.badge.green,
  closed: tokens.badge.gray,
  cancelled: tokens.badge.red,
}

/**
 * Color-coded badge displaying order status.
 */
export function OrderStatusBadge({ status }: OrderStatusBadgeProps) {
  const { t } = useTranslation('pos')

  const style = statusStyles[status] ?? statusStyles['open']

  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${style}`}
    >
      {t(`orders.status.${status}`)}
    </span>
  )
}
