/**
 * AllocationPreview Component
 * Displays payment allocation preview with tolerance handling
 */

import { useTranslation } from 'react-i18next'
import { AlertCircle, CheckCircle } from 'lucide-react'
import type { PaymentAllocationPreview } from '@/types/treasury'
import { useCurrency } from '@/hooks/useCurrency'

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
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex items-center gap-2">
          <div className="h-5 w-5 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600" />
          <span className="text-sm text-gray-600">
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
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center">
        <AlertCircle className="mx-auto h-12 w-12 text-gray-400" />
        <p className="mt-2 text-sm text-gray-600">
          {t('smartPayment.preview.noAllocations')}
        </p>
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-white">
      {/* Header */}
      <div className="border-b border-gray-200 bg-gray-50 px-4 py-3">
        <h3 className="text-sm font-medium text-gray-900">
          {t('smartPayment.preview.title')}
        </h3>
      </div>

      {/* Allocations Table */}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('smartPayment.preview.invoiceNumber')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('smartPayment.preview.originalBalance')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('smartPayment.preview.allocated')}
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('common:fields.status')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white">
            {preview.allocations.map((allocation) => (
              <tr key={allocation.document_id} className="hover:bg-gray-50">
                <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900">
                  {allocation.document_number}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-700">
                  {allocation.original_balance ? formatAmount(allocation.original_balance) : '-'}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm font-semibold text-green-700">
                  {formatAmount(allocation.amount)}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-sm">
                  {allocation.days_overdue !== undefined && isOverdue(allocation.days_overdue) ? (
                    <div className="flex items-center gap-2">
                      <span className="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-800">
                        {t('common:status.overdue', 'Overdue')}
                      </span>
                      <span className="text-xs text-red-600">
                        {t('treasury:smartPayment.preview.daysOverdue', {
                          days: allocation.days_overdue,
                        })}
                      </span>
                    </div>
                  ) : (
                    <span className="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                      {t('common:status.current', 'Current')}
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Summary */}
      <div className="border-t border-gray-200 bg-gray-50 px-4 py-3 space-y-2">
        {/* Total to Invoices */}
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium text-gray-700">
            {t('smartPayment.preview.totalToInvoices')}
          </span>
          <span className="text-sm font-semibold text-gray-900">
            {formatAmount(preview.total_to_invoices)}
          </span>
        </div>

        {/* Tolerance Write-off */}
        {preview.excess_handling === 'tolerance_writeoff' &&
          parseFloat(preview.excess_amount) > 0 && (
            <div className="flex items-center justify-between rounded-md bg-blue-50 p-2">
              <div className="flex items-center gap-2">
                <CheckCircle className="h-4 w-4 text-blue-600" />
                <span className="text-sm font-medium text-blue-900">
                  {t('smartPayment.preview.toleranceWriteoff')}
                </span>
              </div>
              <span className="text-sm font-semibold text-blue-900">
                {formatAmount(preview.excess_amount)}
              </span>
            </div>
          )}

        {/* Excess Amount (Credit Balance) */}
        {preview.excess_handling === 'credit_balance' &&
          parseFloat(preview.excess_amount) > 0 && (
            <div className="flex items-center justify-between rounded-md bg-yellow-50 p-2">
              <div className="flex items-center gap-2">
                <AlertCircle className="h-4 w-4 text-yellow-600" />
                <span className="text-sm font-medium text-yellow-900">
                  {t('smartPayment.preview.excessAmount')}
                </span>
              </div>
              <span className="text-sm font-semibold text-yellow-900">
                {formatAmount(preview.excess_amount)}
              </span>
            </div>
          )}

        {/* Handling Explanation */}
        {parseFloat(preview.excess_amount) > 0 && (
          <p className="text-xs text-gray-600 pl-6">
            {preview.excess_handling === 'tolerance_writeoff'
              ? t('smartPayment.preview.excessHandling.toleranceWriteoff')
              : t('smartPayment.preview.excessHandling.creditBalance')}
          </p>
        )}
      </div>
    </div>
  )
}
