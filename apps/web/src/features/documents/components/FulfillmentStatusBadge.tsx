import { useTranslation } from 'react-i18next'
import { StatusBadge, type StatusTone } from '../../../components/atoms/StatusBadge/StatusBadge'

interface FulfillmentStatusBadgeProps {
  status: 'not_fulfilled' | 'partially_fulfilled' | 'fulfilled' | 'not_applicable'
  className?: string
}

const statusTones: Record<FulfillmentStatusBadgeProps['status'], StatusTone> = {
  fulfilled: 'success',
  partially_fulfilled: 'warning',
  not_fulfilled: 'neutral',
  not_applicable: 'neutral',
}

const statusLabelKeys: Record<FulfillmentStatusBadgeProps['status'], string> = {
  fulfilled: 'sales:fulfillment.fulfilled',
  partially_fulfilled: 'sales:fulfillment.partiallyFulfilled',
  not_fulfilled: 'sales:fulfillment.notFulfilled',
  not_applicable: 'sales:fulfillment.notApplicable',
}

/**
 * Badge component for displaying fulfillment status
 *
 * Shows delivery/shipment status for sales documents (invoices, orders).
 * Renders through the sanctioned StatusBadge tones.
 */
export function FulfillmentStatusBadge({ status, className = '' }: FulfillmentStatusBadgeProps) {
  const { t } = useTranslation(['sales'])

  return (
    <StatusBadge tone={statusTones[status]} className={className}>
      {t(statusLabelKeys[status])}
    </StatusBadge>
  )
}
