import { useTranslation } from 'react-i18next'

interface FulfillmentStatusBadgeProps {
  status: 'not_fulfilled' | 'partially_fulfilled' | 'fulfilled' | 'not_applicable'
  className?: string
}

/**
 * Badge component for displaying fulfillment status
 *
 * Shows delivery/shipment status for sales documents (invoices, orders).
 * Uses color-coded badges following the design system.
 */
export function FulfillmentStatusBadge({ status, className = '' }: FulfillmentStatusBadgeProps) {
  const { t } = useTranslation(['sales'])

  const getStatusConfig = (status: FulfillmentStatusBadgeProps['status']) => {
    switch (status) {
      case 'fulfilled':
        return {
          label: t('sales:fulfillment.fulfilled'),
          color: 'bg-green-100 text-green-800',
        }
      case 'partially_fulfilled':
        return {
          label: t('sales:fulfillment.partiallyFulfilled'),
          color: 'bg-yellow-100 text-yellow-800',
        }
      case 'not_fulfilled':
        return {
          label: t('sales:fulfillment.notFulfilled'),
          color: 'bg-gray-100 text-gray-800',
        }
      case 'not_applicable':
        return {
          label: t('sales:fulfillment.notApplicable'),
          color: 'bg-gray-100 text-gray-600',
        }
      default:
        return {
          label: status,
          color: 'bg-gray-100 text-gray-800',
        }
    }
  }

  const config = getStatusConfig(status)

  return (
    <span
      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${config.color} ${className}`}
    >
      {config.label}
    </span>
  )
}
