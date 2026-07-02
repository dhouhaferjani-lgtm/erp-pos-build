/**
 * DocumentHeader Component
 *
 * Displays document title, number, status badge, dates, and customer/partner info.
 * Reusable across all document types (quote, invoice, sales_order, etc.)
 *
 * Two-zone layout:
 *   Row 1: Back link
 *   Row 2: Identity (number + badges + children) | Actions (right-aligned)
 *   Row 3: Financial callout strip (optional, full-width)
 */

import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  AlertTriangle,
  Truck,
  Package,
} from 'lucide-react'
import type { Document } from '../../../types/document'

// Type and status color mappings
const typeColors: Record<string, string> = {
  quote: 'bg-yellow-100 text-yellow-800',
  order: 'bg-blue-100 text-blue-800',
  sales_order: 'bg-blue-100 text-blue-800',
  purchase_order: 'bg-purple-100 text-purple-800',
  invoice: 'bg-green-100 text-green-800',
  credit_note: 'bg-red-100 text-red-800',
  delivery_note: 'bg-purple-100 text-purple-800',
  return_note: 'bg-orange-100 text-orange-800',
}

const statusColors: Record<Document['status'], string> = {
  draft: 'bg-gray-100 text-gray-800',
  confirmed: 'bg-blue-100 text-blue-800',
  posted: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
  received: 'bg-teal-100 text-teal-800',
}

export interface QuoteExpiryInfo {
  status: 'expired' | 'warning'
  days: number
  message: string
}

export interface DocumentHeaderProps {
  /** The document to display */
  document: Document
  /** Back navigation path */
  backPath: string
  /** Optional quote expiry info for quote documents */
  quoteExpiryInfo?: QuoteExpiryInfo | null
  /** Optional children for additional badges/content in the identity row */
  children?: React.ReactNode
  /** Optional action buttons rendered right-aligned in the identity row */
  actions?: React.ReactNode
  /** Optional full-width strip below the identity row (e.g. outstanding balance) */
  financialCallout?: React.ReactNode
}

/**
 * Get the path to the source document based on type
 */
function getSourceDocumentPath(sourceType: string | null): string | null {
  if (!sourceType) return null
  const paths: Record<string, string> = {
    sales_order: '/sales/orders',
    quote: '/sales/quotes',
    invoice: '/sales/invoices',
    purchase_order: '/purchases/orders',
  }
  return paths[sourceType] ?? null
}

export function DocumentHeader({
  document,
  backPath,
  quoteExpiryInfo,
  children,
  actions,
  financialCallout,
}: DocumentHeaderProps) {
  const { t } = useTranslation(['sales', 'common'])

  // Helper functions for translated labels
  const getTypeLabel = (type: string) => t(`documents.types.${type}`, type)
  const getStatusLabel = (status: string) => t(`sales:documents.statuses.${status}`, status)
  const getDocumentNumberLabel = (documentNumber: string | null) =>
    documentNumber ?? t('sales:documents.draftNumberPlaceholder')

  // Determine if document has been converted
  const isAlreadyConverted = document.converted_to_order_id != null

  // Check if this document has a source document to link back to
  const hasSourceDocument =
    document.source_document_id != null && document.source_document_number != null
  const sourceDocumentPath = getSourceDocumentPath(document.source_document_type ?? null)

  return (
    <div className="space-y-4">
      {/* Back link */}
      <Link
        to={backPath}
        className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('common:actions.back')}
      </Link>

      {/* Row: identity + actions */}
      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-bold text-gray-900">
              {getDocumentNumberLabel(document.document_number)}
            </h1>

            {/* Document Type Badge */}
            <span
              className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[document.type]}`}
            >
              {getTypeLabel(document.type)}
            </span>

            {/* Status Badge */}
            <span
              className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[document.status]}`}
            >
              {getStatusLabel(document.status)}
            </span>

            {/* Converted to Order Link */}
            {isAlreadyConverted && document.converted_to_order_id != null && (
              <Link
                to={`/sales/orders/${document.converted_to_order_id}`}
                className="inline-flex items-center gap-1.5 rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800 hover:bg-purple-200 transition-colors"
              >
                <ArrowRight className="h-3 w-3" />
                {t('documents.convertedToOrder')}
              </Link>
            )}

            {/* Source Document Link */}
            {hasSourceDocument && sourceDocumentPath && (
              <Link
                to={`${sourceDocumentPath}/${document.source_document_id}`}
                className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 hover:bg-gray-200 transition-colors"
              >
                <ArrowLeft className="h-3 w-3" />
                {document.source_document_number}
              </Link>
            )}

            {/* Fully Delivered Badge for sales orders */}
            {document.type === 'sales_order' && document.fully_delivered && (
              <span className="inline-flex items-center gap-1.5 rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                <Truck className="h-3 w-3" />
                {t('orders.fullyDelivered', 'Fully Delivered')}
              </span>
            )}

            {/* Fully Invoiced Badge for sales orders */}
            {document.type === 'sales_order' && document.fully_invoiced && (
              <span className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                <Check className="h-3 w-3" />
                {t('orders.fullyInvoiced', 'Fully Invoiced')}
              </span>
            )}

            {/* Goods Received Badge for purchase orders */}
            {document.goods_received && (
              <span className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                <Package className="h-3 w-3" />
                {t('purchaseOrders.goodsReceived', 'Goods Received')}
              </span>
            )}

            {/* Quote Expiry Warning */}
            {quoteExpiryInfo && (
              <span
                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  quoteExpiryInfo.status === 'expired'
                    ? 'bg-red-100 text-red-800'
                    : 'bg-yellow-100 text-yellow-800'
                }`}
              >
                <AlertTriangle className="h-3 w-3" />
                {quoteExpiryInfo.message === 'expiresIn'
                  ? t('quotes.expiry.expiresIn', { days: quoteExpiryInfo.days })
                  : t(`quotes.expiry.${quoteExpiryInfo.message}`)}
              </span>
            )}

            {/* Additional children (extra badges) */}
            {children}
          </div>
        </div>
        {actions && <div className="flex-shrink-0">{actions}</div>}
      </div>

      {/* Financial callout strip (conditional) */}
      {financialCallout}
    </div>
  )
}
