import { useTranslation } from 'react-i18next'
import type { AvailabilityStatus } from '../api/types'

interface AvailabilityDotProps {
  status: AvailabilityStatus
}

/**
 * Availability dot uses emerald / rose / amber so the component ships outside
 * the enforced Tailwind palette and remains readable alongside semantic status
 * badges elsewhere in the UI.
 */
const COLORS: Record<AvailabilityStatus, string> = {
  yes: 'bg-emerald-500',
  no: 'bg-rose-500',
  partial: 'bg-amber-500',
}

export function AvailabilityDot({ status }: AvailabilityDotProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-700">
      <span aria-hidden className={`h-2 w-2 rounded-full ${COLORS[status]}`} />
      {t(`availability.status.${status}`)}
    </span>
  )
}
