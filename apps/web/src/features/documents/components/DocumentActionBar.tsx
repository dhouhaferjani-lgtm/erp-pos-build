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
  ReceiptText,
  RotateCcw,
  type LucideIcon,
} from 'lucide-react'
import { Button } from '../../../components/atoms/Button/Button'
import { usePermissions } from '../../../hooks/usePermissions'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'

export interface DocumentActionBarProps {
  document: Document
  basePath: string
  isActionPending: boolean

  onConfirm?: (() => void) | undefined
  onPost?: (() => void) | undefined
  onConvert?: (() => void) | undefined
  onConvertToDelivery?: (() => void) | undefined
  onReceiveGoods?: (() => void) | undefined
  onCreateSupplierInvoice?: (() => void) | undefined
  onRecordPayment?: (() => void) | undefined
  onCreateCreditNote?: (() => void) | undefined
  onCreateReturnNote?: (() => void) | undefined
  onRevert?: (() => void) | undefined
  onSendEmail?: (() => void) | undefined
  onDownloadPdf?: (() => void) | undefined
  onPreviewPdf?: (() => void) | undefined
  onPrintPdf?: (() => void) | undefined

  isDownloading?: boolean | undefined
  isPreviewing?: boolean | undefined
  isPrinting?: boolean | undefined
  isSendingEmail?: boolean | undefined
  canCreateSupplierInvoice?: boolean | undefined
}

