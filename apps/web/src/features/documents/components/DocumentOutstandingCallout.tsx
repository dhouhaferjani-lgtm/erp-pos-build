import { useTranslation } from 'react-i18next'
import { CreditCard } from 'lucide-react'
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
    <div className="flex items-center justify-between rounded-lg border border-orange-200 bg-orange-50 px-4 py-3">
      <div className="flex items-center gap-2">
        <CreditCard className="h-5 w-5 text-orange-600" />
        <span className="text-sm font-medium text-orange-800">
          {t('documents.amountDue')}
        </span>
        <span className="text-base font-bold text-orange-900">
          {formatCurrency(amount, { currency })}
        </span>
      </div>
      {onRecordPayment && (
        <button
          type="button"
          onClick={onRecordPayment}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <CreditCard className="h-4 w-4" />
          {t('documents.recordPayment')}
        </button>
      )}
    </div>
  )
}
