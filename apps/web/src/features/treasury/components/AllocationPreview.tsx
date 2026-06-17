/**
 * AllocationPreview Component
 * Displays payment allocation preview with tolerance handling
 */

import { useTranslation } from 'react-i18next'
import { AlertCircle, CheckCircle } from 'lucide-react'
import type { PaymentAllocationPreview } from '@/types/treasury'
import { useCurrency } from '@/hooks/useCurrency'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { Spinner } from '@/components/atoms/Spinner'
import { StatusBadge } from '@/components/atoms/StatusBadge'

interface AllocationPreviewProps {
  preview: PaymentAllocationPreview
  isLoading?: boolean
}

/**
 * Display component showing payment allocation preview
 *
 * Shows:
 * - Allocated invoices table with amounts
 * - Tolerance write-off indicator
 * - Excess amount handling
 * - Overdue status for invoices
 */
export function AllocationPreview({ preview, isLoading }: AllocationPreviewProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const { decimals } = useCurrency()

  if (isLoading) {
    return (
      <div className={cn('rounded-lg border bg-white p-4', borderColors.light)}>
        <div className="flex items-center gap-2">
          <Spinner size="sm" />
          <span className={cn('text-sm', textColors.tertiary)}>
            {t('loading', 'Loading preview...')}
          </span>
        </div>
      </div>
    )
  }

  // Format amount to currency-aware decimals
  const formatAmount = (amount: string): string => {
    return parseFloat(amount).toFixed(decimals)
  }

  // Check if invoice is overdue
  const isOverdue = (daysOverdue: number): boolean => {
    return daysOverdue > 0
  }

  // Empty state
  if (preview.allocations.length === 0) {
    return (
      <div className={cn('rounded-lg border p-8 text-center', colors.neutral[50], borderColors.light)}>
        <AlertCircle className={cn('mx-auto h-12 w-12', textColors.disabled)} />
        <p className={cn('mt-2 text-sm', textColors.tertiary)}>
          {t('smartPayment.preview.noAllocations')}
        </p>
      </div>
    )
  }

  return (
    <div className={cn('rounded-lg border bg-white', borderColors.light)}>
      {/* Header */}
      <div className={cn('border-b px-4 py-3', borderColors.light, tokens.table.header)}>
        <h3 className={cn('text-sm font-medium', textColors.primary)}>
          {t('smartPayment.preview.title')}
        </h3>
      </div>

      {/* Allocations Table */}
      <div className="overflow-x-auto">
        <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
          <thead className={tokens.table.header}>
            <tr>
              <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                {t('smartPayment.preview.invoiceNumber')}
              </th>
              <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                {t('smartPayment.preview.originalBalance')}
              </th>
              <th className={cn('px-4 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                {t('smartPayment.preview.allocated')}
              </th>
              <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                {t('common:fields.status')}
              </th>
            </tr>
          </thead>
          <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
            {preview.allocations.map((allocation) => (
              <tr key={allocation.document_id} className={tokens.table.rowHover}>
                <td className={cn('whitespace-nowrap px-4 py-3 text-sm font-medium', textColors.primary)}>
                  {allocation.document_number}
                </td>
                <td className={cn('whitespace-nowrap px-4 py-3 text-end text-sm tabular-nums', textColors.secondary)}>
                  {allocation.original_balance ? formatAmount(allocation.original_balance) : '-'}
                </td>
                <td className={cn('whitespace-nowrap px-4 py-3 text-end text-sm font-semibold tabular-nums', textColors.success)}>
                  {formatAmount(allocation.amount)}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm">
                  {allocation.days_overdue !== undefined && isOverdue(allocation.days_overdue) ? (
                    <div className="flex items-center gap-2">
                      <StatusBadge tone="danger">
                        {t('common:status.overdue', 'Overdue')}
                      </StatusBadge>
                      <span className={cn('text-xs', textColors.error)}>
                        {t('treasury:smartPayment.preview.daysOverdue', {
                          days: allocation.days_overdue,
                        })}
                      </span>
                    </div>
                  ) : (
                    <StatusBadge tone="success">
                      {t('common:status.current', 'Current')}
                    </StatusBadge>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Summary */}
      <div className={cn('border-t px-4 py-3 space-y-2', borderColors.light, tokens.table.header)}>
        {/* Total to Invoices */}
        <div className="flex items-center justify-between">
          <span className={cn('text-sm font-medium', textColors.secondary)}>
            {t('smartPayment.preview.totalToInvoices')}
          </span>
          <span className={cn('text-sm font-semibold tabular-nums', textColors.primary)}>
            {formatAmount(preview.total_to_invoices)}
          </span>
        </div>

        {/* Tolerance Write-off */}
        {preview.excess_handling === 'tolerance_writeoff' &&
          parseFloat(preview.excess_amount) > 0 && (
            <div className={cn('flex items-center justify-between rounded-md p-2', tokens.alert.info)}>
              <div className="flex items-center gap-2">
                <CheckCircle className={cn('h-4 w-4', textColors.brand)} />
                <span className="text-sm font-medium">
                  {t('smartPayment.preview.toleranceWriteoff')}
                </span>
              </div>
              <span className="text-sm font-semibold tabular-nums">
                {formatAmount(preview.excess_amount)}
              </span>
            </div>
          )}

        {/* Excess Amount (Credit Balance) */}
        {preview.excess_handling === 'credit_balance' &&
          parseFloat(preview.excess_amount) > 0 && (
            <div className={cn('flex items-center justify-between rounded-md p-2', tokens.alert.warning)}>
              <div className="flex items-center gap-2">
                <AlertCircle className={cn('h-4 w-4', textColors.warningDark)} />
                <span className="text-sm font-medium">
                  {t('smartPayment.preview.excessAmount')}
                </span>
              </div>
              <span className="text-sm font-semibold tabular-nums">
                {formatAmount(preview.excess_amount)}
              </span>
            </div>
          )}

        {/* Handling Explanation */}
        {parseFloat(preview.excess_amount) > 0 && (
          <p className={cn('text-xs pl-6', textColors.tertiary)}>
            {preview.excess_handling === 'tolerance_writeoff'
              ? t('smartPayment.preview.excessHandling.toleranceWriteoff')
              : t('smartPayment.preview.excessHandling.creditBalance')}
          </p>
        )}
      </div>
    </div>
  )
}