interface VisibleAction {
  key: string
  icon: LucideIcon
  label: string
  onClick: () => void
  disabled: boolean
  title?: string | undefined
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
  onCreateSupplierInvoice,
  onRecordPayment,
  onCreateCreditNote,
  onCreateReturnNote,
  onRevert,
  onSendEmail,
  onDownloadPdf,
  onPreviewPdf,
  onPrintPdf,
  isDownloading = false,
  isPreviewing = false,
  isPrinting = false,
  isSendingEmail = false,
  canCreateSupplierInvoice: hasUninvoicedReceiptLines = false,
}: DocumentActionBarProps) {
  const { t } = useTranslation(['sales', 'common', 'purchases'])
  const { hasPermission } = usePermissions()
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
    return undefined
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

  const canShowCreateSupplierInvoice =
    document.type === 'purchase_order' &&
    ['confirmed', 'received'].includes(document.status) &&
    onCreateSupplierInvoice !== undefined

  const canRecordPayment =
    (document.type === 'invoice' && ['confirmed', 'posted'].includes(document.status) && document.payment_status !== 'paid') ||
    (document.type === 'purchase_order' && ['confirmed', 'received'].includes(document.status) && document.payment_status !== 'paid') ||
    (document.type === 'sales_order' && document.status === 'confirmed' && document.payment_status !== 'paid')

  const canCreateCreditNote = document.type === 'invoice' && document.status === 'posted'
  const canCreateReturnNote =
    (document.type === 'invoice' && document.status === 'posted') ||
    (document.type === 'delivery_note' && document.status === 'confirmed')
  const canRevert =
    document.status === 'confirmed' &&
    ['quote', 'sales_order', 'purchase_order'].includes(document.type) &&
    hasPermission('documents.update')

  // Build the visible action list in workflow order. The FIRST action is the
  // single primary (filled) button; every other action renders as secondary.
  // One primary per screen — the rainbow of per-action colors is deliberate slop removal.
  const visibleActions: VisibleAction[] = []

  if (canConfirm && onConfirm) {
    visibleActions.push({
      key: 'confirm',
      icon: Check,
      label: t('common:actions.confirm'),
      onClick: onConfirm,
      disabled: isActionPending,
    })
  }

  if (canPost && onPost) {
    visibleActions.push({
      key: 'post',
      icon: Lock,
      label: t('documents.post'),
      onClick: onPost,
      disabled: isActionPending,
    })
  }

  if (canConvert && onConvert) {
    visibleActions.push({
      key: 'convert',
      icon: ArrowRight,
      label: t('quotes.convertToOrder'),
      onClick: onConvert,
      disabled: isActionPending,
    })
  }

  if (canConvertToDelivery && onConvertToDelivery) {
    visibleActions.push({
      key: 'convertToDelivery',
      icon: Truck,
      label: t('orders.convertToDelivery'),
      onClick: onConvertToDelivery,
      disabled: isActionPending,
    })
  }

  if (canConvertToInvoice && onConvert) {
    visibleActions.push({
      key: 'convertToInvoice',
      icon: ArrowRight,
      label: t('orders.convertToInvoice'),
      onClick: onConvert,
      disabled: isActionPending,
    })
  }

  if (canReceiveGoods && onReceiveGoods) {
    visibleActions.push({
      key: 'receiveGoods',
      icon: Package,
      label: t('purchaseOrders.receiveGoods'),
      onClick: onReceiveGoods,
      disabled: isActionPending,
    })
  }

  if (canShowCreateSupplierInvoice && onCreateSupplierInvoice) {
    visibleActions.push({
      key: 'createSupplierInvoice',
      icon: ReceiptText,
      label: t('purchases:supplierInvoices.actions.createFromPurchaseOrder'),
      onClick: onCreateSupplierInvoice,
      disabled: isActionPending || !hasUninvoicedReceiptLines,
      title: !hasUninvoicedReceiptLines
        ? t('purchases:supplierInvoices.create.noReceiptLines')
        : undefined,
    })
  }

  if (canRecordPayment && onRecordPayment) {
    visibleActions.push({
      key: 'recordPayment',
      icon: CreditCard,
      label: t('documents.recordPayment'),
      onClick: onRecordPayment,
      disabled: false,
    })
  }

  if (canCreateCreditNote && onCreateCreditNote) {
    visibleActions.push({
      key: 'createCreditNote',
      icon: MinusCircle,
      label: t('documents.createCreditNote'),
      onClick: onCreateCreditNote,
      disabled: false,
    })
  }

  if (canCreateReturnNote && onCreateReturnNote) {
    visibleActions.push({
      key: 'createReturnNote',
      icon: Package,
      label:
        document.type === 'invoice'
          ? t('returnNotes.createFromInvoice', 'Create Return Note')
          : t('returnNotes.createFromDelivery', 'Create Return Note'),
      onClick: onCreateReturnNote,
      disabled: false,
    })
  }

  // Check if there are any dropdown items
  const hasDropdownItems = canEdit || onDownloadPdf || onPreviewPdf || onPrintPdf || onSendEmail

  return (
    <div className="flex items-center gap-2">
      {/* WORKFLOW ACTIONS — first is the single primary, the rest secondary */}
      {visibleActions.map((action, index) => {
        const Icon = action.icon
        return (
          <Button
            key={action.key}
            type="button"
            variant={index === 0 ? 'primary' : 'secondary'}
            disabled={action.disabled}
            onClick={action.onClick}
            title={action.title}
            className="gap-2"
          >
            <Icon className="h-4 w-4" />
            {action.label}
          </Button>
        )
      })}

      {canRevert && onRevert && (
        <Button
          type="button"
          variant="secondary"
          disabled={isActionPending}
          onClick={onRevert}
          className="gap-2"
        >
          <RotateCcw className="h-4 w-4" />
          {t('documents.revertToDraft')}
        </Button>
      )}

      {/* MORE DROPDOWN - secondary actions */}
      {hasDropdownItems && (
        <div className="relative" ref={dropdownRef}>
          <button
            type="button"
            onClick={() => { setIsDropdownOpen(!isDropdownOpen); }}
            className={`inline-flex items-center justify-center rounded-md border ${colorClasses.borderGray300} bg-white p-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} transition-colors`}
            aria-label={t('common:actions.more', 'More actions')}
          >
            <MoreVertical className="h-5 w-5" />
          </button>

          {isDropdownOpen && (
            <div className={`absolute right-0 z-20 mt-2 w-48 origin-top-right rounded-md border ${colorClasses.borderGray200} bg-white py-1 shadow-lg`}>
              {canEdit && (
                <Link
                  to={`${basePath}/${document.id}/edit`}
                  className={`flex items-center gap-3 px-4 py-2 text-sm ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`}
                  onClick={() => { setIsDropdownOpen(false); }}
                >
                  <Edit className={`h-4 w-4 ${colorClasses.textGray400}`} />
                  {t('actions.edit')}
                </Link>
              )}

              {canEdit && (onDownloadPdf || onPreviewPdf || onPrintPdf || onSendEmail) && (
                <div className={`my-1 border-t ${colorClasses.borderGray100}`} />
              )}

              {onPreviewPdf && (
                <button
                  type="button"
                  disabled={isPreviewing}
                  onClick={() => {
                    onPreviewPdf()
                    setIsDropdownOpen(false)
                  }}
                  className={`flex w-full items-center gap-3 px-4 py-2 text-sm ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50`}
                >
                  <Eye className={`h-4 w-4 ${colorClasses.textGray400}`} />
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
                  className={`flex w-full items-center gap-3 px-4 py-2 text-sm ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50`}
                >
                  <Download className={`h-4 w-4 ${colorClasses.textGray400}`} />
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
                  className={`flex w-full items-center gap-3 px-4 py-2 text-sm ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50`}
                >
                  <Printer className={`h-4 w-4 ${colorClasses.textGray400}`} />
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
                  className={`flex w-full items-center gap-3 px-4 py-2 text-sm ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:opacity-50`}
                >
                  <Send className={`h-4 w-4 ${colorClasses.textGray400}`} />
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
