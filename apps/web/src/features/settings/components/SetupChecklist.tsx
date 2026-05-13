import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CheckCircle2, AlertTriangle, Circle, ArrowRight } from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
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
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-gray-300 border-t-blue-600" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">{t('onboarding.title')}</h2>
        <p className="mt-1 text-sm text-gray-600">{t('onboarding.description')}</p>
      </div>

      {/* Progress bar */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <div className="flex items-center justify-between mb-2">
          <span className="text-sm font-medium text-gray-700">
            {t('onboarding.progressLabel', { completed: completedCount, total: totalCount })}
          </span>
          <span className="text-sm font-semibold text-gray-900">{progressPercent}%</span>
        </div>
        <div className="h-2 w-full rounded-full bg-gray-200">
          <div
            className="h-2 rounded-full bg-green-500 transition-all duration-300"
            style={{ width: `${progressPercent}%` }}
          />
        </div>
      </div>

      {/* Required steps alert */}
      {hasIncompleteRequired && (
        <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4">
          <AlertTriangle className="h-5 w-5 shrink-0 text-red-600 mt-0.5" />
          <p className="text-sm font-medium text-red-800">{t('onboarding.requiredStepsAlert')}</p>
        </div>
      )}

      {/* Checklist items */}
      <div className="rounded-lg border border-gray-200 bg-white divide-y divide-gray-100">
        {items.map((item: OnboardingItem) => (
          <button
            key={item.step}
            type="button"
            onClick={() => { navigate(item.settings_path); }}
            className="flex w-full items-center gap-4 px-6 py-4 text-left hover:bg-gray-50 transition-colors"
          >
            {/* Status icon */}
            <span className="shrink-0">
              {item.completed ? (
                <CheckCircle2 className="h-5 w-5 text-green-500" />
              ) : item.required ? (
                <AlertTriangle className="h-5 w-5 text-amber-500" />
              ) : (
                <Circle className="h-5 w-5 text-gray-300" />
              )}
            </span>

            {/* Label */}
            <span className="flex-1 text-sm font-medium text-gray-900">{t(`onboarding.steps.${item.step}`)}</span>

            {/* Badge */}
            <span
              className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${
                item.required
                  ? 'bg-red-100 text-red-700'
                  : 'bg-gray-100 text-gray-500'
              }`}
            >
              {item.required ? t('onboarding.badges.required') : t('onboarding.badges.optional')}
            </span>

            {/* Arrow */}
            <ArrowRight className="h-4 w-4 shrink-0 text-gray-400" />
          </button>
        ))}
      </div>

      {/* All done message */}
      {!hasIncompleteRequired && totalCount > 0 && (
        <div className="rounded-lg border border-green-200 bg-green-50 p-4">
          <div className="flex items-center gap-3">
            <CheckCircle2 className="h-5 w-5 shrink-0 text-green-600" />
            <p className="text-sm font-medium text-green-800">{t('onboarding.allDone')}</p>
          </div>
        </div>
      )}
    </div>
  )
}
