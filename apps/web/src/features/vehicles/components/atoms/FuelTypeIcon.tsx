import { Fuel } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { textColors } from '@/lib/designTokens'
import type { FuelType } from '../../types'

interface FuelTypeIconProps {
  fuelType: FuelType | null
  className?: string
}

export function FuelTypeIcon({ fuelType, className }: FuelTypeIconProps) {
  const { t } = useTranslation('vehicle-ownership')

  if (fuelType === null) {
    return null
  }

  const label = t(`fuelType.${fuelType}`)

  return (
    <span
      className={`inline-flex items-center gap-1 ${textColors.secondary} ${className ?? ''}`.trim()}
      aria-label={label}
      title={label}
    >
      <Fuel className="h-4 w-4" aria-hidden="true" />
      <span>{label}</span>
    </span>
  )
}
