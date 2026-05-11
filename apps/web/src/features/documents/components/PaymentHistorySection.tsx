import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { AlertCircle, Calendar, CreditCard, Hash, Receipt } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchPaymentHistory, type PaymentHistory } from '../api/paymentHistory'
import { formatCurrency } from '../../../lib/format'
import { format } from 'date-fns'

export interface PaymentHistorySectionProps {
  documentId: string
  currency: string
  className?: string
}

/**
 * Payment History Section Component (Organism)
 *
 * Displays the list of payments received for an invoice, including:
 * - Payment date
 * - Payment method
 * - Payment reference
 * - Amount allocated
 * - Total amount paid
 *
 * Fetches data from /documents/:id/payments endpoint
 */
export function PaymentHistorySection({
  documentId,
  currency,
  className,
}: PaymentHistorySectionProps) {
  const { t } = useTranslation(['sales', 'common'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data: paymentHistory, isLoading, error } = useQuery<PaymentHistory>({
    queryKey: tenantScopedKey(['payment-history', documentId]),
    queryFn: () => fetchPaymentHistory(documentId),
    enabled: tenantId !== null && companyId !== null && !!documentId,
  })

  if (isLoading) {
    return (
      <div className={cn('space-y-3', className)}>
        <h3 className="text-sm font-semibold text-gray-900">
          {t('sales:documents.paymentHistory')}
        </h3>
        <div className="space-y-2">
          <div className="h-16 bg-gray-100 rounded animate-pulse"></div>
          <div className="h-16 bg-gray-100 rounded animate-pulse"></div>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className={cn('rounded-lg border border-red-200 bg-red-50 p-4', className)}>
        <div className="flex items-center gap-2 text-red-700">
          <AlertCircle className="h-4 w-4" />
          <p className="text-sm">
            {t('common:error.loadingData')}
          </p>
        </div>
      </div>
    )
  }

  if (!paymentHistory || paymentHistory.payment_allocations.length === 0) {
    return (
      <div className={cn('space-y-3', className)}>
        <h3 className="text-sm font-semibold text-gray-900">
          {t('sales:documents.paymentHistory')}
        </h3>
        <div className="rounded-lg border border-gray-200 bg-gray-50 p-6 text-center">
          <Receipt className="h-8 w-8 text-gray-400 mx-auto mb-2" />
          <p className="text-sm text-gray-600">
            {t('sales:invoices.paymentHistory.noPayments')}
          </p>
        </div>
      </div>
    )
  }

  // Calculate total paid
  const totalPaid = paymentHistory.payment_allocations.reduce(
    (sum, allocation) => sum + parseFloat(allocation.amount),
    0
  )

  return (
    <div className={cn('space-y-3', className)}>
      <h3 className="text-sm font-semibold text-gray-900">
        {t('sales:documents.paymentHistory')}
      </h3>

      <div className="space-y-2">
        {paymentHistory.payment_allocations.map((allocation) => (
          <div
            key={allocation.id}
            className="rounded-lg border border-gray-200 bg-white p-4 hover:border-gray-300 transition-colors"
          >
            <div className="flex items-start justify-between">
              {/* Left side: Payment details */}
              <div className="space-y-2 flex-1">
                {/* Payment date */}
                <div className="flex items-center gap-2 text-sm">
                  <Calendar className="h-4 w-4 text-gray-400" />
                  <span className="font-medium text-gray-900">
                    {format(new Date(allocation.payment_date), 'PPP')}
                  </span>
                </div>

                {/* Payment method */}
                {allocation.payment_method && (
                  <div className="flex items-center gap-2 text-sm text-gray-600">
                    <CreditCard className="h-4 w-4 text-gray-400" />
                    <span>{allocation.payment_method}</span>
                  </div>
                )}

                {/* Payment reference */}
                {allocation.payment_reference && (
                  <div className="flex items-center gap-2 text-sm text-gray-600">
                    <Hash className="h-4 w-4 text-gray-400" />
                    <span className="font-mono">{allocation.payment_reference}</span>
                  </div>
                )}
              </div>

              {/* Right side: Amount */}
              <div className="text-end">
                <div className="text-base font-semibold text-green-600 font-mono">
                  {formatCurrency(parseFloat(allocation.amount), { currency })}
                </div>
                <div className="text-xs text-gray-500 mt-1">
                  {t('sales:invoices.paymentHistory.allocated')}
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Total paid summary */}
      <div className="border-t border-gray-200 pt-3 mt-4">
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium text-gray-900">
            {t('sales:invoices.paymentHistory.totalPaid')}
          </span>
          <span className="text-base font-bold text-green-700 font-mono">
            {formatCurrency(totalPaid, { currency })}
          </span>
        </div>
      </div>
    </div>
  )
}
