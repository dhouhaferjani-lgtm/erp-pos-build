import { useTranslation } from 'react-i18next'
import { textColors, colors, borderColors } from '@/lib/designTokens'
import { ModuleCard } from './ModuleCard'
import type { ModuleReadiness, GrowthStage } from '../api/types'

interface StageSectionProps {
  stage: GrowthStage
  modules: ModuleReadiness[]
  isCurrentStage: boolean
}

export function StageSection({ stage, modules, isCurrentStage }: StageSectionProps) {
  const { t } = useTranslation('progression')

  if (modules.length === 0) {
    return null
  }

  return (
    <div className="relative pl-8">
      {/* Timeline line */}
      <div className={`absolute left-3 top-0 bottom-0 w-0.5 ${colors.neutral[200]}`} />

      {/* Timeline dot */}
      <div
        className={`absolute left-1.5 top-1 h-4 w-4 rounded-full border-2 ${
          isCurrentStage
            ? `${borderColors.primary} ${colors.primary[500]}`
            : `${borderColors.default} ${colors.white}`
        }`}
      />

      <div className="pb-8">
        <h3 className={`text-base font-semibold ${isCurrentStage ? textColors.brand : textColors.secondary} mb-3`}>
          {t(`dashboard.stage.${stage}`)}
        </h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {modules.map((mod) => (
            <ModuleCard key={mod.id} module={mod} />
          ))}
        </div>
      </div>
    </div>
  )
}
