import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Calendar, Building2, FileText, Package, TrendingUp } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { formatCurrency } from '../../../lib/format'
import { formatQuantity } from '../../../lib/decimal'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { bccomp } from '../../../lib/decimal'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentAttachments } from '../components/DocumentAttachments'
import { PurchaseOrderLandedCostBreakdown } from '../components/PurchaseOrderLandedCostBreakdown'
import { DocumentHeader } from '../components/DocumentHeader'
import { DocumentOutstandingCallout } from '../components/DocumentOutstandingCallout'
import { OutstandingAmountSection } from '../components/OutstandingAmountSection'
import { PaymentHistorySection } from '../components/PaymentHistorySection'
import { isPaymentStatus, paymentStatusFallbackLabel, paymentStatusIcon, paymentStatusTone } from '../components/paymentStatus'
import { useSendDocumentEmail } from '../hooks/useDocumentEmail'
import { useDownloadPdf, usePreviewPdf, usePrintPdf } from '../hooks/useDocumentPdf'
import { useRevertDocument } from '../hooks/useRevertDocument'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { RecordPaymentModal } from '../../../components/organisms/RecordPaymentModal'
import { Modal } from '../../../components/organisms/Modal/Modal'
import { Button } from '../../../components/atoms/Button/Button'
import { Input } from '../../../components/atoms/Input/Input'
import { StatusBadge, type StatusTone } from '../../../components/atoms/StatusBadge/StatusBadge'
import { Textarea } from '../../../components/atoms/Textarea/Textarea'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { ProductCell } from '../../../components/molecules/line-items/ProductCell'
import { tokens } from '../../../lib/designTokens'
import { entityRoutes } from '../../../lib/entityRoutes'
import { useCompany } from '../../../hooks/useCompany'
import { usePermissions } from '../../../hooks/usePermissions'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { ReceiveGoodsDialog, type ReceiveGoodsRequest } from '@/features/purchases/components/ReceiveGoodsDialog'
import { usePurchaseOrderReceiptLines } from '@/features/purchases/supplier-invoices/api'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type ConfirmAction = 'confirm' | 'revert' | null
type ActiveTab = 'related' | 'attachments' | 'landedCosts' | 'payments'
type ReceiptStatusValue = 'not_received' | 'partially_received' | 'fully_received'

interface ReceiptStatusLine {
  line_id: string
  product_name: string
  quantity_ordered: string
  quantity_received: string
  quantity_remaining: string
  is_complete: boolean
}

interface ReceiptStatusResponse {
  status: ReceiptStatusValue
  total_ordered: string
  total_received: string
  percentage: number
  lines: ReceiptStatusLine[]
}

interface ReceiveGoodsResponse {
  data: Document
  meta?: {
    goods_receipt?: {
      receipt_number?: string | null
    } | null
  }
}

const receiptStatusTones: Record<ReceiptStatusValue, StatusTone> = {
  not_received: 'neutral',
  partially_received: 'warning',
  fully_received: 'success',
}

