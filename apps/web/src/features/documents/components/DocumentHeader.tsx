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
 *
 * Badge discipline: every chip in the identity row renders at the shared
 * `tokens.badge.base` size. The document type is an identity label (quiet
 * outline chip); lifecycle status and workflow milestones are semantic
 * StatusBadge tones. Amounts never appear in this row — they belong in the
 * `financialCallout` strip.
 */

import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  AlertTriangle,
  Truck,
} from 'lucide-react'
import { StatusBadge, type StatusTone } from '../../../components/atoms/StatusBadge/StatusBadge'
import { tokens } from '../../../lib/designTokens'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'

const statusTones: Record<Document['status'], StatusTone> = {
  draft: 'pending',
  confirmed: 'info',
  posted: 'success',
  cancelled: 'danger',
  received: 'success',
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
  /**
   * Drop the lifecycle status chip from the identity row. Default `false` — every
   * existing caller is unaffected.
   *
   * C-F0w gate r1 (W-1). A PROFORMA rendering carries no status badge: a `posted`
   * document the fiscal chain never sealed wearing a green `Posted` chip beside a
   * banner that says the sale has not been entered in the accounts contradicts
   * itself in front of the customer, and `status` is not the predicate — the seal
   * is. The header stays ignorant of that reasoning on purpose: the caller owns the
   * `is_proforma` decision and passes the consequence.
   */
  suppressStatusBadge?: boolean
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
  suppressStatusBadge = false,
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
        className={`inline-flex items-center gap-2 text-sm ${colorClasses.textGray600} ${colorClasses.hoverTextGray900}`}
      >
        <ArrowLeft className="h-4 w-4" />
        {t('common:actions.back')}
      </Link>

      {/* Row: identity + actions */}
      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className={`text-[1.5rem] leading-8 font-bold ${colorClasses.textGray900}`}>
              {getDocumentNumberLabel(document.document_number)}
            </h1>

            {/* Document Type — identity label, kept quiet */}
            <span className={`${tokens.badge.base} ${tokens.badge.outline}`}>
              {getTypeLabel(document.type)}
            </span>

            {/* Lifecycle Status — absent on a proforma (see `suppressStatusBadge`) */}
            {!suppressStatusBadge && (
              <StatusBadge tone={statusTones[document.status]}>
                {getStatusLabel(document.status)}
              </StatusBadge>
            )}

            {/* Converted to Order Link */}
            {isAlreadyConverted && document.converted_to_order_id != null && (
              <Link
                to={`/sales/orders/${document.converted_to_order_id}`}
                className={`${tokens.badge.base} gap-1.5 ${colorClasses.bgGray100} ${colorClasses.textGray700} ${colorClasses.hoverBgGray200} transition-colors`}
              >
                <ArrowRight className="h-3 w-3" />
                {t('documents.convertedToOrder')}
              </Link>
            )}

            {/* Source Document Link */}
            {hasSourceDocument && sourceDocumentPath && (
              <Link
                to={`${sourceDocumentPath}/${document.source_document_id}`}
                className={`${tokens.badge.base} gap-1.5 ${colorClasses.bgGray100} ${colorClasses.textGray700} ${colorClasses.hoverBgGray200} transition-colors`}
              >
                <ArrowLeft className="h-3 w-3" />
                {document.source_document_number}
              </Link>
            )}

            {/* Fully Delivered Badge for sales orders */}
            {document.type === 'sales_order' && document.fully_delivered && (
              <StatusBadge tone="success" className="gap-1.5">
                <Truck className="h-3 w-3" />
                {t('orders.fullyDelivered', 'Fully Delivered')}
              </StatusBadge>
            )}

            {/* Fully Invoiced Badge for sales orders */}
            {document.type === 'sales_order' && document.fully_invoiced && (
              <StatusBadge tone="success" className="gap-1.5">
                <Check className="h-3 w-3" />
                {t('orders.fullyInvoiced', 'Fully Invoiced')}
              </StatusBadge>
            )}

            {/* Quote Expiry Warning */}
            {quoteExpiryInfo && (
              <StatusBadge
                tone={quoteExpiryInfo.status === 'expired' ? 'danger' : 'warning'}
                className="gap-1.5"
              >
                <AlertTriangle className="h-3 w-3" />
                {quoteExpiryInfo.message === 'expiresIn'
                  ? t('quotes.expiry.expiresIn', { days: quoteExpiryInfo.days })
                  : t(`quotes.expiry.${quoteExpiryInfo.message}`)}
              </StatusBadge>
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
