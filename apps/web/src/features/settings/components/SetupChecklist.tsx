import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Check, AlertTriangle, Circle, ArrowRight, HelpCircle } from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { Spinner } from '@/components/atoms/Spinner'
import { Button } from '@/components/atoms/Button'
import { ProgressBar } from '@/components/atoms/ProgressBar'
import { StatusBadge } from '@/components/atoms/StatusBadge'
import { fetchOnboardingStatus, type OnboardingItem } from '../api/onboardingApi'

export function SetupChecklist() {
  const { t } = useTranslation(['settings', 'common'])
  const navigate = useNavigate()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data: items = [], isPending, isError, refetch } = useQuery({
    queryKey: tenantScopedKey(['onboarding-status']),
    queryFn: fetchOnboardingStatus,
    enabled: tenantId !== null && companyId !== null,
  })

  const completedCount = items.filter((item) => item.completed).length
  const totalCount = items.length
  const progressPercent = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0

  // A degraded step is NOT complete and NOT actionable — count it with the
  // unresolved steps so `allDone` cannot appear while any step is unknown.
  // Excluded from the "required steps" alert below: a degraded step is
  // UNKNOWN, not "you still have work to do", so it must not trip the red
  // banner on a row the list itself labels "Unavailable" (FE gate round 2,
  // MINOR-R2-2, 2026-08-06).
  const hasIncompleteRequired = items.some((item) => item.required && !item.completed && !item.degraded)
  const hasDegraded = items.some((item) => item.degraded)

  // Gate on isPending, not isLoading: `isLoading === isPending && isFetching`,
  // so while the query is DISABLED (company store not hydrated yet, or a
  // principal with no company) it is pending + idle and isLoading is false —
  // the component would fall through to the item list with `items = []` and
  // render the empty "0 of 0 completed" checklist this component exists to
  // avoid (FE gate 2026-08-06, MAJOR-1).
  if (isPending) {
    return (
      <div className="flex items-center justify-center p-8">
        <Spinner size="md" />
      </div>
    )
  }

  // BUG-005 / RCA B3 — a failed request leaves `data` at its `[]` default, so
  // the component used to render its ordinary body: an empty item list and a
  // "0 of 0 completed" progress bar, with no "required steps" alert and no hint
  // that anything had gone wrong. A failure must look like a failure.
  if (isError) {
    return (
      <div className="space-y-6">
        <div>
          <h2 className={tokens.heading.section}>{t('onboarding.title')}</h2>
          <p className={cn('mt-1 text-sm', textColors.tertiary)}>{t('onboarding.description')}</p>
        </div>

        <div role="alert" className={cn('flex items-start gap-3', tokens.alert.base, tokens.alert.error)}>
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
          <div className="space-y-3">
            <div>
              <p className="font-medium">{t('onboarding.loadErrorTitle')}</p>
              <p className="text-sm">{t('onboarding.loadErrorBody')}</p>
            </div>
            <Button variant="secondary" size="sm" onClick={() => { void refetch() }}>
              {t('common:actions.retry')}
            </Button>
          </div>
        </div>
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
            {/* Status icon — a degraded step is UNKNOWN, not incomplete, so it
                must not wear the red "you still have work to do" triangle. */}
            <span className="shrink-0">
              {item.degraded ? (
                <HelpCircle className={cn('h-5 w-5', textColors.tertiary)} aria-hidden="true" />
              ) : item.completed ? (
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

            {/* Badge — `degraded` wins over required/optional: the backend could
                not determine this step at all (its module's check threw), so
                telling the user it is "Required" would send them to fix
                something that may already be correct. */}
            {item.degraded ? (
              // StatusBadge takes no `title`; wrap rather than widen a shared atom.
              <span title={t('onboarding.badges.unavailableHint')}>
                <StatusBadge tone="warning">{t('onboarding.badges.unavailable')}</StatusBadge>
              </span>
            ) : (
              <StatusBadge tone={item.required ? 'danger' : 'neutral'}>
                {item.required ? t('onboarding.badges.required') : t('onboarding.badges.optional')}
              </StatusBadge>
            )}

            {/* Arrow */}
            <ArrowRight className={cn('h-4 w-4 shrink-0', textColors.disabled)} aria-hidden="true" />
          </button>
        ))}
      </div>

      {/* All done message — never claim completion while a step's state is
          unknown, or a degraded required step would read as "ready to sell". */}
      {!hasIncompleteRequired && !hasDegraded && totalCount > 0 && (
        <div className={cn('flex items-center gap-3', tokens.alert.base, tokens.alert.success)}>
          <Check className="h-5 w-5 shrink-0" aria-hidden="true" />
          <p className="font-medium">{t('onboarding.allDone')}</p>
        </div>
      )}
    </div>
  )
}
