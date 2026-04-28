import { useTranslation } from 'react-i18next'
import { tokens, textColors } from '@/lib/designTokens'
import { ReadinessBadge, ProgressBar } from '@/components/atoms'
import type { ModuleReadiness, ModuleReadinessStatus } from '../api/types'
import { useActivateModule } from '../hooks/useModuleReadiness'

interface ModuleCardProps {
  module: ModuleReadiness
}

function statusToReadiness(status: ModuleReadinessStatus) {
  return status
}

function statusLabel(status: ModuleReadinessStatus, t: (key: string) => string): string {
  const map: Record<ModuleReadinessStatus, string> = {
    active: t('modules.active'),
    ready: t('modules.ready'),
    available: t('modules.available'),
    locked: t('modules.locked'),
  }
  return map[status]
}

export function ModuleCard({ module }: ModuleCardProps) {
  const { t } = useTranslation('progression')
  const activateMutation = useActivateModule()

  const canActivate = module.status === 'ready'
  const isLocked = module.status === 'locked'

  return (
    <div className={`${tokens.card.base} ${tokens.card.hover} flex flex-col gap-3 ${isLocked ? 'opacity-60' : ''}`}>
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-2">
          <span className="text-2xl">{module.icon}</span>
          <h3 className={`${textColors.primary} text-sm font-semibold`}>{module.name}</h3>
        </div>
        <ReadinessBadge status={statusToReadiness(module.status)}>
          {statusLabel(module.status, t)}
        </ReadinessBadge>
      </div>

      <p className={`${textColors.tertiary} text-xs`}>{module.description}</p>

      <div>
        <div className="flex items-center justify-between mb-1">
          <span className={`${textColors.tertiary} text-xs`}>
            {t('modules.readyPercent', { percent: Math.round(module.readiness_percent) })}
          </span>
          {module.discount_percent > 0 && (
            <span className={`text-xs ${textColors.success} font-medium`}>
              {t('modules.discountAvailable', { percent: module.discount_percent })}
            </span>
          )}
        </div>
        <ProgressBar
          percent={module.readiness_percent}
          size="sm"
          variant={module.status === 'active' ? 'success' : 'primary'}
        />
      </div>

      {isLocked && module.requirements.length > 0 && (
        <p className={`text-xs ${textColors.tertiary}`}>
          {t('modules.needsRequirements', { requirements: module.requirements.join(', ') })}
        </p>
      )}

      {canActivate && (
        <button
          type="button"
          onClick={() => activateMutation.mutate(module.id)}
          disabled={activateMutation.isPending}
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm} mt-auto`}
        >
          {t('modules.activate')}
        </button>
      )}
    </div>
  )
}
