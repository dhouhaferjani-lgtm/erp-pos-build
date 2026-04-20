import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '@/lib/designTokens'
import type { BodyType } from '../../types'

interface BodyTypeBadgeProps {
  bodyType: BodyType | null
  className?: string
}

export function BodyTypeBadge({ bodyType, className }: BodyTypeBadgeProps) {
  const { t } = useTranslation('vehicle-ownership')

  if (bodyType === null) {
    return null
  }

  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs ${borderColors.default} ${textColors.secondary} ${className ?? ''}`.trim()}
    >
      {t(`bodyType.${bodyType}`)}
    </span>
  )
}
