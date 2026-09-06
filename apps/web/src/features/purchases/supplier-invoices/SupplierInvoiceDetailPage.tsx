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
  type LucideIcon,
} from 'lucide-react'
import { toast } from 'sonner'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { Button } from '../../../components/atoms/Button/Button'
import { FormField } from '../../../components/atoms/FormField/FormField'
import { Input } from '../../../components/atoms/Input/Input'
import { MoneyInput } from '../../../components/atoms/MoneyInput/MoneyInput'
import { Select } from '../../../components/atoms/Select/Select'
import { Textarea } from '../../../components/atoms/Textarea/Textarea'
import { DataTable, type DataTableColumn } from '../../../components/molecules/DataTable/DataTable'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { getErrorMessage } from '../../../lib/api'
import { bccomp, formatCurrency, formatQuantity } from '../../../lib/decimal'
import type { PerLineMatch, SupplierInvoiceLine, SupplierInvoiceMatchStatus } from './types'
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

const matchIconConfig: Record<SupplierInvoiceMatchStatus, { Icon: LucideIcon; className: string }> = {
  matched: { Icon: CheckCircle2, className: textColors.success },
  price_variance: { Icon: AlertTriangle, className: textColors.warningDark },
  qty_blocked: { Icon: XCircle, className: textColors.error },
  unmatched: { Icon: AlertCircle, className: textColors.disabled },
}

