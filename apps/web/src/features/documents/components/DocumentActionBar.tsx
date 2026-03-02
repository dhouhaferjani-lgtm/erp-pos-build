import { useState, useRef, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Edit,
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
  Lock,
  MoreVertical,
} from 'lucide-react'
import type { Document } from '../../../types/document'

export interface DocumentActionBarProps {
  document: Document
  basePath: string
  isActionPending: boolean

  onConfirm?: () => void
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

  isDownloading?: boolean
  isPreviewing?: boolean
  isPrinting?: boolean
  isSendingEmail?: boolean
}

export function DocumentActionBar({
  document,
  basePath,
  isActionPending,
  onConfirm,
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
}: DocumentActionBarProps) {
  const { t } = useTranslation(['sales', 'common'])
  const [isDropdownOpen, setIsDropdownOpen] = useState(false)
  const dropdownRef = useRef<HTMLDivElement>(null)

  // Close dropdown on click outside
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsDropdownOpen(false)
      }
    }

    if (isDropdownOpen) {
      window.addEventListener('mousedown', handleClickOutside)
      return () => { window.removeEventListener('mousedown', handleClickOutside) }
    }
  }, [isDropdownOpen])

  // Determine available actions based on status and type
  const isAlreadyConverted = document.converted_to_order_id != null
  const canEdit = document.status === 'draft' && !isAlreadyConverted
  const canConfirm = document.status === 'draft' && !isAlreadyConverted
  const canPost =
    document.status === 'confirmed' &&
    (document.type === 'invoice' || document.type === 'credit_note')

  const canConvert =
    document.status === 'confirmed' &&
    document.type === 'quote' &&
    !isAlreadyConverted

  const canConvertToInvoice =
    document.type === 'sales_order' &&
    document.status === 'confirmed' &&
    !document.fully_invoiced

  const canConvertToDelivery =
    document.type === 'sales_order' &&
    document.status === 'confirmed' &&
    !document.fully_delivered

  const canReceiveGoods =
    document.type === 'purchase_order' &&
    document.status === 'confirmed' &&
    !document.goods_received

  const canRecordPayment =
    (document.type === 'invoice' && ['confirmed', 'posted'].includes(document.status) && document.payment_status !== 'paid') ||
    (document.type === 'purchase_order' && ['confirmed', 'received'].includes(document.status) && document.payment_status !== 'paid') ||
    (document.type === 'sales_order' && document.status === 'confirmed' && document.payment_status !== 'paid')

  const canCreateCreditNote = document.type === 'invoice' && document.status === 'posted'
  const canCreateReturnNote =
    (document.type === 'invoice' && document.status === 'posted') ||
    (document.type === 'delivery_note' && document.status === 'confirmed')

  // Check if there are any dropdown items
  const hasDropdownItems = canEdit || onDownloadPdf || onPreviewPdf || onPrintPdf || onSendEmail

  return (
    <div className="flex items-center gap-2">
      {/* PRIMARY ACTIONS - always visible */}

      {canConfirm && onConfirm && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onConfirm}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
        >
          <Check className="h-4 w-4" />
          {t('actions.confirm')}
        </button>
      )}

      {canPost && onPost && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onPost}
          className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 transition-colors"
        >
          <Lock className="h-4 w-4" />
          {t('documents.post')}
        </button>
      )}

      {canConvert && onConvert && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onConvert}
          className="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50 transition-colors"
        >
          <ArrowRight className="h-4 w-4" />
          {t('quotes.convertToOrder')}
        </button>
      )}

      {canConvertToDelivery && onConvertToDelivery && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onConvertToDelivery}
          className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
        >
          <Truck className="h-4 w-4" />
          {t('orders.convertToDelivery')}
        </button>
      )}

      {canConvertToInvoice && onConvert && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onConvert}
          className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 transition-colors"
        >
          <ArrowRight className="h-4 w-4" />
          {t('orders.convertToInvoice')}
        </button>
      )}

      {canReceiveGoods && onReceiveGoods && (
        <button
          type="button"
          disabled={isActionPending}
          onClick={onReceiveGoods}
          className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50 transition-colors"
        >
          <Package className="h-4 w-4" />
          {t('purchaseOrders.receiveGoods')}
        </button>
      )}

      {canRecordPayment && onRecordPayment && (
        <button
          type="button"
          onClick={onRecordPayment}
          className="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition-colors"
        >
          <CreditCard className="h-4 w-4" />
          {t('documents.recordPayment')}
        </button>
      )}

      {canCreateCreditNote && onCreateCreditNote && (
        <button
          type="button"
          onClick={onCreateCreditNote}
          className="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition-colors"
        >
          <MinusCircle className="h-4 w-4" />
          {t('documents.createCreditNote')}
        </button>
      )}

      {canCreateReturnNote && onCreateReturnNote && (
        <button
          type="button"
          onClick={onCreateReturnNote}
          className="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700 transition-colors"
        >
          <Package className="h-4 w-4" />
          {document.type === 'invoice'
            ? t('returnNotes.createFromInvoice', 'Create Return Note')
            : t('returnNotes.createFromDelivery', 'Create Return Note')}
        </button>
      )}

      {/* MORE DROPDOWN - secondary actions */}
      {hasDropdownItems && (
        <div className="relative" ref={dropdownRef}>
          <button
            type="button"
            onClick={() => setIsDropdownOpen(!isDropdownOpen)}
            className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white p-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
            aria-label={t('common:actions.more', 'More actions')}
          >
            <MoreVertical className="h-5 w-5" />
          </button>

          {isDropdownOpen && (
            <div className="absolute right-0 z-20 mt-2 w-48 origin-top-right rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
              {canEdit && (
                <Link
                  to={`${basePath}/${document.id}/edit`}
                  className="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                  onClick={() => setIsDropdownOpen(false)}
                >
                  <Edit className="h-4 w-4 text-gray-400" />
                  {t('actions.edit')}
                </Link>
              )}

              {canEdit && (onDownloadPdf || onPreviewPdf || onPrintPdf || onSendEmail) && (
                <div className="my-1 border-t border-gray-100" />
              )}

              {onPreviewPdf && (
                <button
                  type="button"
                  disabled={isPreviewing}
                  onClick={() => {
                    onPreviewPdf()
                    setIsDropdownOpen(false)
                  }}
                  className="flex w-full items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                >
                  <Eye className="h-4 w-4 text-gray-400" />
                  {t('common:preview')}
                </button>
              )}

              {onDownloadPdf && (
                <button
                  type="button"
                  disabled={isDownloading}
                  onClick={() => {
                    onDownloadPdf()
                    setIsDropdownOpen(false)
                  }}
                  className="flex w-full items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                >
                  <Download className="h-4 w-4 text-gray-400" />
                  {t('common:download')}
                </button>
              )}

              {onPrintPdf && (
                <button
                  type="button"
                  disabled={isPrinting}
                  onClick={() => {
                    onPrintPdf()
                    setIsDropdownOpen(false)
                  }}
                  className="flex w-full items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                >
                  <Printer className="h-4 w-4 text-gray-400" />
                  {t('common:print')}
                </button>
              )}

              {onSendEmail && (
                <button
                  type="button"
                  disabled={isSendingEmail}
                  onClick={() => {
                    onSendEmail()
                    setIsDropdownOpen(false)
                  }}
                  className="flex w-full items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                >
                  <Send className="h-4 w-4 text-gray-400" />
                  {t('common:send')}
                </button>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
