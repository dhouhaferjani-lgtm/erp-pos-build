import { Gauge } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { textColors } from '@/lib/designTokens'

interface MileageLabelProps {
  mileage: number | null
}

/**
 * Compact odometer reading display. Renders a dash when no mileage recorded.
 */
export function MileageLabel({ mileage }: MileageLabelProps) {
  const { t } = useTranslation('workshop-work-orders')
  return (
    <span className={`inline-flex items-center gap-1 text-xs ${textColors.tertiary}`}>
      <Gauge className="h-3 w-3" aria-hidden />
      {mileage === null ? t('labels.notSet') : t('labels.mileageKm', { value: mileage.toLocaleString() })}
    </span>
  )
}
