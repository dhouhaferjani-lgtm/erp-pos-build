import { useTranslation } from 'react-i18next'
import { AlertCircle, MinusCircle, Receipt, DollarSign } from 'lucide-react'
import { cn } from '@/lib/utils'
import { formatCurrency } from '../../../lib/format'
import { Button } from '../../../components/atoms/Button'
import type { PaymentStatus } from './paymentStatus'
import { colorClasses } from '@/lib/designTokens'

export interface OutstandingAmountSectionProps {
  total: number
  amountPaid: number
  creditNotesApplied: number
  outstandingAmount: number
  paymentStatus: PaymentStatus
  currency: string
  onRecordPayment?: (() => void) | undefined
  className?: string | undefined
}

/**
 * Outstanding Amount Section Component (Organism)
 *
 * Displays a detailed breakdown of the invoice balance calculation:
 * - Invoice Total (TTC)
 * - Payments Received (negative)
 * - Credit Notes Applied (negative)
 * - Outstanding Amount (highlighted)
 * - Record Payment button (if unpaid/partially paid)
 *
 * This shows the user EXACTLY how the outstanding amount is calculated.
 */
export function OutstandingAmountSection({
  total,
  amountPaid,
  creditNotesApplied,
  outstandingAmount,
  paymentStatus,
  currency,
  onRecordPayment,
  className,
}: OutstandingAmountSectionProps) {
  const { t } = useTranslation(['sales', 'common'])

  const canRecordPayment = 
    paymentStatus !== 'paid' && 
    paymentStatus !== 'overpaid' &&
    onRecordPayment !== undefined

  const isOverpaid = paymentStatus === 'overpaid'
  const isPaid = paymentStatus === 'paid'

  return (
    <div className={cn(`rounded-lg border ${colorClasses.borderGray200} ${colorClasses.bgGray50} p-4 space-y-3`, className)}>
      <h3 className={`text-sm font-semibold ${colorClasses.textGray900} flex items-center gap-2`}>
        <DollarSign className="h-4 w-4" />
        {t('sales:invoices.outstandingAmount.title')}
      </h3>

      <div className="space-y-2">
        {/* Invoice Total */}
        <div className="flex items-center justify-between py-1">
          <div className="flex items-center gap-2">
            <Receipt className={`h-4 w-4 ${colorClasses.textGray400}`} />
            <span className={`text-sm ${colorClasses.textGray700}`}>
              {t('sales:invoices.outstandingAmount.invoiceTotal')}
            </span>
          </div>
          <span className={`text-sm font-medium ${colorClasses.textGray900} font-mono`}>
            {formatCurrency(total, { currency })}
          </span>
        </div>

        {/* Payments Received */}
        {amountPaid > 0 && (
          <div className="flex items-center justify-between py-1">
            <div className="flex items-center gap-2">
              <MinusCircle className={`h-4 w-4 ${colorClasses.textGreen500}`} />
              <span className={`text-sm ${colorClasses.textGray700}`}>
                {t('sales:invoices.outstandingAmount.paymentsReceived')}
              </span>
            </div>
            <span className={`text-sm font-medium ${colorClasses.textGreen600} font-mono`}>
              -{formatCurrency(amountPaid, { currency })}
            </span>
          </div>
        )}

        {/* Credit Notes Applied */}
        {creditNotesApplied > 0 && (
          <div className="flex items-center justify-between py-1">
            <div className="flex items-center gap-2">
              <MinusCircle className={`h-4 w-4 ${colorClasses.textBlue500}`} />
              <span className={`text-sm ${colorClasses.textGray700}`}>
                {t('sales:invoices.outstandingAmount.creditNotesApplied')}
              </span>
            </div>
            <span className={`text-sm font-medium ${colorClasses.textBlue600} font-mono`}>
              -{formatCurrency(creditNotesApplied, { currency })}
            </span>
          </div>
        )}

        {/* Divider */}
        <div className={`border-t ${colorClasses.borderGray300} my-2`}></div>

        {/* Outstanding Amount */}
        <div className={cn(
          'flex items-center justify-between py-2 px-3 rounded-md',
          isPaid && `${colorClasses.bgGreen50}`,
          isOverpaid && `${colorClasses.bgPurple50}`,
          !isPaid && !isOverpaid && outstandingAmount > 0 && `${colorClasses.bgRed50}`
        )}>
          <span className={cn(
            'text-base font-bold',
            isPaid && `${colorClasses.textGreen700}`,
            isOverpaid && `${colorClasses.textPurple700}`,
            !isPaid && !isOverpaid && outstandingAmount > 0 && `${colorClasses.textRed700}`,
            outstandingAmount === 0 && !isPaid && `${colorClasses.textGray700}`
          )}>
            {t('sales:invoices.outstandingAmount.outstanding')}
          </span>
          <span className={cn(
            'text-lg font-bold font-mono',
            isPaid && `${colorClasses.textGreen700}`,
            isOverpaid && `${colorClasses.textPurple700}`,
            !isPaid && !isOverpaid && outstandingAmount > 0 && `${colorClasses.textRed700}`,
            outstandingAmount === 0 && !isPaid && `${colorClasses.textGray700}`
          )}>
            {formatCurrency(outstandingAmount, { currency })}
          </span>
        </div>

        {/* Overpaid warning */}
        {isOverpaid && (
          <div className={`flex items-start gap-2 rounded-lg ${colorClasses.bgPurple50} border ${colorClasses.borderPurple200} p-3 mt-2`}>
            <AlertCircle className={`h-4 w-4 ${colorClasses.textPurple600} flex-shrink-0 mt-0.5`} />
            <p className={`text-sm ${colorClasses.textPurple700}`}>
              {t('sales:invoices.outstandingAmount.overpaidWarning')}
            </p>
          </div>
        )}

        {/* Record Payment button */}
        {canRecordPayment && (
          <div className="pt-2">
            <Button
              onClick={onRecordPayment}
              className="w-full"
              variant="primary"
            >
              {t('sales:invoices.outstandingAmount.recordPayment')}
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
