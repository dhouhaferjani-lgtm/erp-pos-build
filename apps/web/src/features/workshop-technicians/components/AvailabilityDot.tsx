import { useTranslation } from 'react-i18next'
import type { AvailabilityStatus } from '../api/types'
import { colors, textColors } from '@/lib/designTokens'

interface AvailabilityDotProps {
  status: AvailabilityStatus
}

/**
 * Availability dot. Fills map to the semantic color palette: yes → success,
 * no → error, partial → warning. No off-theme palettes.
 */
const COLORS: Record<AvailabilityStatus, string> = {
  yes: colors.success[600],
  no: colors.error[600],
  partial: colors.warning[600],
}

export function AvailabilityDot({ status }: AvailabilityDotProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <span
      className={`inline-flex items-center gap-1.5 text-xs font-medium ${textColors.secondary}`}
    >
      <span aria-hidden className={`h-2 w-2 rounded-full ${COLORS[status]}`} />
      {t(`availability.status.${status}`)}
    </span>
  )
}
