/**
 * DocumentActions Component
 *
 * Displays action buttons for document operations (Edit, Delete, Cancel, Confirm, etc.)
 * Reusable across all document types with type-specific actions.
 */

import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Edit,
  X,
  Check,
  ArrowRight,
  Truck,
  Package,
  CreditCard,
  MinusCircle,
  Download,
  Eye,
  Printer,
  Send,
} from 'lucide-react'
import type { Document } from '../../../types/document'
import type { DocumentType } from '../DocumentListPage'
import { colorClasses } from '@/lib/designTokens'

// Conversion targets by document type
const conversionTargets: Partial<
  Record<DocumentType, { label: string; targetType: DocumentType; path: string }>
> = {
  quote: { label: 'Convert to Order', targetType: 'sales_order', path: '/sales/orders' },
  sales_order: {
    label: 'Convert to Invoice',
    targetType: 'invoice',
    path: '/sales/invoices',
  },
}

export interface DocumentActionsProps {
  /** The document for which actions are displayed */
  document: Document
  /** Base path for edit link */
  basePath: string
  /** Whether any action is currently pending */
  isActionPending: boolean

  // Action handlers
  onConfirm?: () => void
  onCancel?: () => void
  onPost?: () => void
  onConvert?: () => void
  onConvertToDelivery?: () => void
  onReceiveGoods?: () => void
  onRecordPayment?: () => void
  onCreateCreditNote?: () => void
  onCreateReturnNote?: () => void
  onSendEmail?: () => void
  onDownloadPdf?: () => void
  onPreviewPdf?: () => void
  onPrintPdf?: () => void

  // Loading states
  isDownloading?: boolean
  isPreviewing?: boolean
  isPrinting?: boolean
  isSendingEmail?: boolean

  /** Optional additional actions to render */
  children?: React.ReactNode
}

