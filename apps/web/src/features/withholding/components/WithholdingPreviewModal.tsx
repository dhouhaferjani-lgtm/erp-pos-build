import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertCircle, Calculator } from 'lucide-react'
import { useWithholdingPreview } from '../hooks/useWithholding'
import { useCurrency } from '@/hooks/useCurrency'
import { Button } from '@/components/atoms/Button'
import { Checkbox } from '@/components/atoms'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Textarea } from '@/components/atoms/Textarea'
import { Spinner } from '@/components/atoms/Spinner'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { bcdiv, bcmul, bcsub } from '@/lib/decimal'
import { formatNumber, formatPercent } from '@/lib/format'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
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
  const { decimals } = useCurrency()
  const previewMutation = useWithholdingPreview()

  const [manualOverride, setManualOverride] = useState(false)
  const [manualRate, setManualRate] = useState('')
  const [overrideReason, setOverrideReason] = useState('')

  // Fetch preview when modal opens
  useEffect(() => {
    if (isOpen && partnerId && amount) {
      previewMutation.mutate({
        partner_id: partnerId,
        amount,
        currency,
        transaction_type: transactionType,
      })
    }
  }, [isOpen, partnerId, amount, currency, transactionType])

  const preview = previewMutation.data
  const manualRateValue = manualRate.trim() === '' ? '0' : manualRate

  const handleApply = () => {
    if (manualOverride) {
      const rate = bcdiv(manualRateValue, '100', 4)
      const withholdingAmount = bcmul(amount, rate, decimals)
      onApply(withholdingAmount, rate)
    } else if (preview) {
      onApply(preview.withholding_amount ?? '', preview.withholding_rate ?? '')
    }
    onClose()
  }

  const calculatedWithholding = manualOverride
    ? bcmul(amount, bcdiv(manualRateValue, '100', 4), decimals)
    : preview?.withholding_amount ?? '0.' + '0'.repeat(decimals)

  const calculatedNet = manualOverride
    ? bcsub(amount, calculatedWithholding, decimals)
    : preview?.net_amount ?? amount

  const displayedRate = manualOverride ? manualRateValue : String(preview?.rate_percentage ?? 0)

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('preview.title')}
      size="lg"
    >
      <ModalContent className="space-y-6">
        {/* Loading State */}
        {previewMutation.isPending && (
          <div className="flex items-center justify-center py-8">
            <Spinner size="md" message={t('preview.calculating')} />
          </div>
        )}

        {/* Error State */}
        {previewMutation.isError && (
          <div className={cn('rounded-lg border p-4', borderColors.error, tokens.alert.error)}>
            <div className="flex items-start gap-3">
              <AlertCircle className={cn('h-5 w-5 flex-shrink-0 mt-0.5', textColors.error)} />
              <div>
                <h3 className={cn('text-sm font-medium', textColors.error)}>
                  {t('messages.calculationFailed')}
                </h3>
                <p className={cn('mt-1 text-sm', textColors.error)}>
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
              <div className={cn('rounded-lg border p-4', borderColors.primary, tokens.alert.info)}>
                <div className="flex items-start gap-3">
                  <Calculator className={cn('h-5 w-5 flex-shrink-0 mt-0.5', textColors.brand)} />
                  <div className="flex-1">
                    <h3 className={cn('text-sm font-medium', textColors.brand)}>
                      {t('preview.recommendedAlert')}
                    </h3>
                    {preview.rule && (
                      <div className="mt-2 space-y-1">
                        <p className={cn('text-sm', textColors.brand)}>
                          <span className="font-medium">{t('rules.name')}:</span> {preview.rule.name}
                        </p>
                        <p className={cn('text-sm', textColors.brand)}>
                          <span className="font-medium">{t('rules.code')}:</span> {preview.rule.code}
                        </p>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            )}

            {/* Amount Breakdown */}
            <div className={cn('rounded-lg border p-4', borderColors.light, tokens.alert.base, colors.neutral[50])}>
              <h3 className={cn('mb-3 text-sm font-medium', textColors.primary)}>
                {t('preview.amountBreakdown')}
              </h3>
              <div className="space-y-2">
                <div className="flex items-center justify-between text-sm">
                  <span className={textColors.tertiary}>{t('certificates.grossAmount')}</span>
                  <span className={cn('font-mono font-semibold', textColors.primary)}>
                    {formatNumber(amount, decimals)} {currency}
                  </span>
                </div>
                <div className="flex items-center justify-between text-sm">
                  <span className={textColors.tertiary}>
                    {t('certificates.rate')} ({formatPercent(displayedRate)})
                  </span>
                  <span className={cn('font-mono font-semibold', textColors.error)}>
                    - {calculatedWithholding} {currency}
                  </span>
                </div>
                <div className={cn('flex items-center justify-between border-t pt-2 text-base', borderColors.default)}>
                  <span className={cn('font-semibold', textColors.primary)}>{t('certificates.netAmount')}</span>
                  <span className={cn('font-mono text-lg font-bold', textColors.primary)}>
                    {calculatedNet} {currency}
                  </span>
                </div>
              </div>
            </div>

            {/* Manual Override Toggle */}
            <div className={cn('border-t pt-4', borderColors.light)}>
              <label className="flex items-center gap-2 cursor-pointer">
                <Checkbox
                  checked={manualOverride}
                  onChange={(e) => {
                    setManualOverride(e.target.checked)
                    if (e.target.checked && preview) {
                      setManualRate((preview.rate_percentage ?? 0).toString())
                    }
                  }}
                />
                <span className={cn('text-sm font-medium', textColors.secondary)}>
                  {t('preview.manualOverride')}
                </span>
              </label>

              {/* Manual Override Fields */}
              {manualOverride && (
                <div className={cn('mt-4 space-y-4 rounded-lg border p-4', borderColors.light, colors.neutral[50])}>
                  <FormField label={t('preview.manualRate')} htmlFor="withholding-manual-rate">
                    <div className="flex items-center gap-2">
                      <Input
                        id="withholding-manual-rate"
                        type="number"
                        step="any"
                        min="0"
                        max="100"
                        value={manualRate}
                        onChange={(e) => { setManualRate(e.target.value); }}
                        className="w-32"
                      />
                      <span className={cn('text-sm', textColors.tertiary)}>%</span>
                    </div>
                  </FormField>
                  <FormField label={t('details.overrideReason')} htmlFor="withholding-override-reason">
                    <Textarea
                      id="withholding-override-reason"
                      value={overrideReason}
                      onChange={(e) => { setOverrideReason(e.target.value); }}
                      rows={2}
                      placeholder={t('preview.overrideReasonPlaceholder')}
                    />
                  </FormField>
                </div>
              )}
            </div>
          </>
        )}

        {/* No Rule Found */}
        {!preview && !previewMutation.isPending && !previewMutation.isError && (
          <div className={cn('rounded-lg border p-4 text-center', borderColors.light, tokens.alert.base, colors.neutral[50])}>
            <p className={cn('text-sm', textColors.tertiary)}>{t('preview.noRuleFound')}</p>
            <p className={cn('mt-1 text-xs', textColors.disabled)}>
              {t('preview.noRuleFoundHelp')}
            </p>
          </div>
        )}
      </ModalContent>

      <ModalFooter>
        <Button variant="secondary" onClick={onClose}>
          {t('common:cancel')}
        </Button>
        <Button
          variant="primary"
          onClick={handleApply}
          disabled={!preview && !manualOverride}
        >
          {t('preview.applyWithholding')}
        </Button>
      </ModalFooter>
    </Modal>
  )
}
