/**
 * DocumentPaymentHistory Component
 *
 * Displays payment history for documents with payment records.
 */

import { useTranslation } from 'react-i18next'
import { CreditCard } from 'lucide-react'
import type { PaymentRecord } from '../../../types/document'

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
      className={`rounded-lg border border-gray-200 bg-white ${className}`}
    >
      <div className="border-b border-gray-200 px-6 py-4">
        <h2 className="text-lg font-semibold text-gray-900">
          <CreditCard className="me-2 inline h-5 w-5" />
          {t('documents.paymentHistory')}
        </h2>
      </div>
      <div className="divide-y divide-gray-100">
        {payments.map((payment) => (
          <div
            key={payment.id}
            className="flex items-center justify-between px-6 py-4"
          >
            <div className="flex items-center gap-4">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-green-100">
                <CreditCard className="h-5 w-5 text-green-600" />
              </div>
              <div>
                <p className="font-medium text-gray-900">
                  {payment.payment_method ?? t('documents.payment')}
                </p>
                <p className="text-sm text-gray-500">
                  {new Date(payment.payment_date).toLocaleDateString()}
                  {payment.payment_reference && (
                    <span className="ms-2 text-gray-400">
                      {t('documents.ref')}: {payment.payment_reference}
                    </span>
                  )}
                </p>
              </div>
            </div>
            <div className="text-end">
              <p className="font-semibold text-green-600">
                {formatAmount(payment.amount)}
              </p>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
