import { useTranslation } from 'react-i18next'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge/StatusBadge'
import type { ReplenishmentStatus } from '../types'

const statusTone: Record<ReplenishmentStatus, StatusTone> = {
  pending: 'pending',
  in_progress: 'info',
  fulfilled: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
}

export interface ReplenishmentStatusBadgeProps {
  status: ReplenishmentStatus
}

export function ReplenishmentStatusBadge({ status }: ReplenishmentStatusBadgeProps) {
  const { t } = useTranslation('replenishment')

  return <StatusBadge tone={statusTone[status]}>{t(`status.${status}`)}</StatusBadge>
}
