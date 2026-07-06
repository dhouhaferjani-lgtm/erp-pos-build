import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  FileText,
  Paperclip,
  Upload,
  Download,
  Trash2,
  ArrowLeft,
  ExternalLink,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  AlertCircle,
  Link2,
} from 'lucide-react'
import { toast } from 'sonner'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { FormField, Input, MoneyInput, Select, Textarea } from '../../../components/atoms'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { getErrorMessage } from '../../../lib/api'
import { bccomp, formatCurrency, formatQuantity } from '../../../lib/decimal'
import type { SupplierInvoiceMatchStatus } from './types'
import { useActivePaymentMethods } from '../../treasury/hooks/usePaymentMethods'
import { useActivePaymentRepositories } from '../../treasury/hooks/usePaymentRepositories'
import { usePermissions } from '@/hooks/usePermissions'
import {
  useSupplierInvoiceDetail,
  usePostSupplierInvoice,
  useRematchSupplierInvoice,
  useLinkSupplierInvoiceReceipts,
  useOpenPurchaseOrdersForSupplier,
  usePurchaseOrderReceiptLinesForSupplierInvoice,
  useUploadAttachment,
  useDeleteAttachment,
  useRecordSupplierPayment,
  downloadAttachment,
} from './api'

// ── Match status icon ──────────────────────────────────────────────────────

function MatchIcon({ status }: { status: SupplierInvoiceMatchStatus }) {
  switch (status) {
    case 'matched':
      return <CheckCircle2 className={`h-5 w-5 ${textColors.success}`} />
    case 'price_variance':
      return <AlertTriangle className={`h-5 w-5 ${textColors.warningDark}`} />
    case 'qty_blocked':
      return <XCircle className={`h-5 w-5 ${textColors.error}`} />
    case 'unmatched':
      return <AlertCircle className={`h-5 w-5 ${textColors.disabled}`} />
  }
}

// ── Page ───────────────────────────────────────────────────────────────────

