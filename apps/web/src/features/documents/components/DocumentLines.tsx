/**
 * DocumentLines Component
 *
 * Displays line items table with product, quantity, price, tax, and total.
 * Reusable across all document types.
 */

import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { FileText } from 'lucide-react'
import type { DocumentLine } from '../../../types/document'
import { DesignationCell } from './DesignationCell'
import { textColors } from '../../../lib/designTokens'
import { useLineDesignationFeature } from '../hooks/useLineDesignationFeature'

export interface DocumentLinesProps {
  /** Array of document lines to display */
  lines: DocumentLine[]
  /** Format currency function */
  formatAmount: (amount: string | number) => string
  /** Optional custom title */
  title?: string
  /** Whether to show product links (default: true) */
  showProductLinks?: boolean
  /** Optional class name for the container */
  className?: string
}

export function DocumentLines({
  lines,
  formatAmount,
  title,
  showProductLinks = true,
  className = '',
}: DocumentLinesProps) {
  const { t } = useTranslation(['sales'])
  const designationFeatureEnabled = useLineDesignationFeature()

  return (
    <div className={`rounded-lg border border-gray-200 bg-white ${className}`}>
      <div className="border-b border-gray-200 px-6 py-4">
        <h2 className="text-lg font-semibold text-gray-900">
          <FileText className="me-2 inline h-5 w-5" />
          {title ?? t('documents.lineItems')}
        </h2>
      </div>
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.item')}
              </th>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.description')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.quantity')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.unitPrice')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.tax')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.total')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white">
            {lines.length === 0 ? (
              <tr>
                <td
                  colSpan={6}
                  className="px-6 py-8 text-center text-sm text-gray-500"
                >
                  {t('documents.noLines', 'No line items')}
                </td>
              </tr>
            ) : (
              lines.map((line) => (
                <tr key={line.id}>
                  <td className="whitespace-nowrap px-6 py-4 text-sm font-medium">
                    {showProductLinks && line.product_id ? (
                      <Link
                        to={`/inventory/products/${line.product_id}`}
                        className="text-blue-600 hover:text-blue-800 hover:underline"
                      >
                        {line.product_name}
                      </Link>
                    ) : (
                      <span className="text-gray-900">{line.product_name}</span>
                    )}
                  </td>
                  <td className="px-6 py-4 text-sm">
                    {designationFeatureEnabled ? (
                      <DesignationCell
                        value={line.description}
                        originalSnapshot={line.designation_default_snapshot ?? null}
                        readOnly={true}
                        onCommit={() => { /* read-only: no-op */ }}
                      />
                    ) : (
                      <span className={`text-sm ${textColors.primary}`}>{line.description}</span>
                    )}
                    {designationFeatureEnabled && line.notes && (
                      <p className={`mt-0.5 text-xs ${textColors.tertiary}`}>{line.notes}</p>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {line.quantity}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {formatAmount(line.unit_price)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-500">
                    {line.tax_rate}%
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                    {formatAmount(line.line_total)}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
