import { useTranslation } from 'react-i18next'
import { AlertCircle, MinusCircle, Receipt, DollarSign } from 'lucide-react'
import { cn } from '@/lib/utils'
import { formatCurrency } from '../../../lib/format'
import { Button } from '../../../components/atoms/Button'
import type { PaymentStatus } from './PaymentStatusBadge'

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
    <div className={cn('rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-3', className)}>
      <h3 className="text-sm font-semibold text-gray-900 flex items-center gap-2">
        <DollarSign className="h-4 w-4" />
        {t('sales:invoices.outstandingAmount.title')}
      </h3>

      <div className="space-y-2">
        {/* Invoice Total */}
        <div className="flex items-center justify-between py-1">
          <div className="flex items-center gap-2">
            <Receipt className="h-4 w-4 text-gray-400" />
            <span className="text-sm text-gray-700">
              {t('sales:invoices.outstandingAmount.invoiceTotal')}
            </span>
          </div>
          <span className="text-sm font-medium text-gray-900 font-mono">
            {formatCurrency(total, { currency })}
          </span>
        </div>

        {/* Payments Received */}
        {amountPaid > 0 && (
          <div className="flex items-center justify-between py-1">
            <div className="flex items-center gap-2">
              <MinusCircle className="h-4 w-4 text-green-500" />
              <span className="text-sm text-gray-700">
                {t('sales:invoices.outstandingAmount.paymentsReceived')}
              </span>
            </div>
            <span className="text-sm font-medium text-green-600 font-mono">
              -{formatCurrency(amountPaid, { currency })}
            </span>
          </div>
        )}

        {/* Credit Notes Applied */}
        {creditNotesApplied > 0 && (
          <div className="flex items-center justify-between py-1">
            <div className="flex items-center gap-2">
              <MinusCircle className="h-4 w-4 text-blue-500" />
              <span className="text-sm text-gray-700">
                {t('sales:invoices.outstandingAmount.creditNotesApplied')}
              </span>
            </div>
            <span className="text-sm font-medium text-blue-600 font-mono">
              -{formatCurrency(creditNotesApplied, { currency })}
            </span>
          </div>
        )}

        {/* Divider */}
        <div className="border-t border-gray-300 my-2"></div>

        {/* Outstanding Amount */}
        <div className={cn(
          'flex items-center justify-between py-2 px-3 rounded-md',
          isPaid && 'bg-green-50',
          isOverpaid && 'bg-purple-50',
          !isPaid && !isOverpaid && outstandingAmount > 0 && 'bg-red-50'
        )}>
          <span className={cn(
            'text-base font-bold',
            isPaid && 'text-green-700',
            isOverpaid && 'text-purple-700',
            !isPaid && !isOverpaid && outstandingAmount > 0 && 'text-red-700',
            outstandingAmount === 0 && !isPaid && 'text-gray-700'
          )}>
            {t('sales:invoices.outstandingAmount.outstanding')}
          </span>
          <span className={cn(
            'text-lg font-bold font-mono',
            isPaid && 'text-green-700',
            isOverpaid && 'text-purple-700',
            !isPaid && !isOverpaid && outstandingAmount > 0 && 'text-red-700',
            outstandingAmount === 0 && !isPaid && 'text-gray-700'
          )}>
            {formatCurrency(outstandingAmount, { currency })}
          </span>
        </div>

        {/* Overpaid warning */}
        {isOverpaid && (
          <div className="flex items-start gap-2 rounded-lg bg-purple-50 border border-purple-200 p-3 mt-2">
            <AlertCircle className="h-4 w-4 text-purple-600 flex-shrink-0 mt-0.5" />
            <p className="text-sm text-purple-700">
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
