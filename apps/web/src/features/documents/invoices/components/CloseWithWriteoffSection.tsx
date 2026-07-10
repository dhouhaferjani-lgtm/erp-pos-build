import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { AxiosError } from 'axios'
import { Button } from '@/components/atoms/Button/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useToleranceSettings } from '@/features/treasury/hooks/useSmartPayment'
import { bccomp, bcmul } from '@/lib/decimal'
import { tokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import type { CloseWithToleranceErrorBody } from '../api/closeWithTolerance'
import { useCloseWithTolerance } from '../hooks/useCloseWithTolerance'

interface CloseWithWriteoffSectionProps {
  invoiceId: string
  invoiceTotal: string
  balanceDue: string
  currency: string
  invoiceStatus: string
}

/**
 * Renders the "Close with write-off" entry point on the invoice detail page when:
 *   - the invoice is Posted,
 *   - it has a non-zero balance, AND
 *   - the residual is strictly below the company's tolerance margin.
 *
 * Backend authoritatively re-validates eligibility on submit; the frontend gate
 * here is purely UX — saves a wasted click on out-of-tolerance balances.
 */
export function CloseWithWriteoffSection({
  invoiceId,
  invoiceTotal,
  balanceDue,
  currency,
  invoiceStatus,
}: CloseWithWriteoffSectionProps) {
  const { t } = useTranslation('documents')
  const [isOpen, setIsOpen] = useState(false)
  const { data: settings } = useToleranceSettings()

  const eligibility = useMemo(() => {
    if (invoiceStatus !== 'posted') return false
    if (bccomp(balanceDue, '0') <= 0) return false
    if (!settings?.enabled) return false

    const percentageThreshold = bcmul(invoiceTotal, settings.percentage, 4)
    const withinPercentage = bccomp(balanceDue, percentageThreshold) < 0
    const withinMaxAmount = bccomp(balanceDue, settings.max_amount) < 0
    return withinPercentage && withinMaxAmount
  }, [invoiceStatus, balanceDue, invoiceTotal, settings])

  const mutation = useCloseWithTolerance({
    invoiceId,
    onSuccess: (response) => {
      setIsOpen(false)
      toast.success(
        t('invoice.closeWithWriteoff.success', {
          amount: formatCurrency(response.meta.tolerance_writeoff.amount, { currency }),
        })
      )
    },
    onError: (error: AxiosError<CloseWithToleranceErrorBody>) => {
      const code = error.response?.data?.error.code
      const message = error.response?.data?.error.message
      if (code === 'TOLERANCE_EXCEEDED') {
        toast.error(t('invoice.closeWithWriteoff.error.toleranceExceeded'))
      } else if (code === 'ALREADY_PAID') {
        toast.error(t('invoice.closeWithWriteoff.error.alreadyPaid'))
      } else {
        toast.error(message ?? t('invoice.closeWithWriteoff.error.generic'))
      }
    },
  })

  if (!eligibility) {
    return null
  }

  const formattedBalance = formatCurrency(balanceDue, { currency })

  return (
    <div className={`mt-4 ${tokens.alert.base} ${tokens.alert.warning}`}>
      <div className="flex items-center justify-between gap-4">
        <div className="text-sm">
          <p className={`font-medium ${textColors.primary}`}>
            {t('invoice.closeWithWriteoff.calloutTitle')}
          </p>
          <p className={`mt-1 ${textColors.tertiary}`}>
            {t('invoice.closeWithWriteoff.calloutMessage', { amount: formattedBalance })}
          </p>
        </div>
        <Button
          type="button"
          onClick={() => { setIsOpen(true); }}
          disabled={mutation.isPending}
          data-testid="close-with-writeoff-button"
        >
          {t('invoice.closeWithWriteoff.button', { amount: formattedBalance })}
        </Button>
      </div>

      <ConfirmDialog
        isOpen={isOpen}
        onClose={() => { setIsOpen(false); }}
        onConfirm={() => { mutation.mutate(); }}
        title={t('invoice.closeWithWriteoff.dialog.title')}
        message={t('invoice.closeWithWriteoff.dialog.message', { amount: formattedBalance })}
        confirmText={t('invoice.closeWithWriteoff.dialog.confirm')}
        cancelText={t('invoice.closeWithWriteoff.dialog.cancel')}
        variant="warning"
        isLoading={mutation.isPending}
      />
    </div>
  )
}
