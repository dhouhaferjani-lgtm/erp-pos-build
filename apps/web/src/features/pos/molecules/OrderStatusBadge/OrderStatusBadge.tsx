import { useTranslation } from 'react-i18next'

export interface OrderStatusBadgeProps {
  status: 'open' | 'sent_to_kitchen' | 'ready' | 'closed' | 'cancelled'
}

const statusStyles: Record<string, string> = {
  open: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
  sent_to_kitchen:
    'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
  ready:
    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
  closed: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300',
  cancelled: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
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
