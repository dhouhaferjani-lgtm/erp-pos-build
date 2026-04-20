import { useTranslation } from 'react-i18next'
import { tokens, textColors } from '@/lib/designTokens'
import type { AppointmentType } from '../../types'

interface AppointmentTypeChipProps {
  type: AppointmentType
}

/**
 * Small chip labelling the appointment type (diagnostic, tire_service, …).
 * Atom: presentational only.
 */
export function AppointmentTypeChip({ type }: AppointmentTypeChipProps) {
  const { t } = useTranslation('scheduling')
  return (
    <span
      className={`${tokens.badge.base} ${tokens.badge.gray} ${textColors.secondary}`}
    >
      {t(`appointmentType.${type}`)}
    </span>
  )
}
