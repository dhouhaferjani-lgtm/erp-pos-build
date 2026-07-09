import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchTaxBreakdown, type TaxBreakdown } from '../api/taxApi'
import { getDecimals } from '@/hooks/useCurrency'
import { bccomp } from '@/lib/decimal'
import { formatNumber, formatPercent } from '@/lib/format'

export interface DocumentTotalsProps {
  documentId: string
  documentType: 'invoice' | 'quote' | 'sales_order' | 'credit_note'
  currency: string
  showBalanceDue?: boolean
  balanceDue?: number
  className?: string
}

/**
 * Inline document totals component displaying:
 * - Subtotal
 * - Individual tax lines with rates (e.g., "TVA 19%: 190.000")
 * - Stamp duty (if applicable)
 * - Total
 * - Balance due (optional, for posted invoices)
 *
 * Supports multi-currency formatting:
 * - TND: 3 decimal places
 * - EUR, USD, etc: 2 decimal places
 */
export function DocumentTotals({
  documentId,
  documentType: _documentType,
  currency,
  showBalanceDue = false,
  balanceDue,
  className,
}: DocumentTotalsProps) {
  const { t } = useTranslation('sales')
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data: taxBreakdown, isLoading, error } = useQuery<TaxBreakdown>({
    queryKey: tenantScopedKey(['tax-breakdown', documentId]),
    queryFn: () => fetchTaxBreakdown(documentId),
    enabled: tenantId !== null && companyId !== null && !!documentId,
  })

  // Determine decimal places based on currency
  const decimals = getDecimals(currency)

  const formatAmount = (amount: string | number): string => {
    return formatNumber(amount, decimals)
  }

  if (isLoading) {
    return (
      <div className={cn('space-y-2', className)}>
        <div className="h-4 bg-gray-200 rounded animate-pulse w-3/4 ms-auto"></div>
        <div className="h-4 bg-gray-200 rounded animate-pulse w-3/4 ms-auto"></div>
        <div className="h-4 bg-gray-200 rounded animate-pulse w-3/4 ms-auto"></div>
      </div>
    )
  }

  if (error || !taxBreakdown) {
    return (
      <div className={cn('rounded-lg border border-red-200 bg-red-50 p-4', className)}>
        <div className="flex items-center gap-2 text-red-700">
          <AlertCircle className="h-4 w-4" />
          <p className="text-sm">
            {t('tax.breakdown.error')}
          </p>
        </div>
      </div>
    )
  }

  const hasStampDuty = bccomp(taxBreakdown.stamp_duty_amount, '0') > 0
  const hasTaxes = bccomp(taxBreakdown.total_tax_amount, '0') > 0
  const hasBalanceDue = showBalanceDue && balanceDue !== undefined && balanceDue > 0

  return (
    <div className={cn('space-y-2', className)}>
      {/* Subtotal */}
      <div className="flex items-center justify-between py-1">
        <span className="text-sm text-gray-600">
          {t('documents.subtotal')}
        </span>
        <span className="text-sm font-medium text-gray-900 font-mono">
          {formatAmount(taxBreakdown.subtotal)} {currency}
        </span>
      </div>

      {/* Individual tax lines from tax_details */}
      {hasTaxes && taxBreakdown.tax_details.length > 0 && (
        <>
          {taxBreakdown.tax_details
            .filter((detail) => !detail.tax_name.toLowerCase().includes('timbre'))
            .map((detail, index) => {
              const displayName = detail.tax_type === 'percentage' && detail.tax_rate
                ? `${detail.tax_name} ${formatPercent(detail.tax_rate)}`
                : detail.tax_name

              return (
                <div key={index} className="flex items-center justify-between py-1">
                  <span className="text-sm text-gray-600">
                    {displayName}
                  </span>
                  <span className="text-sm font-medium text-gray-900 font-mono">
                    {formatAmount(detail.tax_amount)} {currency}
                  </span>
                </div>
              )
            })}
        </>
      )}

      {/* Stamp duty (separate from other taxes) */}
      {hasStampDuty && (
        <div className="flex items-center justify-between py-1">
          <span className="text-sm text-gray-600">
            {t('tax.breakdown.stampDuty')}
          </span>
          <span className="text-sm font-medium text-gray-900 font-mono">
            {formatAmount(taxBreakdown.stamp_duty_amount)} {currency}
          </span>
        </div>
      )}

      {/* Divider before total */}
      <div className="border-t border-gray-300 my-2"></div>

      {/* Total */}
      <div className="flex items-center justify-between py-1">
        <span className="text-base font-semibold text-gray-900">
          {t('documents.total')}
        </span>
        <span className="text-base font-bold text-gray-900 font-mono">
          {formatAmount(taxBreakdown.total)} {currency}
        </span>
      </div>

      {/* Balance due (for posted invoices) */}
      {hasBalanceDue && (
        <>
          <div className="border-t border-gray-200 my-2"></div>
          <div className="flex items-center justify-between py-1">
            <span className="text-sm font-medium text-blue-700">
              {t('invoices.balanceDue')}
            </span>
            <span className="text-sm font-semibold text-blue-700 font-mono">
              {formatAmount(balanceDue)} {currency}
            </span>
          </div>
        </>
      )}

      {/* No taxes message */}
      {!hasTaxes && (
        <div className="flex items-start gap-2 rounded-lg bg-gray-50 p-3 mt-2">
          <AlertCircle className="h-4 w-4 text-gray-600 flex-shrink-0 mt-0.5" />
          <p className="text-sm text-gray-600">
            {t('tax.breakdown.noTaxes')}
          </p>
        </div>
      )}
    </div>
  )
}
