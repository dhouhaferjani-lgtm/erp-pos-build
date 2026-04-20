import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '@/lib/designTokens'
import type { TransmissionType } from '../../types'

interface TransmissionBadgeProps {
  transmission: TransmissionType | null
  className?: string
}

export function TransmissionBadge({ transmission, className }: TransmissionBadgeProps) {
  const { t } = useTranslation('vehicle-ownership')

  if (transmission === null) {
    return null
  }

  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs ${borderColors.default} ${textColors.secondary} ${className ?? ''}`.trim()}
    >
      {t(`transmissionType.${transmission}`)}
    </span>
  )
}
