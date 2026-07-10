import { useTranslation } from 'react-i18next'
import { CreditCard } from 'lucide-react'
import { Button } from '../../../components/atoms/Button/Button'
import { colorClasses, tokens, textColors } from '../../../lib/designTokens'
import { formatCurrency } from '../../../lib/format'

export interface DocumentOutstandingCalloutProps {
  amount: number
  currency: string
  onRecordPayment?: () => void
}

export function DocumentOutstandingCallout({
  amount,
  currency,
  onRecordPayment,
}: DocumentOutstandingCalloutProps) {
  const { t } = useTranslation(['sales'])

  return (
    <div
      className={`${tokens.alert.warning} flex items-center justify-between rounded-md border ${colorClasses.borderYellow200} px-4 py-3`}
    >
      <div className="flex items-center gap-2">
        <CreditCard className={`h-5 w-5 ${textColors.warningDark}`} />
        <span className="text-sm font-medium">{t('documents.amountDue')}</span>
        <span className="text-base font-semibold">{formatCurrency(amount, { currency })}</span>
      </div>
      {onRecordPayment && (
        <Button type="button" variant="secondary" size="sm" onClick={onRecordPayment} className="gap-2">
          <CreditCard className="h-4 w-4" />
          {t('documents.recordPayment')}
        </Button>
      )}
    </div>
  )
}