export function DocumentActions({
  document,
  basePath,
  isActionPending,
  onConfirm,
  onCancel,
  onPost,
  onConvert,
  onConvertToDelivery,
  onReceiveGoods,
  onRecordPayment,
  onCreateCreditNote,
  onCreateReturnNote,
  onSendEmail,
  onDownloadPdf,
  onPreviewPdf,
  onPrintPdf,
  isDownloading = false,
  isPreviewing = false,
  isPrinting = false,
  isSendingEmail = false,
  children,
}: DocumentActionsProps) {
  const { t } = useTranslation(['sales', 'common'])

  // Determine if document has been converted
  const isAlreadyConverted = document.converted_to_order_id != null

  // Determine available actions based on status and type
  const canEdit = document.status === 'draft' && !isAlreadyConverted
  const canConfirm = document.status === 'draft' && !isAlreadyConverted
  const canCancel =
    (document.status === 'draft' || document.status === 'confirmed') && !isAlreadyConverted
  const canPost =
    document.status === 'confirmed' &&
    (document.type === 'invoice' || document.type === 'credit_note')

  // Conversion logic
  const conversionTarget = conversionTargets[document.type as DocumentType]
  const canConvert =
    document.status === 'confirmed' &&
    conversionTarget != null &&
    !isAlreadyConverted &&
    !(document.type === 'sales_order' && document.fully_invoiced)

  const canConvertToDelivery =
    document.type === 'sales_order' &&
    document.status === 'confirmed' &&
    !document.fully_delivered

  const canReceiveGoods =
    document.type === 'purchase_order' &&
    document.status === 'confirmed' &&
    !document.goods_received

  const canRecordPayment =
    (document.type === 'invoice' && document.status === 'posted') ||
    (document.type === 'sales_order' && document.status === 'confirmed')

  const canCreateCreditNote = document.type === 'invoice' && document.status === 'posted'
  const canCreateReturnNote =
    (document.type === 'invoice' && document.status === 'posted') ||
    (document.type === 'delivery_note' && document.status === 'confirmed')

  return (
    <div className="flex items-center gap-2">
      {/* Download PDF button */}
      {onDownloadPdf && (
        <button
          type="button"
          disabled={isDownloading}
          onClick={onDownloadPdf}
          className={`inline-flex items-center gap-2 rounded-lg border ${colorClasses.borderGray300} bg-white px-3 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50 transition-colors`}
          title={t('common:actions.downloadPdf')}
        >
          <Download className="h-4 w-4" />
        </button>
      )}

      {/* Preview PDF button */}
      {onPreviewPdf && (
        <button
          type="button"
          disabled={isPreviewing}
          onClick={onPreviewPdf}
          className={`inline-flex items-center gap-2 rounded-lg border ${colorClasses.borderGray300} bg-white px-3 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50 transition-colors`}
          title={t('common:actions.previewPdf')}
        >
          <Eye className="h-4 w-4" />
        </button>
      )}

      {/* Print button */}
      {onPrintPdf && (
        <button
          type="button"
          disabled={isPrinting}
          onClick={onPrintPdf}
          className={`inline-flex items-center gap-2 rounded-lg border ${colorClasses.borderGray300} bg-white px-3 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50 transition-colors`}
          title={t('common:actions.printPdf')}
        >
          <Printer className="h-4 w-4" />
        </button>
      )}

      {/* Send Email button */}
      {onSendEmail && (
        <button
          type="button"
          disabled={isSendingEmail}
          onClick={onSendEmail}
          className={`inline-flex items-center gap-2 rounded-lg border ${colorClasses.borderGray300} bg-white px-3 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50 transition-colors`}
          title={t('common:actions.sendEmail')}
        >
          <Send className="h-4 w-4" />
        </button>
      )}

      {/* Cancel button */}
      {canCancel && onCancel && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onCancel}
          className={`inline-flex items-center gap-2 rounded-lg border ${colorClasses.borderRed300} bg-white px-3 py-2 text-sm font-medium ${colorClasses.textRed700} ${colorClasses.hoverBgRed50} disabled:opacity-50 transition-colors`}
        >
          <X className="h-4 w-4" />
          {t('documents.cancel')}
        </button>
      )}

      {/* Edit button */}
      {canEdit && (
        <Link
          to={`${basePath}/${document.id}/edit`}
          className={`inline-flex items-center gap-2 rounded-lg border ${colorClasses.borderGray300} bg-white px-3 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} transition-colors`}
        >
          <Edit className="h-4 w-4" />
          {t('actions.edit')}
        </Link>
      )}

      {/* Confirm button */}
      {canConfirm && onConfirm && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onConfirm}
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700} disabled:opacity-50 transition-colors`}
        >
          <Check className="h-4 w-4" />
          {t('common:actions.confirm')}
        </button>
      )}

      {/* Post button - for invoices and credit notes */}
      {canPost && onPost && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onPost}
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgGreen600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgGreen700} disabled:opacity-50 transition-colors`}
        >
          <Check className="h-4 w-4" />
          {t('documents.post')}
        </button>
      )}

      {/* Convert button */}
      {canConvert && onConvert && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onConvert}
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgPurple600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgPurple700} disabled:opacity-50 transition-colors`}
        >
          <ArrowRight className="h-4 w-4" />
          {document.type === 'quote'
            ? t('quotes.convertToOrder')
            : t('orders.convertToInvoice')}
        </button>
      )}

      {/* Convert to Delivery Note button - for sales orders */}
      {canConvertToDelivery && onConvertToDelivery && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onConvertToDelivery}
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgIndigo600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgIndigo700} disabled:opacity-50 transition-colors`}
        >
          <Truck className="h-4 w-4" />
          {t('orders.convertToDelivery')}
        </button>
      )}

      {/* Receive Goods button - for confirmed purchase orders */}
      {canReceiveGoods && onReceiveGoods && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onReceiveGoods}
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgTeal600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgTeal700} disabled:opacity-50 transition-colors`}
        >
          <Package className="h-4 w-4" />
          {t('purchaseOrders.receiveGoods')}
        </button>
      )}

      {/* Record Payment button - for posted invoices and confirmed sales orders */}
      {canRecordPayment && onRecordPayment && (
        <button
          type="button"
          onClick={onRecordPayment}
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgGreen600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgGreen700} transition-colors`}
        >
          <CreditCard className="h-4 w-4" />
          {t('documents.recordPayment')}
        </button>
      )}

      {/* Create Credit Note button - for posted invoices */}
      {canCreateCreditNote && onCreateCreditNote && (
        <button
          type="button"
          onClick={onCreateCreditNote}
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgRed600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgRed700} transition-colors`}
        >
          <MinusCircle className="h-4 w-4" />
          {t('documents.createCreditNote')}
        </button>
      )}

      {/* Create Return Note button */}
      {canCreateReturnNote && onCreateReturnNote && (
        <button
          type="button"
          onClick={onCreateReturnNote}
          className={`inline-flex items-center gap-2 rounded-lg ${colorClasses.bgOrange600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgOrange700} transition-colors`}
        >
          <Package className="h-4 w-4" />
          {document.type === 'invoice'
            ? t('returnNotes.createFromInvoice', 'Create Return Note')
            : t('returnNotes.createFromDelivery', 'Create Return Note')}
        </button>
      )}

      {/* Additional children */}
      {children}
    </div>
  )
}
