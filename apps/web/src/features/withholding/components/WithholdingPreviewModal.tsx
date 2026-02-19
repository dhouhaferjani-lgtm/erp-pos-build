import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { X, AlertCircle, Calculator } from 'lucide-react'
import { useWithholdingPreview } from '../hooks/useWithholding'
import type { TransactionType } from '../types'

interface WithholdingPreviewModalProps {
  isOpen: boolean
  onClose: () => void
  onApply: (withholdingAmount: string, withholdingRate: string) => void
  partnerId: string
  amount: string
  currency: string
  transactionType?: TransactionType
}

export function WithholdingPreviewModal({
  isOpen,
  onClose,
  onApply,
  partnerId,
  amount,
  currency,
  transactionType,
}: WithholdingPreviewModalProps) {
  const { t } = useTranslation(['withholding', 'common'])
  const previewMutation = useWithholdingPreview()

  const [manualOverride, setManualOverride] = useState(false)
  const [manualRate, setManualRate] = useState('')
  const [overrideReason, setOverrideReason] = useState('')

  // Fetch preview when modal opens
  useEffect(() => {
    if (isOpen && partnerId && amount) {
      previewMutation.mutate({
        partner_id: partnerId,
        gross_amount: amount,
        currency,
        transaction_type: transactionType,
      })
    }
  }, [isOpen, partnerId, amount, currency, transactionType])

  const preview = previewMutation.data

  const handleApply = () => {
    if (manualOverride) {
      const rate = parseFloat(manualRate) / 100
      const withholdingAmount = (parseFloat(amount) * rate).toFixed(3)
      onApply(withholdingAmount, rate.toFixed(4))
    } else if (preview) {
      onApply(preview.withholding_amount, preview.withholding_rate)
    }
    onClose()
  }

  const calculatedWithholding = manualOverride
    ? (parseFloat(amount) * parseFloat(manualRate) / 100).toFixed(3)
    : preview?.withholding_amount ?? '0.000'

  const calculatedNet = manualOverride
    ? (parseFloat(amount) - parseFloat(calculatedWithholding)).toFixed(3)
    : preview?.net_amount ?? amount

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
      <div className="w-full max-w-2xl rounded-lg bg-white shadow-xl">
        {/* Header */}
        <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('preview.title')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Content */}
        <div className="space-y-6 px-6 py-4">
          {/* Loading State */}
          {previewMutation.isPending && (
            <div className="flex items-center justify-center py-8">
              <div className="text-center">
                <div className="mx-auto mb-2 h-8 w-8 animate-spin rounded-full border-4 border-blue-600 border-t-transparent"></div>
                <p className="text-sm text-gray-600">{t('preview.calculating')}</p>
              </div>
            </div>
          )}

          {/* Error State */}
          {previewMutation.isError && (
            <div className="rounded-lg border border-red-200 bg-red-50 p-4">
              <div className="flex items-start gap-3">
                <AlertCircle className="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5" />
                <div>
                  <h3 className="text-sm font-medium text-red-900">
                    {t('messages.calculationFailed')}
                  </h3>
                  <p className="mt-1 text-sm text-red-700">
                    {previewMutation.error?.message || t('messages.noApplicableRule')}
                  </p>
                </div>
              </div>
            </div>
          )}

          {/* Success State - Show Preview */}
          {preview && !previewMutation.isPending && (
            <>
              {/* Automatic Calculation */}
              {!manualOverride && (
                <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
                  <div className="flex items-start gap-3">
                    <Calculator className="h-5 w-5 text-blue-600 flex-shrink-0 mt-0.5" />
                    <div className="flex-1">
                      <h3 className="text-sm font-medium text-blue-900">
                        {t('preview.recommendedAlert')}
                      </h3>
                      {preview.rule && (
                        <div className="mt-2 space-y-1">
                          <p className="text-sm text-blue-800">
                            <span className="font-medium">{t('rules.name')}:</span> {preview.rule.name}
                          </p>
                          <p className="text-sm text-blue-800">
                            <span className="font-medium">{t('rules.code')}:</span> {preview.rule.code}
                          </p>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {/* Amount Breakdown */}
              <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <h3 className="mb-3 text-sm font-medium text-gray-900">
                  {t('preview.amountBreakdown')}
                </h3>
                <div className="space-y-2">
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-gray-600">{t('certificates.grossAmount')}</span>
                    <span className="font-mono font-semibold text-gray-900">
                      {parseFloat(amount).toFixed(3)} {currency}
                    </span>
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-gray-600">
                      {t('certificates.rate')} ({manualOverride ? manualRate : preview.rate_percentage}%)
                    </span>
                    <span className="font-mono font-semibold text-red-600">
                      - {calculatedWithholding} {currency}
                    </span>
                  </div>
                  <div className="flex items-center justify-between border-t border-gray-300 pt-2 text-base">
                    <span className="font-semibold text-gray-900">{t('certificates.netAmount')}</span>
                    <span className="font-mono text-lg font-bold text-gray-900">
                      {calculatedNet} {currency}
                    </span>
                  </div>
                </div>
              </div>

              {/* Manual Override Toggle */}
              <div className="border-t border-gray-200 pt-4">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={manualOverride}
                    onChange={(e) => {
                      setManualOverride(e.target.checked)
                      if (e.target.checked && preview) {
                        setManualRate(preview.rate_percentage.toString())
                      }
                    }}
                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                  />
                  <span className="text-sm font-medium text-gray-700">
                    {t('preview.manualOverride')}
                  </span>
                </label>

                {/* Manual Override Fields */}
                {manualOverride && (
                  <div className="mt-4 space-y-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        {t('preview.manualRate')}
                      </label>
                      <div className="flex items-center gap-2">
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          max="100"
                          value={manualRate}
                          onChange={(e) => setManualRate(e.target.value)}
                          className="block w-32 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                        />
                        <span className="text-sm text-gray-600">%</span>
                      </div>
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        {t('details.overrideReason')}
                      </label>
                      <textarea
                        value={overrideReason}
                        onChange={(e) => setOverrideReason(e.target.value)}
                        rows={2}
                        className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                        placeholder={t('preview.overrideReasonPlaceholder')}
                      />
                    </div>
                  </div>
                )}
              </div>
            </>
          )}

          {/* No Rule Found */}
          {preview === null && !previewMutation.isPending && !previewMutation.isError && (
            <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center">
              <p className="text-sm text-gray-600">{t('preview.noRuleFound')}</p>
              <p className="mt-1 text-xs text-gray-500">
                {t('preview.noRuleFoundHelp')}
              </p>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 border-t border-gray-200 px-6 py-4">
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            {t('common:cancel')}
          </button>
          <button
            type="button"
            onClick={handleApply}
            disabled={!preview && !manualOverride}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {t('preview.applyWithholding')}
          </button>
        </div>
      </div>
    </div>
  )
}