function fallbackReceiptStatus(documentStatus: string): ReceiptStatusValue {
  return documentStatus === 'received' ? 'fully_received' : 'not_received'
}

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function PurchaseOrderDetailPage() {
  const { t } = useTranslation(['sales', 'common', 'inventory', 'purchases'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { hasPermission } = usePermissions()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [showReceiveDialog, setShowReceiveDialog] = useState(false)
  const [showPaymentModal, setShowPaymentModal] = useState(false)
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [activeTab, setActiveTab] = useState<ActiveTab>('related')
  const [emailForm, setEmailForm] = useState({
    recipientEmail: '',
    subject: '',
    message: '',
    ccEmails: '',
  })

  // Fetch purchase order
  const { data: purchaseOrder, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['document', 'purchase_order', id]),
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/purchase-orders/${id}`)
      return response.data.data
    },
    enabled: id.length > 0 && tenantId !== null && companyId !== null,
  })

  const { data: receiptStatusData } = useQuery({
    queryKey: tenantScopedKey(['purchase-order', 'receipt-status', id]),
    queryFn: async () => {
      const response = await api.get<{ data: ReceiptStatusResponse }>(`/purchase-orders/${id}/receipt-status`)
      return response.data.data
    },
    enabled: id.length > 0 && tenantId !== null && companyId !== null,
  })

  const { data: uninvoicedReceiptLines } = usePurchaseOrderReceiptLines(id, id.length > 0)
  const canCreateSupplierInvoice = hasPermission('purchases.create') && (uninvoicedReceiptLines?.length ?? 0) > 0

  // PDF mutations
  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()
  const sendEmailMutation = useSendDocumentEmail()
  const revertMutation = useRevertDocument(id, 'purchase_order')

  // Confirm PO mutation
  const confirmMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/purchase-orders/${id}/confirm`, {}),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
      ])
      toast.success(t('documents.messages.confirmed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Receive goods mutation
  const receiveGoodsMutation = useMutation({
    mutationFn: async (request: ReceiveGoodsRequest) => {
      const response = await api.post<ReceiveGoodsResponse>(`/purchase-orders/${id}/receive`, request)
      return response.data
    },
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', id] }),
        queryClient.invalidateQueries({ queryKey: ['purchase-order', 'receipt-status', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('stock-levels', tenantId, companyId),
        }),
      ])
      setShowReceiveDialog(false)
      const receiptNumber = response.meta?.goods_receipt?.receipt_number
      toast.success(receiptNumber
        ? t('documents.messages.goodsReceivedWithReceipt', { receiptNumber })
        : t('documents.messages.goodsReceived'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const isActionPending = confirmMutation.isPending || receiveGoodsMutation.isPending || revertMutation.isPending

  // Action handlers
  const handleConfirm = () => {
    confirmMutation.mutate()
    setConfirmAction(null)
  }

  const handleReceiveGoods = (request: ReceiveGoodsRequest) => {
    receiveGoodsMutation.mutate(request)
  }

  const handleRevert = () => {
    revertMutation.mutate(undefined, {
      onSuccess: () => {
        toast.success(t('documents.messages.revertedToDraft'))
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
    setConfirmAction(null)
  }

  const handleDownloadPdf = () => {
    if (!purchaseOrder) return
    downloadPdfMutation.mutate(purchaseOrder.id)
  }

  const handlePreviewPdf = () => {
    if (!purchaseOrder) return
    previewPdfMutation.mutate(purchaseOrder.id)
  }

  const handlePrintPdf = () => {
    if (!purchaseOrder) return
    printPdfMutation.mutate(purchaseOrder.id)
  }

  const handleSendEmail = () => {
    if (!purchaseOrder) return

    const { recipientEmail, subject, message, ccEmails } = emailForm

    sendEmailMutation.mutate({
      documentId: purchaseOrder.id,
      recipientEmail,
      subject,
      message,
      ccEmails: ccEmails.split(',').map(e => e.trim()).filter(e => e),
    }, {
      onSuccess: () => {
        setShowEmailModal(false)
        setEmailForm({ recipientEmail: '', subject: '', message: '', ccEmails: '' })
        toast.success(t('common.emailSent'))
      },
    })
  }

  const handlePaymentSuccess = async () => {
    setShowPaymentModal(false)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', id] }),
      queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('documents', tenantId, companyId),
      }),
      queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('payments', tenantId, companyId),
      }),
    ])
  }

  const receiptLineById = useMemo(
    () => new Map((receiptStatusData?.lines ?? []).map((line) => [line.line_id, line])),
    [receiptStatusData?.lines],
  )
  const purchaseOrderWithReceiptQuantities = useMemo(() => {
    if (!purchaseOrder) {
      return undefined
    }

    if (purchaseOrder.lines === undefined) {
      return purchaseOrder
    }

    return {
      ...purchaseOrder,
      lines: purchaseOrder.lines.map((line) => {
        const quantityReceived = receiptLineById.get(line.id)?.quantity_received

        return quantityReceived === undefined ? line : {
          ...line,
          quantity_received: quantityReceived,
        }
      }),
    }
  }, [purchaseOrder, receiptLineById])

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-center">
          <div className={`animate-spin rounded-full h-12 w-12 border-b-2 ${colorClasses.borderBlue600} mx-auto`}></div>
          <p className={`mt-4 ${colorClasses.textGray600}`}>{t('common:loading')}</p>
        </div>
      </div>
    )
  }

  if (error || !purchaseOrder) {
    return (
      <div className="py-6">
        <div className={`${colorClasses.bgRed50} border ${colorClasses.borderRed200} rounded-lg p-4`}>
          <p className={`${colorClasses.textRed800}`}>{t('common.errorLoadingData')}</p>
        </div>
      </div>
    )
  }

  const receiptStatus = receiptStatusData?.status ?? fallbackReceiptStatus(purchaseOrder.status)
  const canShowReceiptStatus = ['confirmed', 'received', 'partially_received'].includes(purchaseOrder.status)

  // Payment computation
  const outstandingAmount = parseFloat(purchaseOrder.outstanding_amount || purchaseOrder.balance_due || '0')
  const total = parseFloat(purchaseOrder.total || '0')
  const amountPaid = parseFloat(purchaseOrder.amount_paid || '0')
  const isPaid = purchaseOrder.payment_status === 'paid' || outstandingAmount === 0
  const canRecordPayment = ['confirmed', 'received'].includes(purchaseOrder.status) && !isPaid && outstandingAmount > 0
  const creditNotesApplied = Math.max(0, total - outstandingAmount - amountPaid)
  const paymentStatus = isPaymentStatus(purchaseOrder.payment_status) ? purchaseOrder.payment_status : null
  const PaymentStatusIcon = paymentStatus === null ? null : paymentStatusIcon(paymentStatus)

  return (
    <div className="py-6">
      {/* Header */}
      <div className="mb-6">
        <DocumentHeader
          document={purchaseOrder}
          backPath="/purchases/orders"
          actions={
            <DocumentActionBar
              document={purchaseOrder}
              basePath="/purchases/orders"
              isActionPending={isActionPending}
              onConfirm={() => { setConfirmAction('confirm'); }}
              onReceiveGoods={() => { setShowReceiveDialog(true); }}
              onRevert={() => { setConfirmAction('revert'); }}
              onCreateSupplierInvoice={hasPermission('purchases.create') ? () => { void navigate(`/purchases/supplier-invoices/new?po=${id}`) } : undefined}
              canCreateSupplierInvoice={canCreateSupplierInvoice}
              onRecordPayment={canRecordPayment ? () => { setShowPaymentModal(true); } : undefined}
              onDownloadPdf={handleDownloadPdf}
              onPreviewPdf={handlePreviewPdf}
              onPrintPdf={handlePrintPdf}
              onSendEmail={() => { setShowEmailModal(true); }}
              isDownloading={downloadPdfMutation.isPending}
              isPreviewing={previewPdfMutation.isPending}
              isPrinting={printPdfMutation.isPending}
            />
          }
          financialCallout={
            ['confirmed', 'received'].includes(purchaseOrder.status) && !isPaid && outstandingAmount > 0 ? (
              <DocumentOutstandingCallout
                amount={outstandingAmount}
                currency={currentCompany?.currency ?? 'EUR'}
                {...(canRecordPayment ? { onRecordPayment: () => { setShowPaymentModal(true); } } : {})}
              />
            ) : undefined
          }
        >
          {canShowReceiptStatus && (
            <StatusBadge tone={receiptStatusTones[receiptStatus]} className="gap-1.5">
              <Package className="h-3 w-3" />
              {t(`purchaseOrders.receiptStatus.${receiptStatus}`)}
            </StatusBadge>
          )}
          {['confirmed', 'received'].includes(purchaseOrder.status) && paymentStatus !== null && PaymentStatusIcon !== null && (
            <StatusBadge tone={paymentStatusTone(paymentStatus)} className="gap-1.5">
              <PaymentStatusIcon className="h-3 w-3" />
              {t(`sales:invoices.paymentStatus.${paymentStatus}`, {
                defaultValue: paymentStatusFallbackLabel(paymentStatus),
              })}
            </StatusBadge>
          )}
        </DocumentHeader>
      </div>

      {/* Main Content */}
      <div className="bg-white shadow overflow-hidden sm:rounded-lg">
        {/* Details Section */}
        <div className="px-4 py-5 sm:px-6">
          <div className="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-3">
            <div>
              <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                <Calendar className="h-4 w-4" />
                {t('documents.documentDate')}
              </dt>
              <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                {new Date(purchaseOrder.document_date).toLocaleDateString()}
              </dd>
            </div>

            {purchaseOrder.due_date && (
              <div>
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <Calendar className="h-4 w-4" />
                  {t('purchaseOrders.expectedDate')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                  {new Date(purchaseOrder.due_date).toLocaleDateString()}
                </dd>
              </div>
            )}

            <div>
              <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                <Building2 className="h-4 w-4" />
                {t('documents.supplier')}
              </dt>
              <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                <EntityLink
                  type="partner"
                  id={purchaseOrder.partner_id}
                  partnerType="supplier"
                  label={purchaseOrder.partner_name || '-'}
                />
              </dd>
            </div>

            {purchaseOrder.notes && (
              <div className="sm:col-span-3">
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <FileText className="h-4 w-4" />
                  {t('documents.notes')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900} whitespace-pre-wrap`}>
                  {purchaseOrder.notes}
                </dd>
              </div>
            )}
          </div>
        </div>

        {/* Lines Table */}
        <div className={`border-t ${colorClasses.borderGray200}`}>
          <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
            <thead className={`${colorClasses.bgGray50}`}>
              <tr>
                <th className={`px-6 py-3 text-left text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                  {t('documents.description')}
                </th>
                <th className={`px-6 py-3 text-right text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                  {t('documents.quantity')}
                </th>
                {canShowReceiptStatus && (
                  <th className={`px-6 py-3 text-right text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                    {t('purchaseOrders.received')}
                  </th>
                )}
                <th className={`px-6 py-3 text-right text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                  {t('documents.unitPrice')}
                </th>
                <th className={`px-6 py-3 text-right text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                  {t('documents.total')}
                </th>
              </tr>
            </thead>
            <tbody className={`bg-white divide-y ${colorClasses.divideGray200}`}>
              {purchaseOrder.lines?.map((line) => (
                <tr key={line.id}>
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                    {line.product_id ? (
                      <Link to={entityRoutes.product(line.product_id)} className="block hover:underline">
                        <ProductCell
                          product={{
                            name: line.product_name || line.description,
                            sku: line.product_code ?? null,
                            barcode: line.product_barcode ?? null,
                            primary_image_url: line.primary_image_url ?? null,
                          }}
                          size="sm"
                        />
                      </Link>
                    ) : (
                      <ProductCell
                        product={{
                          name: line.product_name || line.description,
                          sku: line.product_code ?? null,
                          barcode: line.product_barcode ?? null,
                          primary_image_url: line.primary_image_url ?? null,
                        }}
                        size="sm"
                      />
                    )}
                    {line.notes && (
                      <div className={`text-xs ${colorClasses.textGray500} mt-1`}>{line.notes}</div>
                    )}
                  </td>
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right`}>
                    {formatQuantity(line.quantity, getQuantityDecimals(line))}
                  </td>
                  {canShowReceiptStatus && (
                    <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right`}>
                      {t('purchaseOrders.receivedOfTotal', {
                        received: formatQuantity(
                          receiptLineById.get(line.id)?.quantity_received ?? line.quantity_received ?? '0',
                          getQuantityDecimals(line),
                        ),
                        total: formatQuantity(
                          receiptLineById.get(line.id)?.quantity_ordered ?? line.quantity,
                          getQuantityDecimals(line),
                        ),
                      })}
                    </td>
                  )}
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right`}>
                    {formatCurrency(line.unit_price, { currency: currentCompany?.currency ?? 'EUR' })}
                  </td>
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right font-medium`}>
                    {formatCurrency(line.line_total, { currency: currentCompany?.currency ?? 'EUR' })}
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </div>

        {/* Totals */}
        <div className={`${colorClasses.bgGray50} px-4 py-5 sm:px-6`}>
          <div className="flex justify-end">
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between gap-12">
                <dt className={`${colorClasses.textGray500}`}>{t('documents.subtotal')}</dt>
                <dd className={`${colorClasses.textGray900} font-medium`}>
                  {formatCurrency(purchaseOrder.subtotal, { currency: currentCompany?.currency ?? 'EUR' })}
                </dd>
              </div>
              {bccomp(purchaseOrder.tax_amount, '0') > 0 && (
                <div className="flex justify-between gap-12">
                  <dt className={`${colorClasses.textGray500}`}>{t('documents.tax')}</dt>
                  <dd className={`${colorClasses.textGray900} font-medium`}>
                    {formatCurrency(purchaseOrder.tax_amount, { currency: currentCompany?.currency ?? 'EUR' })}
                  </dd>
                </div>
              )}
              <div className={`flex justify-between gap-12 text-base font-bold pt-2 border-t ${colorClasses.borderGray200}`}>
                <dt className={`${colorClasses.textGray900}`}>{t('documents.total')}</dt>
                <dd className={`${colorClasses.textGray900}`}>
                  {formatCurrency(purchaseOrder.total, { currency: currentCompany?.currency ?? 'EUR' })}
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="mt-6">
        <div className={`border-b ${colorClasses.borderGray200}`}>
          <nav className="-mb-px flex space-x-8">
            <button
              onClick={() => { setActiveTab('related'); }}
              className={`${
                activeTab === 'related'
                  ? `${colorClasses.borderBlue500} ${colorClasses.textBlue600}`
                  : `border-transparent ${colorClasses.textGray500} ${colorClasses.hoverTextGray700} ${colorClasses.hoverBorderGray300}`
              } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
            >
              {t('documents.relatedDocuments')}
            </button>
            <button
              onClick={() => { setActiveTab('attachments'); }}
              className={`${
                activeTab === 'attachments'
                  ? `${colorClasses.borderBlue500} ${colorClasses.textBlue600}`
                  : `border-transparent ${colorClasses.textGray500} ${colorClasses.hoverTextGray700} ${colorClasses.hoverBorderGray300}`
              } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
            >
              {t('documents.attachments')}
            </button>
            {purchaseOrder.status === 'received' && (
              <button
                onClick={() => { setActiveTab('landedCosts'); }}
                className={`${
                  activeTab === 'landedCosts'
                    ? `${colorClasses.borderBlue500} ${colorClasses.textBlue600}`
                    : `border-transparent ${colorClasses.textGray500} ${colorClasses.hoverTextGray700} ${colorClasses.hoverBorderGray300}`
                } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-1`}
              >
                <TrendingUp className="h-4 w-4" />
                {t('purchaseOrders.landedCosts')}
              </button>
            )}
            {['confirmed', 'received'].includes(purchaseOrder.status) && (
              <button
                onClick={() => { setActiveTab('payments'); }}
                className={`${
                  activeTab === 'payments'
                    ? `${colorClasses.borderBlue500} ${colorClasses.textBlue600}`
                    : `border-transparent ${colorClasses.textGray500} ${colorClasses.hoverTextGray700} ${colorClasses.hoverBorderGray300}`
                } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
              >
                {t('purchaseOrders.paymentHistory')}
              </button>
            )}
          </nav>
        </div>

        <div className="mt-6">
          {activeTab === 'related' && (
            <div className="space-y-6">
              <RelatedDocumentsTab documentId={purchaseOrder.id} />
              {purchaseOrder.goods_receipts && purchaseOrder.goods_receipts.length > 0 && (
                <section className={tokens.card.base}>
                  <h3 className={tokens.heading.section}>{t('inventory:goodsReceipt.title')}</h3>
                  <div className="mt-3 space-y-2">
                    {purchaseOrder.goods_receipts.map((receipt) => (
                      <div key={receipt.id} className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <EntityLink
                          type="goodsReceipt"
                          id={receipt.id}
                          purchaseOrderId={purchaseOrder.id}
                          label={receipt.receipt_number ?? receipt.id}
                          className="font-medium"
                        />
                        <span className={`${colorClasses.textGray500}`}>{receipt.external_reference ?? receipt.status}</span>
                      </div>
                    ))}
                  </div>
                </section>
              )}
              {purchaseOrder.supplier_invoices && purchaseOrder.supplier_invoices.length > 0 && (
                <section className={tokens.card.base}>
                  <h3 className={tokens.heading.section}>{t('purchases:supplierInvoices.title')}</h3>
                  <div className="mt-3 space-y-2">
                    {purchaseOrder.supplier_invoices.map((invoice) => (
                      <div key={invoice.id} className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <EntityLink
                          type="document"
                          id={invoice.id}
                          documentType="supplier_invoice"
                          label={invoice.document_number ?? invoice.id}
                          className="font-medium"
                        />
                        <span className={`${colorClasses.textGray500}`}>{invoice.status}</span>
                      </div>
                    ))}
                  </div>
                </section>
              )}
            </div>
          )}
          {activeTab === 'attachments' && <DocumentAttachments documentId={purchaseOrder.id} />}
          {activeTab === 'landedCosts' && purchaseOrder.status === 'received' && (
            <PurchaseOrderLandedCostBreakdown documentId={purchaseOrder.id} />
          )}
          {activeTab === 'payments' && ['confirmed', 'received'].includes(purchaseOrder.status) && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2">
                <PaymentHistorySection
                  documentId={purchaseOrder.id}
                  currency={currentCompany?.currency ?? 'EUR'}
                />
              </div>
              <div>
                <OutstandingAmountSection
                  total={total}
                  amountPaid={amountPaid}
                  creditNotesApplied={creditNotesApplied}
                  outstandingAmount={outstandingAmount}
                  paymentStatus={paymentStatus ?? 'unpaid'}
                  currency={currentCompany?.currency ?? 'EUR'}
                  onRecordPayment={canRecordPayment ? () => { setShowPaymentModal(true); } : undefined}
                />
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Confirmation Dialogs */}
      <ConfirmDialog
        isOpen={confirmAction === 'confirm'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleConfirm}
        title={t('documents.confirmTitle')}
        message={t('documents.confirmMessage')}
        confirmText={t('common:confirm')}
        isLoading={confirmMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'revert'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleRevert}
        title={t('documents.revertToDraftTitle')}
        message={t('documents.revertToDraftMessage')}
        confirmText={t('documents.revertToDraft')}
        isLoading={revertMutation.isPending}
      />

      <ReceiveGoodsDialog
        key={showReceiveDialog ? `receive-open-${purchaseOrder.id}` : `receive-closed-${purchaseOrder.id}`}
        isOpen={showReceiveDialog}
        purchaseOrder={purchaseOrderWithReceiptQuantities ?? purchaseOrder}
        isLoading={receiveGoodsMutation.isPending}
        onClose={() => { setShowReceiveDialog(false); }}
        onConfirm={handleReceiveGoods}
      />

      {/* Record Payment Modal */}
      {purchaseOrder.partner_id && (
        <RecordPaymentModal
          isOpen={showPaymentModal}
          onClose={() => { setShowPaymentModal(false); }}
          onSuccess={handlePaymentSuccess}
          prefill={{
            partner_id: purchaseOrder.partner_id,
            partner_name: purchaseOrder.partner_name || '',
            amount: outstandingAmount,
            reference: purchaseOrder.document_number ?? '',
            document_id: purchaseOrder.id,
            document_type: 'purchase_order',
          }}
        />
      )}

      {/* Email Modal */}
      <Modal
        isOpen={showEmailModal}
        onClose={() => { setShowEmailModal(false); }}
        title={t('common.sendEmail')}
        size="md"
      >
        <div className="space-y-4">
          <div>
            <label className={tokens.label.base}>{t('common.recipientEmail')}</label>
            <Input
              type="email"
              value={emailForm.recipientEmail}
              onChange={(e) => { setEmailForm({ ...emailForm, recipientEmail: e.target.value }); }}
            />
          </div>
          <div>
            <label className={tokens.label.base}>{t('common.subject')}</label>
            <Input
              type="text"
              value={emailForm.subject}
              onChange={(e) => { setEmailForm({ ...emailForm, subject: e.target.value }); }}
            />
          </div>
          <div>
            <label className={tokens.label.base}>{t('common.message')}</label>
            <Textarea
              value={emailForm.message}
              onChange={(e) => { setEmailForm({ ...emailForm, message: e.target.value }); }}
              rows={4}
            />
          </div>
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={() => { setShowEmailModal(false); }}>
              {t('common:cancel')}
            </Button>
            <Button
              onClick={handleSendEmail}
              disabled={sendEmailMutation.isPending || !emailForm.recipientEmail}
            >
              {t('common:send')}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
