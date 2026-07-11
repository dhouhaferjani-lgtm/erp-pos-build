import { useTranslation } from 'react-i18next'
import { StatusBadge, statusTone, type StatusTone } from '@/components/atoms/StatusBadge'

export interface OrderStatusBadgeProps {
  status: 'open' | 'sent_to_kitchen' | 'ready' | 'closed' | 'cancelled'
}

const orderToneOverrides: Record<string, StatusTone> = {
  open: 'info',
  sent_to_kitchen: 'warning',
  ready: 'success',
  closed: 'neutral',
  cancelled: 'danger',
}

/**
 * Color-coded badge displaying order status.
 */
export function OrderStatusBadge({ status }: OrderStatusBadgeProps) {
  const { t } = useTranslation('pos')

  return (
    <StatusBadge tone={statusTone(status, orderToneOverrides)}>
      {t(`orders.status.${status}`)}
    </StatusBadge>
  )
}
