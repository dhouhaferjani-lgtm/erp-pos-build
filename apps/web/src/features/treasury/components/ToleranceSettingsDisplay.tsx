/**
 * ToleranceSettingsDisplay Component
 * Displays payment tolerance settings for the current company
 */

import { useTranslation } from 'react-i18next'
import { Info } from 'lucide-react'
import { useToleranceSettings } from '../hooks/useSmartPayment'

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
  const { data: settings, isLoading, error } = useToleranceSettings()

  if (isLoading) {
    return (
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex items-center gap-2">
          <div className="h-5 w-5 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600" />
          <span className="text-sm text-gray-600">{t('loading', 'Loading...')}</span>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-4">
        <div className="flex items-center gap-2">
          <Info className="h-5 w-5 text-red-600" />
          <span className="text-sm text-red-800">
            {t('error', 'Error loading tolerance settings')}
          </span>
        </div>
      </div>
    )
  }

  if (!settings) {
    return null
  }

  // Convert decimal string to percentage (0.0050 → 0.50%)
  const percentageValue = (parseFloat(settings.percentage) * 100).toFixed(2)
  const maxAmountValue = parseFloat(settings.max_amount).toFixed(2)

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4">
      <div className="flex items-start gap-3">
        <Info className="h-5 w-5 text-blue-600 mt-0.5" />
        <div className="flex-1">
          <h3 className="text-sm font-medium text-gray-900">
            {t('smartPayment.tolerance.title')}
          </h3>
          <div className="mt-2 space-y-1">
            <div className="flex items-center gap-2">
              <div
                className={`h-2 w-2 rounded-full ${
                  settings.enabled ? 'bg-green-500' : 'bg-gray-400'
                }`}
              />
              <span className="text-sm text-gray-700">
                {settings.enabled
                  ? t('smartPayment.tolerance.enabled')
                  : t('smartPayment.tolerance.disabled')}
              </span>
            </div>
            {settings.enabled && (
              <p className="text-sm text-gray-600 pl-4">
                {t('smartPayment.tolerance.threshold', {
                  percentage: percentageValue,
                  maxAmount: maxAmountValue,
                })}
              </p>
            )}
            <p className="text-xs text-gray-500 pl-4">
              {t(`smartPayment.tolerance.source.${settings.source}`)}
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
