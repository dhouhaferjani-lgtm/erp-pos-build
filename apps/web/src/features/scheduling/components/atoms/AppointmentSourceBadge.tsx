import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'
import type { AppointmentSource } from '../../types'

interface AppointmentSourceBadgeProps {
  source: AppointmentSource
}

const STYLES: Record<AppointmentSource, string> = {
  manual: tokens.badge.gray,
  phone: tokens.badge.blue,
  online: tokens.badge.purple,
  walkin: tokens.badge.yellow,
}

/**
 * Pill that labels where the appointment came from (manual / phone / online /
 * walkin). Atom: pure presentational mapping, no side effects.
 */
export function AppointmentSourceBadge({ source }: AppointmentSourceBadgeProps) {
  const { t } = useTranslation('scheduling')
  return (
    <span className={`${tokens.badge.base} ${STYLES[source]}`}>{t(`source.${source}`)}</span>
  )
}
