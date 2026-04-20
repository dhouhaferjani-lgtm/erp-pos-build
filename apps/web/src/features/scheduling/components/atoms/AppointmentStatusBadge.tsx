import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'
import type { AppointmentStatus } from '../../types'

interface AppointmentStatusBadgeProps {
  status: AppointmentStatus
}

/**
 * Maps an appointment status to a colored pill label. Atom: no data
 * fetching, no side effects; purely presentational.
 *
 * Colors live in `tokens.statusBadge` — each state is mapped to a semantic
 * slot (see designTokens.ts for the rationale).
 */
const STATUS_TOKEN: Record<AppointmentStatus, string> = {
  scheduled: tokens.statusBadge.scheduled,
  confirmed: tokens.statusBadge.confirmed,
  checked_in: tokens.statusBadge.checkedIn,
  in_progress: tokens.statusBadge.inProgress,
  completed: tokens.statusBadge.completed,
  closed: tokens.statusBadge.closed,
  no_show: tokens.statusBadge.noShow,
  cancelled: tokens.statusBadge.cancelled,
}

export function AppointmentStatusBadge({ status }: AppointmentStatusBadgeProps) {
  const { t } = useTranslation('scheduling')
  return (
    <span className={`${tokens.statusBadge.base} ${STATUS_TOKEN[status]}`}>
      {t(`status.${status}`)}
    </span>
  )
}
