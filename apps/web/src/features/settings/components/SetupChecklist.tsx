import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Check, AlertTriangle, Circle, ArrowRight } from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { Spinner } from '@/components/atoms/Spinner'
import { ProgressBar } from '@/components/atoms/ProgressBar'
import { StatusBadge } from '@/components/atoms/StatusBadge'
import { fetchOnboardingStatus, type OnboardingItem } from '../api/onboardingApi'

export function SetupChecklist() {
  const { t } = useTranslation('settings')
  const navigate = useNavigate()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data: items = [], isLoading } = useQuery({
    queryKey: tenantScopedKey(['onboarding-status']),
    queryFn: fetchOnboardingStatus,
    enabled: tenantId !== null && companyId !== null,
  })

  const completedCount = items.filter((item) => item.completed).length
  const totalCount = items.length
  const progressPercent = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0

  const hasIncompleteRequired = items.some((item) => item.required && !item.completed)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <Spinner size="md" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className={tokens.heading.section}>{t('onboarding.title')}</h2>
        <p className={cn('mt-1 text-sm', textColors.tertiary)}>{t('onboarding.description')}</p>
      </div>

      {/* Progress bar */}
      <div className={cn(tokens.card.base)}>
        <div className="mb-2 flex items-center justify-between">
          <span className={cn('text-sm font-medium', textColors.secondary)}>
            {t('onboarding.progressLabel', { completed: completedCount, total: totalCount })}
          </span>
          <span className={cn('text-sm font-semibold', textColors.primary)}>{`${String(progressPercent)}%`}</span>
        </div>
        <ProgressBar percent={progressPercent} size="sm" variant="success" />
      </div>

      {/* Required steps alert */}
      {hasIncompleteRequired && (
        <div className={cn('flex items-start gap-3', tokens.alert.base, tokens.alert.error)}>
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
          <p className="font-medium">{t('onboarding.requiredStepsAlert')}</p>
        </div>
      )}

      {/* Checklist items */}
      <div
        className={cn(
          'divide-y rounded-lg border',
          colors.white,
          borderColors.light,
          borderColors.divideLight,
        )}
      >
        {items.map((item: OnboardingItem) => (
          <button
            key={item.step}
            type="button"
            onClick={() => { void navigate(item.settings_path); }}
            className={cn(
              'flex w-full items-center gap-4 px-6 py-4 text-left transition-colors',
              tokens.table.rowHover,
            )}
          >
            {/* Status icon */}
            <span className="shrink-0">
              {item.completed ? (
                <Check className={cn('h-5 w-5', textColors.success)} aria-hidden="true" />
              ) : item.required ? (
                <AlertTriangle className={cn('h-5 w-5', textColors.warningDark)} aria-hidden="true" />
              ) : (
                <Circle className={cn('h-5 w-5', textColors.disabled)} aria-hidden="true" />
              )}
            </span>

            {/* Label */}
            <span className={cn('flex-1 text-sm font-medium', textColors.primary)}>
              {t(`onboarding.steps.${item.step}`)}
            </span>

            {/* Badge */}
            <StatusBadge tone={item.required ? 'danger' : 'neutral'}>
              {item.required ? t('onboarding.badges.required') : t('onboarding.badges.optional')}
            </StatusBadge>

            {/* Arrow */}
            <ArrowRight className={cn('h-4 w-4 shrink-0', textColors.disabled)} aria-hidden="true" />
          </button>
        ))}
      </div>

      {/* All done message */}
      {!hasIncompleteRequired && totalCount > 0 && (
        <div className={cn('flex items-center gap-3', tokens.alert.base, tokens.alert.success)}>
          <Check className="h-5 w-5 shrink-0" aria-hidden="true" />
          <p className="font-medium">{t('onboarding.allDone')}</p>
        </div>
      )}
    </div>
  )
}