function MatchIcon({ status }: { status: SupplierInvoiceMatchStatus }) {
  const { Icon, className } = matchIconConfig[status]

  return <Icon className={`h-5 w-5 ${className}`} />
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
  // F-W2-14 residual (a), both-layer gating (rule 12): paying a SUPPLIER invoice
  // needs `payments.pay-supplier` on top of `payments.create` — POST /payments
  // enforces exactly that pair on its AP branch (SupplierPaymentAuthorizer), and
  // `payments.create` alone is held by cashier/operator so a till can take a
  // CUSTOMER payment.
  const canCreatePayments = hasPermission('payments.create') && hasPermission('payments.pay-supplier')
  // F-W2-14: Post / Rematch mutate the supplier invoice and are gated on the
  // dedicated supplier-invoices.manage permission (both-layer gating, rule 12).
  const canManageSupplierInvoice = hasPermission('supplier-invoices.manage')
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
  const invoiceLineColumns: DataTableColumn<SupplierInvoiceLine>[] = [
    {
      key: 'quantity',
      header: t('purchases:supplierInvoices.lines.quantity'),
      render: (line) => formatQuantity(line.quantity),
    },
    {
      key: 'unit_price',
      header: t('purchases:supplierInvoices.lines.unitPrice'),
      numeric: true,
      render: (line) => formatCurrency(line.unit_price, true, invoice.currency),
    },
    {
      key: 'vat_rate',
      header: t('purchases:supplierInvoices.lines.vatRate'),
      numeric: true,
      render: (line) => `${line.vat_rate}%`,
      cellClassName: textColors.tertiary,
    },
    {
      key: 'subtotal',
      header: t('purchases:supplierInvoices.lines.subtotal'),
      numeric: true,
      render: (line) => formatCurrency(line.line_subtotal, true, invoice.currency),
      cellClassName: 'font-medium',
    },
  ]
  const matchRowsByPoLine = new Map<string, PerLineMatch>()
  for (const row of invoice.match.per_line) {
    const existingRow = matchRowsByPoLine.get(row.po_line_id)
    matchRowsByPoLine.set(
      row.po_line_id,
      existingRow
        ? { ...existingRow, price_variance: existingRow.price_variance || row.price_variance }
        : row
    )
  }
  const matchRows = [...matchRowsByPoLine.values()]
  const matchColumns: DataTableColumn<PerLineMatch>[] = [
    {
      key: 'ordered',
      header: t('purchases:supplierInvoices.lines.ordered'),
      numeric: true,
      render: (row) => (
        <span data-testid={`match-row-${row.po_line_id}`}>
          {formatQuantity(row.ordered)}
        </span>
      ),
    },
    {
      key: 'received',
      header: t('purchases:supplierInvoices.lines.received'),
      numeric: true,
      render: (row) => formatQuantity(row.received),
    },
    {
      key: 'invoiced',
      header: t('purchases:supplierInvoices.lines.invoiced'),
      numeric: true,
      render: (row) => formatQuantity(row.invoiced),
    },
    {
      key: 'matchable',
      header: t('purchases:supplierInvoices.lines.matchable'),
      numeric: true,
      render: (row) => formatQuantity(row.matchable),
    },
    {
      key: 'price_variance',
      header: t('purchases:supplierInvoices.lines.priceVariance'),
      numeric: true,
      render: (row) =>
        row.price_variance ? (
          <AlertTriangle
            data-testid={`match-variance-${row.po_line_id}`}
            data-variance={String(row.price_variance)}
            className={`inline h-4 w-4 ${textColors.warningDark}`}
          />
        ) : (
          <CheckCircle2
            data-testid={`match-variance-${row.po_line_id}`}
            data-variance={String(row.price_variance)}
            className={`inline h-4 w-4 ${textColors.success}`}
          />
        ),
    },
  ]

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
      // LEDGER C-28(i): the API refusal (e.g. MATCH_NOT_ALLOWED) must reach the
      // user, like handlePost/handleLinkReceipts. Without this the mutation
      // failed silently.
      onError: (err: unknown) => {
        const message =
          (err as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error
            ?.message ?? t('common:errors.unexpected')
        toast.error(message)
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
            {!isPosted && canManageSupplierInvoice && (
              <Button
                type="button"
                data-testid="btn-post"
                disabled={postDisabled || postMutation.isPending}
                onClick={handlePost}
              >
                {t('purchases:supplierInvoices.actions.post')}
              </Button>
            )}

            {/* LEDGER C-28(i): Draft-only, like its sibling Post above. Q-11 made
                re-matching a Draft-only operation on the server (MATCH_NOT_ALLOWED:
                a posted invoice's match_status is the authoritative post-time value
                the GL was booked against), so on a posted invoice this button was a
                live control whose only possible outcome was an error toast. */}
            {!isPosted && canManageSupplierInvoice && (
              <Button
                type="button"
                data-testid="btn-rematch"
                variant="secondary"
                disabled={rematchMutation.isPending}
                onClick={handleRematch}
              >
                {t('purchases:supplierInvoices.actions.rematch')}
              </Button>
            )}

            {isPosted && canCreatePayments && (
              <>
                <Link
                  to={`/treasury/payments/new?supplier_invoice=${invoice.id}`}
                  className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
                >
                  {t('purchases:supplierInvoices.actions.payInTreasury')}
                </Link>
                <Button
                  type="button"
                  data-testid="btn-record-payment"
                  onClick={() => { openPaymentDialog() }}
                >
                  {t('purchases:supplierInvoices.actions.recordPayment')}
                </Button>
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
            <Button
              type="button"
              data-testid="link-receipts"
              disabled={pendingReceiptLinks.length === 0 || linkReceiptsMutation.isPending}
              onClick={handleLinkReceipts}
            >
              <Link2 className="me-2 h-4 w-4" />
              {t('purchases:supplierInvoices.pendingReceipt.linkAction')}
            </Button>
          </div>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {invoice.lines.map((line) => (
              <div key={line.id}>
                <label className={tokens.label.base} htmlFor={`link-receipt-line-id-${line.id}`}>
                  {t('purchases:supplierInvoices.pendingReceipt.receiptLineId', {
                    quantity: formatQuantity(line.quantity),
                  })}
                </label>
                <Select
                  id={`link-receipt-line-id-${line.id}`}
                  data-testid={`link-receipt-line-selector-${line.id}`}
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
                </Select>
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
        <DataTable
          columns={invoiceLineColumns}
          data={invoice.lines}
          keyExtractor={(line) => line.id}
          className="mt-4"
        />
      </div>

      {/* 3-Way Match Table */}
      <div className={tokens.card.base}>
        <div className="flex items-center gap-3">
          <MatchIcon status={invoice.match_status} />
          <h2 className={tokens.heading.section}>
            {t('purchases:supplierInvoices.detail.matchTable')}
          </h2>
        </div>
        <DataTable
          columns={matchColumns}
          data={matchRows}
          keyExtractor={(row) => row.po_line_id}
          className="mt-4"
        />
      </div>

      {/* Attachments */}
      <div className={tokens.card.base}>
        <div className="flex items-center justify-between">
          <h2 className={tokens.heading.section}>
            {t('purchases:supplierInvoices.detail.attachments')}
          </h2>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => { fileInputRef.current?.click() }}
            disabled={uploadMutation.isPending}
          >
            <Upload className="me-1.5 h-4 w-4" />
            {uploadMutation.isPending
              ? t('purchases:supplierInvoices.attachments.uploading')
              : t('purchases:supplierInvoices.actions.uploadAttachment')}
          </Button>
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
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => { handleDownload(att.id, att.filename) }}
                    aria-label={t('purchases:supplierInvoices.actions.downloadAttachment')}
                  >
                    <Download className="h-4 w-4" />
                  </Button>
                  <Button
                    type="button"
                    variant="dangerOutline"
                    size="sm"
                    onClick={() => { handleDeleteAttachment(att.id) }}
                    disabled={deleteMutation.isPending}
                    aria-label={t('purchases:supplierInvoices.actions.deleteAttachment')}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
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
            <Button
              type="button"
              variant="secondary"
              onClick={() => { closePaymentDialog() }}
            >
              {t('common:actions.cancel')}
            </Button>
            <Button
              type="submit"
              disabled={recordPaymentMutation.isPending}
            >
              {t('purchases:supplierInvoices.paymentForm.submit')}
            </Button>
          </div>
        </form>
      </dialog>
    </div>
  )
}
