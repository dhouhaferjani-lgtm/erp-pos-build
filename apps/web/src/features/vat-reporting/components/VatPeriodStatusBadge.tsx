import { useTranslation } from 'react-i18next'
import { StatusBadge, type StatusTone, statusTone } from '@/components/atoms/StatusBadge'
import type { VatPeriodStatus } from '../types'

interface VatPeriodStatusBadgeProps {
  status: VatPeriodStatus
}

const vatPeriodToneOverrides: Record<VatPeriodStatus, StatusTone> = {
  OPEN: 'success',
  CLOSED: 'warning',
  FILED: 'info',
}

const vatPeriodLabelKeys: Record<VatPeriodStatus, string> = {
  OPEN: 'finance:vatReporting.status.open',
  CLOSED: 'finance:vatReporting.status.closed',
  FILED: 'finance:vatReporting.status.filed',
}

export function VatPeriodStatusBadge({ status }: VatPeriodStatusBadgeProps) {
  const { t } = useTranslation('finance')

  return (
    <StatusBadge tone={statusTone(status, vatPeriodToneOverrides)}>
      {t(vatPeriodLabelKeys[status])}
    </StatusBadge>
  )
}
