/**
 * DocumentTotals Component
 *
 * Displays subtotal, tax, total, and optional balance due with payment status.
 * Reusable across all document types.
 */

import { useTranslation } from 'react-i18next'
import type { Document } from '../../../types/document'

export interface DocumentTotalsProps {
  /** The document to display totals for */
  document: Document
  /** Format currency function */
  formatAmount: (amount: string | number) => string
  /** Whether to show balance due section (for invoices and confirmed sales orders) */
  showBalanceDue?: boolean
  /** Optional class name for the container */
  className?: string
}

/**
 * Get balance due color class
 */
function getBalanceDueColorClass(document: Document): string {
  const balanceDue = parseFloat(document.balance_due ?? document.total ?? '0')
  const total = parseFloat(document.total ?? '0')
  const isPaid = balanceDue === 0
  const isOverdue =
    document.due_date && new Date(document.due_date) < new Date() && balanceDue > 0

  if (isPaid) return 'text-green-600'
  if (isOverdue) return 'text-red-600'
  if (balanceDue < total) return 'text-yellow-600'
  return 'text-gray-900'
}

export function DocumentTotals({
  document,
  formatAmount,
  showBalanceDue = false,
  className = '',
}: DocumentTotalsProps) {
  const { t } = useTranslation(['sales'])

  const balanceDue = parseFloat(document.balance_due ?? document.total ?? '0')
  const total = parseFloat(document.total ?? '0')
  const shouldShowBalanceDue =
    showBalanceDue ||
    (document.type === 'invoice' && document.status === 'posted') ||
    (document.type === 'sales_order' && document.status === 'confirmed')

  // Calculate payment status inline to avoid t() typing issues
  const getPaymentStatusInfo = (): { badgeClass: string; label: string } | null => {
    if (!shouldShowBalanceDue) return null

    if (balanceDue === 0) {
      return {
        badgeClass: 'bg-green-100 text-green-800',
        label: t('documents.statuses.paid'),
      }
    }

    if (
      document.type === 'invoice' &&
      document.due_date &&
      new Date(document.due_date) < new Date()
    ) {
      const daysOverdue = Math.floor(
        (new Date().getTime() - new Date(document.due_date).getTime()) /
          (1000 * 60 * 60 * 24)
      )
      return {
        badgeClass: 'bg-red-100 text-red-800',
        label: `${t('documents.statuses.overdue')} (${String(daysOverdue)}d)`,
      }
    }

    if (balanceDue < total) {
      return {
        badgeClass: 'bg-yellow-100 text-yellow-800',
        label:
          document.type === 'sales_order'
            ? t('documents.statuses.prepaid')
            : t('documents.statuses.partial'),
      }
    }

    return {
      badgeClass: 'bg-orange-100 text-orange-800',
      label: t('documents.statuses.unpaid'),
    }
  }

  const paymentStatus = getPaymentStatusInfo()

  return (
    <div
      className={`rounded-lg border border-gray-200 bg-white p-6 ${className}`}
    >
      <h2 className="mb-4 text-lg font-semibold text-gray-900">
        {t('documents.totals')}
      </h2>
      <dl className="space-y-3">
        {/* Subtotal */}
        <div className="flex justify-between">
          <dt className="text-sm text-gray-500">{t('documents.subtotal')}</dt>
          <dd className="text-sm font-medium text-gray-900">
            {formatAmount(document.subtotal ?? 0)}
          </dd>
        </div>

        {/* Tax */}
        <div className="flex justify-between">
          <dt className="text-sm text-gray-500">{t('documents.tax')}</dt>
          <dd className="text-sm font-medium text-gray-900">
            {formatAmount(document.tax_amount ?? 0)}
          </dd>
        </div>

        {/* Total */}
        <div className="border-t border-gray-200 pt-3">
          <div className="flex justify-between">
            <dt className="text-base font-semibold text-gray-900">
              {t('documents.total')}
            </dt>
            <dd className="text-base font-semibold text-gray-900">
              {formatAmount(document.total ?? 0)}
            </dd>
          </div>
        </div>

        {/* Balance Due Section */}
        {shouldShowBalanceDue && (
          <>
            <div className="border-t border-gray-200 pt-3">
              <div className="flex items-center justify-between">
                <dt className="text-base font-semibold text-gray-900">
                  {document.type === 'sales_order'
                    ? t('documents.amountDue')
                    : t('documents.balanceDue')}
                </dt>
                <dd
                  className={`text-base font-semibold ${getBalanceDueColorClass(document)}`}
                >
                  {formatAmount(balanceDue)}
                </dd>
              </div>
            </div>

            {/* Payment Status Badge */}
            {paymentStatus && (
              <div className="flex items-center justify-between">
                <dt className="text-sm text-gray-500">
                  {t('documents.paymentStatus')}
                </dt>
                <dd>
                  <span
                    className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${paymentStatus.badgeClass}`}
                  >
                    {paymentStatus.label}
                  </span>
                </dd>
              </div>
            )}
          </>
        )}
      </dl>
    </div>
  )
}