export function SupplierInvoiceDetailPage() {
  const { t } = useTranslation(['common', 'purchases'])
  const { id = '' } = useParams<{ id: string }>()
  const { hasPermission } = usePermissions()

  const { data: invoice, isLoading } = useSupplierInvoiceDetail(id)
  const postMutation = usePostSupplierInvoice(id)
  const rematchMutation = useRematchSupplierInvoice(id)
  const linkReceiptsMutation = useLinkSupplierInvoiceReceipts(id)
  const uploadMutation = useUploadAttachment(id)
  const deleteMutation = useDeleteAttachment(id)
  const recordPaymentMutation = useRecordSupplierPayment(id)
  const paymentMethodsQuery = useActivePaymentMethods()
  const paymentRepositoriesQuery = useActivePaymentRepositories()
  const [receiptLineLinks, setReceiptLineLinks] = useState<Record<string, string>>({})
  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentMethodId, setPaymentMethodId] = useState('')
  const [paymentRepositoryId, setPaymentRepositoryId] = useState('')
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().split('T')[0])
  const [paymentReference, setPaymentReference] = useState('')
  const [paymentNotes, setPaymentNotes] = useState('')
  const [paymentError, setPaymentError] = useState<string | null>(null)
  const canLinkReceipts = hasPermission('supplier-invoices.link-receipts')
  const canCreatePayments = hasPermission('payments.create')
  const openPurchaseOrdersQuery = useOpenPurchaseOrdersForSupplier(invoice?.partner.id ?? '')
  const supplierReceiptLinesQuery = usePurchaseOrderReceiptLinesForSupplierInvoice(
    (openPurchaseOrdersQuery.data ?? []).map((po) => po.id),
    invoice?.pending_receipt === true && canLinkReceipts,
  )

  const fileInputRef = useRef<HTMLInputElement>(null)
  const paymentDialogRef = useRef<HTMLDialogElement>(null)

  function openPaymentDialog() {
    setPaymentError(null)
    if (invoice) {
      setPaymentAmount(invoice.balance_due ?? invoice.total)
      setPaymentDate(new Date().toISOString().split('T')[0])
      setPaymentReference(invoice.number)
      setPaymentNotes('')
    }
    paymentDialogRef.current?.showModal()
  }

  function closePaymentDialog() {
    paymentDialogRef.current?.close()
  }

  if (isLoading || !invoice) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  // ── Post button logic ──────────────────────────────────────────────────

  const isPosted = invoice.status === 'posted' || invoice.status === 'paid'
  const isQtyBlocked = invoice.match_status === 'qty_blocked'
  const hasPendingReceipt = invoice.pending_receipt === true
  const postDisabled = isQtyBlocked || hasPendingReceipt
  const sourcePurchaseOrders = invoice.source_purchase_orders && invoice.source_purchase_orders.length > 0
    ? invoice.source_purchase_orders
    : invoice.source_purchase_order
      ? [invoice.source_purchase_order]
      : []
  const consumedReceipts = invoice.consumed_receipts ?? []
  const pendingReceiptLinks = invoice.lines
    .map((line) => ({
      invoice_line_id: line.id,
      receipt_line_id: (receiptLineLinks[line.id] ?? '').trim(),
    }))
    .filter((link) => link.receipt_line_id !== '')

  function handlePost() {
    postMutation.mutate(undefined, {
      onSuccess: () => {
        toast.success(t('purchases:supplierInvoices.toast.posted'))
      },
      onError: (err: unknown) => {
        const message =
          (err as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error
            ?.message ?? t('common:errors.unexpected')
        toast.error(message)
      },
    })
  }

  function handleRematch() {
    rematchMutation.mutate(undefined, {
      onSuccess: () => {
        toast.success(t('purchases:supplierInvoices.toast.rematched'))
      },
    })
  }

  function handleLinkReceipts() {
    linkReceiptsMutation.mutate(
      { links: pendingReceiptLinks },
      {
        onSuccess: () => {
          toast.success(t('purchases:supplierInvoices.toast.receiptsLinked'))
        },
        onError: (err: unknown) => {
          const message =
            (err as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error
              ?.message ?? t('common:errors.unexpected')
          toast.error(message)
        },
      },
    )
  }

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    uploadMutation.mutate(file, {
      onSuccess: () => {
        toast.success(t('purchases:supplierInvoices.toast.attachmentUploaded'))
        if (fileInputRef.current) fileInputRef.current.value = ''
      },
      onError: () => {
        toast.error(t('common:errors.unexpected'))
      },
    })
  }

  function handleDeleteAttachment(attachmentId: string) {
    deleteMutation.mutate(attachmentId, {
      onSuccess: () => {
        toast.success(t('purchases:supplierInvoices.toast.attachmentDeleted'))
      },
    })
  }

  function handleDownload(attachmentId: string, filename: string) {
    void downloadAttachment(id, attachmentId, filename)
  }

  function handlePaymentSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!invoice) return

    setPaymentError(null)
    const balanceDue = invoice.balance_due ?? invoice.total
    const allocationAmount = bccomp(paymentAmount, balanceDue) > 0 ? balanceDue : paymentAmount
    recordPaymentMutation.mutate(
      {
        amount: paymentAmount,
        currency: invoice.currency,
        payment_method_id: paymentMethodId,
        repository_id: paymentRepositoryId,
        partner_id: invoice.partner.id,
        payment_date: paymentDate,
        reference: paymentReference,
        notes: paymentNotes,
        allocations: [
          {
            document_id: invoice.id,
            amount: allocationAmount,
          },
        ],
      },
      {
        onSuccess: () => {
          toast.success(t('purchases:supplierInvoices.toast.paymentRecorded'))
          closePaymentDialog()
        },
        onError: (error: unknown) => {
          const message =
            (error as { response?: { data?: { error?: { message?: string }; message?: string } } })?.response?.data?.error?.message
            ?? (error as { response?: { data?: { message?: string } } })?.response?.data?.message
            ?? getErrorMessage(error)
          setPaymentError(message)
        },
      },
    )
  }

  return (
    <div className="space-y-6">
      {/* Back navigation */}
      <Link
        to="/purchases/supplier-invoices"
        className={`inline-flex items-center gap-2 text-sm ${textColors.brand} hover:underline`}
      >
        <ArrowLeft className="h-4 w-4" />
        {t('purchases:supplierInvoices.title')}
      </Link>

      {/* Header card */}
      <div className={`${tokens.card.base} space-y-4`}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className={`text-xl font-semibold ${textColors.primary}`}>
              {invoice.number}
            </h1>
            <p className={`mt-1 text-sm ${textColors.tertiary}`}>
              <EntityLink
                type="supplier"
                id={invoice.partner.id}
                label={invoice.partner.name}
              />
            </p>
          </div>

          {/* Actions */}
          <div className="flex items-center gap-2">
            {!isPosted && (
              <button
                type="button"
                data-testid="btn-post"
                disabled={postDisabled || postMutation.isPending}
                onClick={handlePost}
                className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
              >
                {t('purchases:supplierInvoices.actions.post')}
              </button>
            )}

            <button
              type="button"
              disabled={rematchMutation.isPending}
              onClick={handleRematch}
              className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
            >
              {t('purchases:supplierInvoices.actions.rematch')}
            </button>

            {isPosted && canCreatePayments && (
              <>
                <Link
                  to={`/treasury/payments/new?supplier_invoice=${invoice.id}`}
                  className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
                >
                  {t('purchases:supplierInvoices.actions.payInTreasury')}
                </Link>
                <button
                  type="button"
                  data-testid="btn-record-payment"
                  onClick={() => { openPaymentDialog() }}
                  className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
                >
                  {t('purchases:supplierInvoices.actions.recordPayment')}
                </button>
              </>
            )}
          </div>
        </div>

        {/* Post block reason */}
        {!isPosted && isQtyBlocked && (
          <div
            data-testid="post-block-reason"
            className={`${tokens.alert.base} ${tokens.alert.error}`}
          >
            <XCircle className="inline me-2 h-4 w-4" />
            {t('purchases:supplierInvoices.actions.postBlocked')}
          </div>
        )}

        {!isPosted && hasPendingReceipt && (
          <div
            data-testid="pending-receipt-banner"
            className={`${tokens.alert.base} ${tokens.alert.warning}`}
          >
            <AlertTriangle className="inline me-2 h-4 w-4" />
            {t('purchases:supplierInvoices.pendingReceipt.detail')}
          </div>
        )}

        {/* Invoice meta */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div>
            <p className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
              {t('purchases:supplierInvoices.filters.dateFrom')}
            </p>
            <p className={`mt-1 text-sm ${textColors.primary}`}>
              {new Date(invoice.issue_date).toLocaleDateString()}
            </p>
          </div>
          {invoice.due_date && (
            <div>
              <p className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
                {t('purchases:supplierInvoices.filters.dateTo')}
              </p>
              <p className={`mt-1 text-sm ${textColors.primary}`}>
                {new Date(invoice.due_date).toLocaleDateString()}
              </p>
            </div>
          )}
          <div>
            <p className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
              {t('purchases:supplierInvoices.columns.total')}
            </p>
            <p className={`mt-1 text-sm font-semibold ${textColors.primary}`}>
              {formatCurrency(invoice.total, true, invoice.currency)}
            </p>
          </div>
          {invoice.posted_at && (
            <div>
              <p className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
                {t('purchases:supplierInvoices.detail.postedAt')}
              </p>
              <p className={`mt-1 text-sm ${textColors.primary}`}>
                {new Date(invoice.posted_at).toLocaleString()}
              </p>
            </div>
          )}
        </div>

        {/* Linked procurement documents */}
        {(sourcePurchaseOrders.length > 0 || consumedReceipts.length > 0) && (
          <div className={`rounded-md border ${borderColors.light} p-3`}>
            {sourcePurchaseOrders.length > 0 && (
              <div>
                <p className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.detail.linkedPOs')}
                </p>
                <div className="mt-1 flex flex-wrap gap-2">
                  {sourcePurchaseOrders.map((purchaseOrder) => (
                    <EntityLink
                      key={purchaseOrder.id}
                      data-testid="link-source-po"
                      type="document"
                      id={purchaseOrder.id}
                      documentType="purchase_order"
                      label={(
                        <span className="inline-flex items-center gap-1">
                          <ExternalLink className="h-3.5 w-3.5" />
                          {t('purchases:supplierInvoices.detail.poNumber', {
                            number: purchaseOrder.number,
                          })}
                        </span>
                      )}
                      className="inline-flex items-center gap-1 text-sm font-medium"
                    />
                  ))}
                </div>
              </div>
            )}
            {consumedReceipts.length > 0 && (
              <div className={sourcePurchaseOrders.length > 0 ? 'mt-3' : undefined}>
                <p className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.detail.consumedReceipts')}
                </p>
                <div className="mt-1 flex flex-wrap gap-2">
                  {consumedReceipts.map((receipt) => (
                    <EntityLink
                      key={receipt.id}
                      type="goodsReceipt"
                      id={receipt.id}
                      purchaseOrderId={sourcePurchaseOrders[0]?.id ?? invoice.source_document_id}
                      label={receipt.receipt_number ?? receipt.id}
                      className="inline-flex items-center gap-1 text-sm font-medium"
                    />
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
      </div>

      {hasPendingReceipt && !isPosted && canLinkReceipts ? (
        <div className={`${tokens.card.base} space-y-4`}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className={tokens.heading.section}>
                {t('purchases:supplierInvoices.pendingReceipt.linkTitle')}
              </h2>
              <p className={`mt-1 text-sm ${textColors.tertiary}`}>
                {t('purchases:supplierInvoices.pendingReceipt.linkDescription')}
              </p>
            </div>
            <button
              type="button"
              data-testid="link-receipts"
              disabled={pendingReceiptLinks.length === 0 || linkReceiptsMutation.isPending}
              onClick={handleLinkReceipts}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
            >
              <Link2 className="me-2 h-4 w-4" />
              {t('purchases:supplierInvoices.pendingReceipt.linkAction')}
            </button>
          </div>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {invoice.lines.map((line) => (
              <div key={line.id}>
                <label className={tokens.label.base} htmlFor={`link-receipt-line-id-${line.id}`}>
                  {t('purchases:supplierInvoices.pendingReceipt.receiptLineId', {
                    quantity: formatQuantity(line.quantity),
                  })}
                </label>
                <select
                  id={`link-receipt-line-id-${line.id}`}
                  data-testid={`link-receipt-line-selector-${line.id}`}
                  className={tokens.select.base}
                  value={receiptLineLinks[line.id] ?? ''}
                  onChange={(event) => {
                    setReceiptLineLinks((current) => ({
                      ...current,
                      [line.id]: event.target.value,
                    }))
                  }}
                >
                  <option value="">{t('purchases:supplierInvoices.pendingReceipt.selectReceiptLine')}</option>
                  {(supplierReceiptLinesQuery.data ?? [])
                    .filter((receiptLine) => (
                      (line.product_id === undefined || line.product_id === null || receiptLine.product_id === line.product_id)
                      && (line.variant_id === undefined || line.variant_id === receiptLine.variant_id)
                    ))
                    .map((receiptLine) => (
                      <option key={receiptLine.id} value={receiptLine.id}>
                        {receiptLine.receipt_number} · {formatQuantity(receiptLine.received_qty)}
                      </option>
                    ))}
                </select>
              </div>
            ))}
          </div>
        </div>
      ) : null}

      {/* Invoice Lines */}
      <div className={tokens.card.base}>
        <h2 className={tokens.heading.section}>
          {t('purchases:supplierInvoices.detail.lines')}
        </h2>
        <div className="mt-4 overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className={tokens.table.header}>
              <tr>
                <th className={`px-4 py-3 text-start text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.quantity')}
                </th>
                <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.unitPrice')}
                </th>
                <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.vatRate')}
                </th>
                <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.subtotal')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {invoice.lines.map((line) => (
                <tr key={line.id} className={tokens.table.rowHover}>
                  <td className={`px-4 py-3 text-sm ${textColors.primary}`}>
                    {formatQuantity(line.quantity)}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm ${textColors.primary}`}>
                    {formatCurrency(line.unit_price, true, invoice.currency)}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm ${textColors.tertiary}`}>
                    {line.vat_rate}%
                  </td>
                  <td className={`px-4 py-3 text-end text-sm font-medium ${textColors.primary}`}>
                    {formatCurrency(line.line_subtotal, true, invoice.currency)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* 3-Way Match Table */}
      <div className={tokens.card.base}>
        <div className="flex items-center gap-3">
          <MatchIcon status={invoice.match_status} />
          <h2 className={tokens.heading.section}>
            {t('purchases:supplierInvoices.detail.matchTable')}
          </h2>
        </div>
        <div className="mt-4 overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className={tokens.table.header}>
              <tr>
                <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.ordered')}
                </th>
                <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.received')}
                </th>
                <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.invoiced')}
                </th>
                <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.matchable')}
                </th>
                <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                  {t('purchases:supplierInvoices.lines.priceVariance')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {invoice.match.per_line.map((row) => (
                <tr key={row.po_line_id} data-testid={`match-row-${row.po_line_id}`} className={tokens.table.rowHover}>
                  <td className={`px-4 py-3 text-end text-sm ${textColors.primary}`}>
                    {formatQuantity(row.ordered)}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm ${textColors.primary}`}>
                    {formatQuantity(row.received)}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm ${textColors.primary}`}>
                    {formatQuantity(row.invoiced)}
                  </td>
                  <td className={`px-4 py-3 text-end text-sm ${textColors.primary}`}>
                    {formatQuantity(row.matchable)}
                  </td>
                  <td
                    className={`px-4 py-3 text-end text-sm ${
                      row.price_variance ? `font-medium ${textColors.warning}` : textColors.primary
                    }`}
                  >
                    {row.price_variance
                      ? <AlertTriangle className={`inline h-4 w-4 ${textColors.warningDark}`} />
                      : <CheckCircle2 className={`inline h-4 w-4 ${textColors.success}`} />
                    }
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Attachments */}
      <div className={tokens.card.base}>
        <div className="flex items-center justify-between">
          <h2 className={tokens.heading.section}>
            {t('purchases:supplierInvoices.detail.attachments')}
          </h2>
          <button
            type="button"
            onClick={() => { fileInputRef.current?.click() }}
            disabled={uploadMutation.isPending}
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
          >
            <Upload className="me-1.5 h-4 w-4" />
            {uploadMutation.isPending
              ? t('purchases:supplierInvoices.attachments.uploading')
              : t('purchases:supplierInvoices.actions.uploadAttachment')}
          </button>
          <input
            ref={fileInputRef}
            type="file"
            className="sr-only"
            accept="application/pdf,image/*"
            aria-label={t('purchases:supplierInvoices.actions.uploadAttachment')}
            onChange={handleFileChange}
          />
        </div>

        {invoice.attachments.length === 0 ? (
          <div className="mt-4 flex flex-col items-center py-8 text-center">
            <Paperclip className={`h-10 w-10 ${textColors.disabled}`} />
            <p className={`mt-2 text-sm ${textColors.tertiary}`}>
              {t('purchases:supplierInvoices.attachments.empty')}
            </p>
            <p className={`mt-1 text-xs ${textColors.disabled}`}>
              {t('purchases:supplierInvoices.attachments.uploadHint')}
            </p>
          </div>
        ) : (
          <ul className={`mt-4 divide-y ${borderColors.divideDefault}`}>
            {invoice.attachments.map((att) => (
              <li key={att.id} className="flex items-center justify-between py-3">
                <div className="flex items-center gap-3">
                  <FileText className={`h-5 w-5 ${textColors.disabled}`} />
                  <div>
                    <p className={`text-sm font-medium ${textColors.primary}`}>{att.filename}</p>
                    <p className={`text-xs ${textColors.tertiary}`}>
                      {(att.size / 1024).toFixed(1)} KB
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => { handleDownload(att.id, att.filename) }}
                    className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm}`}
                    aria-label={t('purchases:supplierInvoices.actions.downloadAttachment')}
                  >
                    <Download className="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    onClick={() => { handleDeleteAttachment(att.id) }}
                    disabled={deleteMutation.isPending}
                    className={`${tokens.button.base} ${tokens.button.dangerOutline} ${tokens.button.sizes.sm}`}
                    aria-label={t('purchases:supplierInvoices.actions.deleteAttachment')}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Record Payment Modal (minimal inline form — gated on C4 ship) */}
      {/* Native <dialog> for built-in focus trapping + Escape-to-close */}
      <dialog
        ref={paymentDialogRef}
        aria-labelledby="payment-modal-title"
        className={tokens.modal.container}
        onClose={() => { closePaymentDialog() }}
      >
        <div className={tokens.modal.header}>
          <h2 id="payment-modal-title" className={tokens.modal.title}>
            {t('purchases:supplierInvoices.detail.payment')}
          </h2>
          <button
            type="button"
            onClick={() => { closePaymentDialog() }}
            className={tokens.modal.closeButton}
            aria-label={t('common:actions.close')}
          >
            ×
          </button>
        </div>
        <form className="space-y-4" onSubmit={handlePaymentSubmit}>
          <FormField
            label={t('purchases:supplierInvoices.paymentForm.amount')}
            htmlFor="supplier-payment-amount"
            required
          >
            <MoneyInput
              id="supplier-payment-amount"
              currency={invoice.currency}
              value={paymentAmount}
              onChange={setPaymentAmount}
              aria-label={t('purchases:supplierInvoices.paymentForm.amount')}
              className={tokens.input.base}
            />
          </FormField>

          <div className="grid gap-4 sm:grid-cols-2">
            <FormField
              label={t('purchases:supplierInvoices.paymentForm.method')}
              htmlFor="supplier-payment-method"
              required
            >
              <Select
                id="supplier-payment-method"
                aria-label={t('purchases:supplierInvoices.paymentForm.method')}
                value={paymentMethodId}
                onChange={(event) => { setPaymentMethodId(event.target.value) }}
                required
              >
                <option value="">{t('purchases:supplierInvoices.paymentForm.selectMethod')}</option>
                {paymentMethodsQuery.data.map((method) => (
                  <option key={method.id} value={method.id}>{method.name}</option>
                ))}
              </Select>
            </FormField>

            <FormField
              label={t('purchases:supplierInvoices.paymentForm.repository')}
              htmlFor="supplier-payment-repository"
              required
            >
              <Select
                id="supplier-payment-repository"
                aria-label={t('purchases:supplierInvoices.paymentForm.repository')}
                value={paymentRepositoryId}
                onChange={(event) => { setPaymentRepositoryId(event.target.value) }}
                required
              >
                <option value="">{t('purchases:supplierInvoices.paymentForm.selectRepository')}</option>
                {paymentRepositoriesQuery.data.map((repository) => (
                  <option key={repository.id} value={repository.id}>{repository.name}</option>
                ))}
              </Select>
            </FormField>
          </div>

          <FormField
            label={t('purchases:supplierInvoices.paymentForm.date')}
            htmlFor="supplier-payment-date"
            required
          >
            <Input
              id="supplier-payment-date"
              type="date"
              aria-label={t('purchases:supplierInvoices.paymentForm.date')}
              value={paymentDate}
              onChange={(event) => { setPaymentDate(event.target.value) }}
              required
            />
          </FormField>

          <FormField
            label={t('purchases:supplierInvoices.paymentForm.reference')}
            htmlFor="supplier-payment-reference"
          >
            <Input
              id="supplier-payment-reference"
              aria-label={t('purchases:supplierInvoices.paymentForm.reference')}
              value={paymentReference}
              onChange={(event) => { setPaymentReference(event.target.value) }}
            />
          </FormField>

          <FormField
            label={t('purchases:supplierInvoices.paymentForm.notes')}
            htmlFor="supplier-payment-notes"
          >
            <Textarea
              id="supplier-payment-notes"
              rows={3}
              aria-label={t('purchases:supplierInvoices.paymentForm.notes')}
              value={paymentNotes}
              onChange={(event) => { setPaymentNotes(event.target.value) }}
            />
          </FormField>

          {paymentError && (
            <div role="alert" className={`${tokens.alert.base} ${tokens.alert.error}`}>
              {paymentError}
            </div>
          )}

          <div className={tokens.modal.footer}>
            <button
              type="button"
              onClick={() => { closePaymentDialog() }}
              className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="submit"
              disabled={recordPaymentMutation.isPending}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
            >
              {t('purchases:supplierInvoices.paymentForm.submit')}
            </button>
          </div>
        </form>
      </dialog>
    </div>
  )
}
