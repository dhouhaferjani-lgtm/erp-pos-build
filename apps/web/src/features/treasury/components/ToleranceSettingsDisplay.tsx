/**
 * ToleranceSettingsDisplay Component
 * Displays payment tolerance settings for the current company
 */

import { useTranslation } from 'react-i18next'
import { Info } from 'lucide-react'
import { useToleranceSettings } from '../hooks/useSmartPayment'
import { useCurrency } from '@/hooks/useCurrency'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { StatusBadge } from '@/components/atoms/StatusBadge'

/**
 * Display-only component showing effective tolerance settings
 *
 * Shows:
 * - Enabled/disabled status
 * - Percentage threshold
 * - Maximum amount
 * - Effective source (company/country/system)
 */
export function ToleranceSettingsDisplay() {
  const { t } = useTranslation(['treasury', 'common'])
  const { decimals } = useCurrency()
  const { data: settings, isLoading, error } = useToleranceSettings()

  if (isLoading) {
    return (
      <div className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
        <div className="flex items-center gap-2">
          <div className={`h-5 w-5 animate-spin rounded-full border-2 ${borderColors.default} border-t-transparent`} />
          <span className={`text-sm ${textColors.tertiary}`}>{t('loading', 'Loading...')}</span>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className={`rounded-lg border ${borderColors.error} ${tokens.alert.error} p-4`}>
        <div className="flex items-center gap-2">
          <Info className={`h-5 w-5 ${textColors.error}`} />
          <span className={`text-sm ${textColors.error}`}>
            {t('error', 'Error loading tolerance settings')}
          </span>
        </div>
      </div>
    )
  }

  if (!settings) {
    return null
  }

  // Convert decimal string to percentage (0.0050 → 0.5000%)
  const percentageValue = (parseFloat(settings.percentage) * 100).toFixed(4)
  const maxAmountValue = parseFloat(settings.max_amount).toFixed(decimals)

  return (
    <div className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
      <div className="flex items-start gap-3">
        <Info className={`h-5 w-5 ${textColors.brand} mt-0.5`} />
        <div className="flex-1">
          <h3 className={`text-sm font-medium ${textColors.primary}`}>
            {t('smartPayment.tolerance.title')}
          </h3>
          <div className="mt-2 space-y-1">
            <div className="flex items-center gap-2">
              <StatusBadge tone={settings.enabled ? 'success' : 'neutral'}>
                {settings.enabled
                  ? t('smartPayment.tolerance.enabled')
                  : t('smartPayment.tolerance.disabled')}
              </StatusBadge>
            </div>
            {settings.enabled && (
              <p className={`text-sm ${textColors.tertiary} pl-4`}>
                {t('smartPayment.tolerance.threshold', {
                  percentage: percentageValue,
                  maxAmount: maxAmountValue,
                })}
              </p>
            )}
            <p className={`text-xs ${textColors.disabled} pl-4`}>
              {t(`smartPayment.tolerance.source.${settings.source}`)}
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
