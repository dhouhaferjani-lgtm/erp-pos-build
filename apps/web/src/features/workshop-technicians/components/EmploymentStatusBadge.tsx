import { useTranslation } from 'react-i18next'
import type { EmploymentStatus } from '../api/types'
import { StatusBadge, statusTone } from '@/components/atoms/StatusBadge'

interface EmploymentStatusBadgeProps {
  status: EmploymentStatus
}

/**
 * Employment-status badge. Maps the lifecycle to the shared semantic tone set:
 * active → success, on_leave → warning, terminated → neutral. No off-theme
 * palettes — tones resolve to design-token classes inside `StatusBadge`.
 */
const TONE_OVERRIDES = {
  on_leave: 'warning',
  terminated: 'neutral',
} as const

export function EmploymentStatusBadge({ status }: EmploymentStatusBadgeProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <StatusBadge tone={statusTone(status, TONE_OVERRIDES)}>
      {t(`employmentStatus.${status}`)}
    </StatusBadge>
  )
}
