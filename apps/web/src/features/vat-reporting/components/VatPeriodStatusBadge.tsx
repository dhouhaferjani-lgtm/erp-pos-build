import { useTranslation } from 'react-i18next'
import { Badge, type BadgeVariant } from '@/components/atoms/Badge/Badge'
import type { VatPeriodStatus } from '../types'

interface VatPeriodStatusBadgeProps {
  status: VatPeriodStatus
}

const statusConfig: Record<VatPeriodStatus, { variant: BadgeVariant; labelKey: string }> = {
  OPEN: { variant: 'success', labelKey: 'finance:vatReporting.status.open' },
  CLOSED: { variant: 'warning', labelKey: 'finance:vatReporting.status.closed' },
  FILED: { variant: 'info', labelKey: 'finance:vatReporting.status.filed' },
}

export function VatPeriodStatusBadge({ status }: VatPeriodStatusBadgeProps) {
  const { t } = useTranslation('finance')
  const config = statusConfig[status]

  return (
    <Badge variant={config.variant}>
      {t(config.labelKey)}
    </Badge>
  )
}
