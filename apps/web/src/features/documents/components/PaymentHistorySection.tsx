import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { AlertCircle, Calendar, CreditCard, Hash, Receipt } from 'lucide-react'
import { cn } from '@/lib/utils'
import { EntityLink } from '@/components/molecules/EntityLink'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchPaymentHistory, type PaymentHistory } from '../api/paymentHistory'
import { formatCurrency } from '../../../lib/format'
import { format } from 'date-fns'
import { bcadd } from '@/lib/decimal'
import { colorClasses } from '@/lib/designTokens'

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
        <h3 className={`text-sm font-semibold ${colorClasses.textGray900}`}>
          {t('sales:documents.paymentHistory')}
        </h3>
        <div className="space-y-2">
          <div className={`h-16 ${colorClasses.bgGray100} rounded animate-pulse`}></div>
          <div className={`h-16 ${colorClasses.bgGray100} rounded animate-pulse`}></div>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className={cn(`rounded-lg border ${colorClasses.borderRed200} ${colorClasses.bgRed50} p-4`, className)}>
        <div className={`flex items-center gap-2 ${colorClasses.textRed700}`}>
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
        <h3 className={`text-sm font-semibold ${colorClasses.textGray900}`}>
          {t('sales:documents.paymentHistory')}
        </h3>
        <div className={`rounded-lg border ${colorClasses.borderGray200} ${colorClasses.bgGray50} p-6 text-center`}>
          <Receipt className={`h-8 w-8 ${colorClasses.textGray400} mx-auto mb-2`} />
          <p className={`text-sm ${colorClasses.textGray600}`}>
            {t('sales:invoices.paymentHistory.noPayments')}
          </p>
        </div>
      </div>
    )
  }

  // Calculate total paid
  const totalPaid = paymentHistory.payment_allocations.reduce(
    (sum, allocation) => bcadd(sum, allocation.amount, 3),
    '0'
  )

  return (
    <div className={cn('space-y-3', className)}>
      <h3 className={`text-sm font-semibold ${colorClasses.textGray900}`}>
        {t('sales:documents.paymentHistory')}
      </h3>

      <div className="space-y-2">
        {paymentHistory.payment_allocations.map((allocation) => (
          <div
            key={allocation.id}
            className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-4 ${colorClasses.hoverBorderGray300} transition-colors`}
          >
            <div className="flex items-start justify-between">
              {/* Left side: Payment details */}
              <div className="space-y-2 flex-1">
                {/* Payment date */}
                <div className="flex items-center gap-2 text-sm">
                  <Calendar className={`h-4 w-4 ${colorClasses.textGray400}`} />
                  <EntityLink
                    type="payment"
                    id={allocation.payment_id}
                    label={format(new Date(allocation.payment_date), 'PPP')}
                    className="font-medium"
                  />
                </div>

                {/* Payment method */}
                {allocation.payment_method && (
                  <div className={`flex items-center gap-2 text-sm ${colorClasses.textGray600}`}>
                    <CreditCard className={`h-4 w-4 ${colorClasses.textGray400}`} />
                    <span>{allocation.payment_method}</span>
                  </div>
                )}

                {/* Payment reference */}
                {allocation.payment_reference && (
                  <div className={`flex items-center gap-2 text-sm ${colorClasses.textGray600}`}>
                    <Hash className={`h-4 w-4 ${colorClasses.textGray400}`} />
                    <span className="font-mono">{allocation.payment_reference}</span>
                  </div>
                )}
              </div>

              {/* Right side: Amount */}
              <div className="text-end">
                <div className={`text-base font-semibold ${colorClasses.textGreen600} font-mono`}>
                  {formatCurrency(allocation.amount, { currency })}
                </div>
                <div className={`text-xs ${colorClasses.textGray500} mt-1`}>
                  {t('sales:invoices.paymentHistory.allocated')}
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Total paid summary */}
      <div className={`border-t ${colorClasses.borderGray200} pt-3 mt-4`}>
        <div className="flex items-center justify-between">
          <span className={`text-sm font-medium ${colorClasses.textGray900}`}>
            {t('sales:invoices.paymentHistory.totalPaid')}
          </span>
          <span className={`text-base font-bold ${colorClasses.textGreen700} font-mono`}>
            {formatCurrency(totalPaid, { currency })}
          </span>
        </div>
      </div>
    </div>
  )
}
