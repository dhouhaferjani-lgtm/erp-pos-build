/**
 * DocumentPaymentHistory Component
 *
 * Displays payment history for documents with payment records.
 */

import { useTranslation } from 'react-i18next'
import { CreditCard } from 'lucide-react'
import type { PaymentRecord } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'

export interface DocumentPaymentHistoryProps {
  /** Array of payment records */
  payments: PaymentRecord[]
  /** Format currency function */
  formatAmount: (amount: string | number) => string
  /** Optional class name for the container */
  className?: string
}

export function DocumentPaymentHistory({
  payments,
  formatAmount,
  className = '',
}: DocumentPaymentHistoryProps) {
  const { t } = useTranslation(['sales'])

  if (!payments || payments.length === 0) {
    return null
  }

  return (
    <div
      className={`rounded-lg border ${colorClasses.borderGray200} bg-white ${className}`}
    >
      <div className={`border-b ${colorClasses.borderGray200} px-6 py-4`}>
        <h2 className={`text-lg font-semibold ${colorClasses.textGray900}`}>
          <CreditCard className="me-2 inline h-5 w-5" />
          {t('documents.paymentHistory')}
        </h2>
      </div>
      <div className={`divide-y ${colorClasses.divideGray100}`}>
        {payments.map((payment) => (
          <div
            key={payment.id}
            className="flex items-center justify-between px-6 py-4"
          >
            <div className="flex items-center gap-4">
              <div className={`flex h-10 w-10 items-center justify-center rounded-full ${colorClasses.bgGreen100}`}>
                <CreditCard className={`h-5 w-5 ${colorClasses.textGreen600}`} />
              </div>
              <div>
                <p className={`font-medium ${colorClasses.textGray900}`}>
                  {payment.payment_method ?? t('documents.payment')}
                </p>
                <p className={`text-sm ${colorClasses.textGray500}`}>
                  {new Date(payment.payment_date).toLocaleDateString()}
                  {payment.payment_reference && (
                    <span className={`ms-2 ${colorClasses.textGray400}`}>
                      {t('documents.ref')}: {payment.payment_reference}
                    </span>
                  )}
                </p>
              </div>
            </div>
            <div className="text-end">
              <p className={`font-semibold ${colorClasses.textGreen600}`}>
                {formatAmount(payment.amount)}
              </p>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
