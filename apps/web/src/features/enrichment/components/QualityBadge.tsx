import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'

interface QualityBadgeProps {
  quality: 'high' | 'medium' | 'low'
}

const qualityStyles: Record<string, string> = {
  high: tokens.badge.green,
  medium: tokens.badge.yellow,
  low: tokens.badge.red,
}

export function QualityBadge({ quality }: QualityBadgeProps) {
  const { t } = useTranslation('enrichment')
  return (
    <span className={`${tokens.badge.base} ${qualityStyles[quality]}`}>
      {t(`quality.${quality}`)}
    </span>
  )
}
