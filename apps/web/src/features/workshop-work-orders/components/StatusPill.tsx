import { useTranslation } from 'react-i18next'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge'
import type { WorkOrderStatus } from '../types'

interface StatusPillProps {
  status: WorkOrderStatus
}

/**
 * Work-order status → semantic tone. Replaces the previous bespoke off-theme
 * color map (emerald/amber/slate/sky/...) by mapping each lifecycle state onto
 * the sanctioned {@link StatusTone} set and rendering through `StatusBadge`.
 */
const STATUS_TONE: Record<WorkOrderStatus, StatusTone> = {
  received: 'neutral',
  diagnosed: 'info',
  quoted: 'warning',
  approved: 'success',
  in_progress: 'info',
  paused: 'warning',
  waiting_parts: 'warning',
  completed: 'success',
  invoiced: 'info',
  closed: 'neutral',
  cancelled: 'danger',
}

export function StatusPill({ status }: StatusPillProps) {
  const { t } = useTranslation('workshop-work-orders')
  return <StatusBadge tone={STATUS_TONE[status]}>{t(`status.${status}`)}</StatusBadge>
}
