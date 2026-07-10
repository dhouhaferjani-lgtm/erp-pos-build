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
import { NotesCell } from './NotesCell'
import { LineItemsTable, type LineItemsTableColumn } from '../../../components/molecules/line-items/LineItemsTable'
import { ProductCell } from '../../../components/molecules/line-items/ProductCell'
import { textColors } from '../../../lib/designTokens'
import { useLineDesignationFeature } from '../hooks/useLineDesignationFeature'
import { formatPercent } from '../../../lib/format'

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

  const lineColumns: LineItemsTableColumn<DocumentLine>[] = [
    {
      id: 'article',
      header: t('lineItems.item'),
      headerClassName: 'min-w-72',
      Cell: ({ line }) => {
        const productName = line.product_name || line.description || '-'
        const productSummary = (
          <ProductCell
            size="sm"
            product={{
              name: productName,
              sku: line.product_code ?? null,
              barcode: line.product_barcode ?? null,
              primary_image_url: line.primary_image_url ?? null,
            }}
          />
        )

        return (
          <div className="min-w-72 space-y-1">
            {showProductLinks && line.product_id ? (
              <Link
                to={`/inventory/products/${line.product_id}`}
                className={`block ${textColors.brand} ${textColors.hoverBrand} hover:underline`}
              >
                {productSummary}
              </Link>
            ) : (
              productSummary
            )}
            <div className="max-w-xl">
              {designationFeatureEnabled ? (
                <DesignationCell
                  value={line.description || productName}
                  originalSnapshot={line.designation_default_snapshot ?? null}
                  readOnly={true}
                  className="min-w-0"
                  valueClassName={`line-clamp-2 text-xs ${textColors.tertiary}`}
                  onCommit={() => { /* read-only: no-op */ }}
                />
              ) : (
                <span className={`block line-clamp-2 text-xs ${textColors.tertiary}`}>
                  {line.description || productName}
                </span>
              )}
              {designationFeatureEnabled && line.notes ? (
                <NotesCell
                  value={line.notes}
                  readOnly={true}
                  className="mt-0.5 min-w-0"
                  valueClassName={`line-clamp-2 text-xs ${textColors.tertiary}`}
                  onCommit={() => { /* read-only: no-op */ }}
                />
              ) : line.notes ? (
                <span className={`mt-0.5 block line-clamp-2 text-xs ${textColors.tertiary}`}>{line.notes}</span>
              ) : null}
            </div>
          </div>
        )
      },
    },
    {
      id: 'quantity',
      header: t('lineItems.quantity'),
      headerClassName: 'w-24 text-end',
      cellClassName: `whitespace-nowrap text-end text-sm ${textColors.primary}`,
      Cell: ({ line }) => <>{line.quantity}</>,
    },
    {
      id: 'unit-price',
      header: t('lineItems.unitPrice'),
      headerClassName: 'w-32 text-end',
      cellClassName: `whitespace-nowrap text-end text-sm ${textColors.primary}`,
      Cell: ({ line }) => <>{formatAmount(line.unit_price)}</>,
    },
    {
      id: 'tax',
      header: t('lineItems.tax'),
      headerClassName: 'w-20 text-end',
      cellClassName: `whitespace-nowrap text-end text-sm ${textColors.tertiary}`,
      Cell: ({ line }) => <>{formatPercent(line.tax_rate ?? '0')}</>,
    },
    {
      id: 'total',
      header: t('lineItems.total'),
      headerClassName: 'w-32 text-end',
      cellClassName: `whitespace-nowrap text-end text-sm font-medium ${textColors.primary}`,
      Cell: ({ line }) => <>{formatAmount(line.line_total)}</>,
    },
  ]

  return (
    <div className={className}>
      <LineItemsTable
        title={(
          <>
            <FileText className="me-2 inline h-5 w-5" />
            {title ?? t('documents.lineItems')}
          </>
        )}
        lines={lines}
        columns={lineColumns}
        getLineKey={(line) => line.id}
        emptyTitle={t('documents.noLines', 'No line items')}
        readonly={true}
        renderLineDetail={(line) => {
          const showFullDescription =
            line.description.trim().length > 80 &&
            line.description.trim() !== (line.product_name || '').trim()
          const showFullNotes = (line.notes ?? '').trim().length > 80

          if (!showFullDescription && !showFullNotes) return null

          return (
            <div className={`px-6 py-2 text-xs ${textColors.secondary}`}>
              {showFullDescription ? <div>{line.description}</div> : null}
              {showFullNotes ? <div>{line.notes}</div> : null}
            </div>
          )
        }}
      />
    </div>
  )
}
