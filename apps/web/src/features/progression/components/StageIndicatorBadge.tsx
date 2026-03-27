import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'
import type { GrowthStage } from '../api/types'

interface StageIndicatorBadgeProps {
  stage: GrowthStage
  progressPercent: number
  className?: string
}

const stageColorMap: Record<GrowthStage, string> = {
  launch: tokens.badge.blue,
  stabilize: tokens.badge.yellow,
  optimize: tokens.badge.green,
  expand: tokens.badge.purple,
}

export function StageIndicatorBadge({ stage, progressPercent, className = '' }: StageIndicatorBadgeProps) {
  const { t } = useTranslation('progression')

  return (
    <span
      className={`
        ${tokens.badge.base}
        ${stageColorMap[stage]}
        ${className}
      `}
    >
      {t(`dashboard.stage.${stage}`)} — {Math.round(progressPercent)}%
    </span>
  )
}
