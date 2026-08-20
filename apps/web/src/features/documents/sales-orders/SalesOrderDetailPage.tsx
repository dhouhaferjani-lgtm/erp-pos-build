import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { AlertTriangle, Calendar, Building2, FileText, Car, Truck } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { formatCurrency } from '../../../lib/format'
import { formatQuantity } from '../../../lib/decimal'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentAttachments } from '../components/DocumentAttachments'
import { DocumentTotals } from '../components/DocumentTotals'
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
import { Checkbox } from '../../../components/atoms/Checkbox/Checkbox'
import { Input } from '../../../components/atoms/Input/Input'
import { StatusBadge, type StatusTone } from '../../../components/atoms/StatusBadge/StatusBadge'
import { Textarea } from '../../../components/atoms/Textarea/Textarea'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { semanticColorTokens, tokens } from '../../../lib/designTokens'
import { useCompany } from '../../../hooks/useCompany'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type ConfirmAction = 'confirm' | 'convertToInvoice' | 'convertToDelivery' | 'revert' | null
type ActiveTab = 'related' | 'attachments' | 'payments'

interface BillingRefusalDocument {
  id: string
  document_number: string
  reason: 'claim_lost' | 'already_invoiced'
  invoice_id: string | null
  invoice_number: string | null
  invoice_date: string | null
  invoiced_via: string | null
}

interface BillingRefusal {
  documents: BillingRefusalDocument[]
  billed_order_line_ids?: string[]
}

interface InvoiceConversionOptions {
  partial?: true
  line_ids?: string[]
}

const deliveryStatusTones: Record<string, StatusTone> = {
  not_delivered: 'neutral',
  partially_delivered: 'warning',
  fully_delivered: 'success',
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function nullableString(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null
}

function parseBillingRefusal(error: unknown): BillingRefusal | null {
  if (!isRecord(error)) return null
  const response = error['response']
  if (!isRecord(response) || response['status'] !== 422) return null
  const data = response['data']
  if (!isRecord(data)) return null
  const envelope = data['error']
  if (!isRecord(envelope) || envelope['code'] !== 'DELIVERY_NOTE_ALREADY_INVOICED') return null
  const details = envelope['details']
  if (!isRecord(details) || !Array.isArray(details['documents'])) return null

  const documents: BillingRefusalDocument[] = []
  for (const candidate of details['documents']) {
    if (!isRecord(candidate)) continue
    const id = nullableString(candidate['id'])
    const documentNumber = nullableString(candidate['document_number'])
    const reason = candidate['reason']
    if (id === null || documentNumber === null || (reason !== 'claim_lost' && reason !== 'already_invoiced')) {
      continue
    }

    documents.push({
      id,
      document_number: documentNumber,
      reason,
      invoice_id: nullableString(candidate['invoice_id']),
      invoice_number: nullableString(candidate['invoice_number']),
      invoice_date: nullableString(candidate['invoice_date']),
      invoiced_via: nullableString(candidate['invoiced_via']),
    })
  }

  const rawBilledOrderLineIds = details['billed_order_line_ids']
  const billedOrderLineIds = Array.isArray(rawBilledOrderLineIds)
    ? rawBilledOrderLineIds.filter((lineId): lineId is string => typeof lineId === 'string' && lineId.length > 0)
    : undefined

  return documents.length > 0
    ? {
        documents,
        ...(billedOrderLineIds === undefined ? {} : { billed_order_line_ids: billedOrderLineIds }),
      }
    : null
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

export function SalesOrderDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [showPaymentModal, setShowPaymentModal] = useState(false)
  const [activeTab, setActiveTab] = useState<ActiveTab>('related')
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [billingRefusal, setBillingRefusal] = useState<BillingRefusal | null>(null)
  const [showRemainingLinePicker, setShowRemainingLinePicker] = useState(false)
  const [selectedRemainingLineIds, setSelectedRemainingLineIds] = useState<Set<string>>(new Set())
  const [emailForm, setEmailForm] = useState({
    recipientEmail: '',
    subject: '',
    message: '',
    ccEmails: '',
  })

  // Fetch sales order
  const { data: order, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['document', 'sales_order', id]),
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/orders/${id}`)
      return response.data.data
    },
    enabled: id.length > 0 && tenantId !== null && companyId !== null,
  })

  // PDF mutations
  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()
  const sendEmailMutation = useSendDocumentEmail()
  const revertMutation = useRevertDocument(id, 'sales_order')

  // Confirm order mutation
  const confirmMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/orders/${id}/confirm`, {}),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] }),
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

  // Convert to invoice mutation
  const convertToInvoiceMutation = useMutation({
    mutationFn: (options: InvoiceConversionOptions = {}) => apiPost<Document>(`/orders/${id}/convert-to-invoice`, options),
    onSuccess: async (data) => {
      setBillingRefusal(null)
      setShowRemainingLinePicker(false)
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] }),
      ])
      if (data?.id) {
        void navigate(`/sales/invoices/${data.id}`)
      }
    },
    onError: async (error: unknown) => {
      const refusal = parseBillingRefusal(error)
      if (refusal !== null) {
        setBillingRefusal(refusal)
      } else {
        toast.error(getErrorMessage(error) || t('documents.conversionError'))
      }
      await queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] })
    },
  })

  // Convert to delivery note mutation
  const convertToDeliveryMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/orders/${id}/convert-to-delivery`, {}),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] }),
      ])
      if (data?.id) {
        void navigate(`/inventory/delivery-notes/${data.id}`)
      }
    },
    onError: async (error: Error) => {
      toast.error(error.message || t('documents.conversionError'))
      await queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] })
    },
  })

  const isActionPending = confirmMutation.isPending || convertToInvoiceMutation.isPending || convertToDeliveryMutation.isPending || revertMutation.isPending

  // Action handlers
  const handleConfirm = () => {
    confirmMutation.mutate()
    setConfirmAction(null)
  }

  const handleConvertToInvoice = () => {
    convertToInvoiceMutation.mutate({})
    setConfirmAction(null)
  }

  const handleConvertToDelivery = () => {
    convertToDeliveryMutation.mutate()
    setConfirmAction(null)
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
    if (!order) return
    downloadPdfMutation.mutate(order.id)
  }

  const handlePreviewPdf = () => {
    if (!order) return
    previewPdfMutation.mutate(order.id)
  }

  const handlePrintPdf = () => {
    if (!order) return
    printPdfMutation.mutate(order.id)
  }

  const handleSendEmail = () => {
    if (!order) return

    const { recipientEmail, subject, message, ccEmails } = emailForm

    sendEmailMutation.mutate({
      documentId: order.id,
      recipientEmail,
      subject,
      message,
      ccEmails: ccEmails.split(',').map(e => e.trim()).filter(e => e),
    }, {
      onSuccess: () => {
        setShowEmailModal(false)
        setEmailForm({ recipientEmail: '', subject: '', message: '', ccEmails: '' })
        toast.success(t('common:email.success'))
      },
    })
  }

  const handlePaymentSuccess = async () => {
    setShowPaymentModal(false)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] }),
      queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('documents', tenantId, companyId),
      }),
      queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('payments', tenantId, companyId),
      }),
    ])
  }

  const billedSourceLineIds = new Set(
    billingRefusal?.billed_order_line_ids ?? [],
  )
  const canDetermineRemainingLines = billingRefusal?.billed_order_line_ids !== undefined
  const remainingLines = (order?.lines ?? []).filter((line) => !billedSourceLineIds.has(line.id))

  const openRemainingLinePicker = () => {
    setSelectedRemainingLineIds(new Set(remainingLines.map((line) => line.id)))
    setShowRemainingLinePicker(true)
  }

  const toggleRemainingLine = (lineId: string) => {
    setSelectedRemainingLineIds((current) => {
      const next = new Set(current)
      if (next.has(lineId)) next.delete(lineId)
      else next.add(lineId)
      return next
    })
  }

  const confirmRemainingLines = () => {
    convertToInvoiceMutation.mutate({
      partial: true,
      line_ids: Array.from(selectedRemainingLineIds),
    })
  }

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

  if (error || !order) {
    return (
      <div className="py-6">
        <div className={`${colorClasses.bgRed50} border ${colorClasses.borderRed200} rounded-lg p-4`}>
          <p className={`${colorClasses.textRed800}`}>{t('common.errorLoadingData')}</p>
        </div>
      </div>
    )
  }

  // Get delivery status from payload
  const deliveryStatus = order.payload?.['delivery_status'] || 'not_delivered'

  // Payment computation
  const outstandingAmount = parseFloat(order.outstanding_amount || order.balance_due || '0')
  const total = parseFloat(order.total || '0')
  const amountPaid = parseFloat(order.amount_paid || '0')
  const isPaid = order.payment_status === 'paid' || outstandingAmount === 0
  const canRecordPayment = order.status === 'confirmed' && !isPaid && outstandingAmount > 0
  const creditNotesApplied = Math.max(0, total - outstandingAmount - amountPaid)
  const paymentStatus = isPaymentStatus(order.payment_status) ? order.payment_status : null
  const PaymentStatusIcon = paymentStatus === null ? null : paymentStatusIcon(paymentStatus)

  return (
    <div className="py-6">
      {/* Header */}
      <div className="mb-6">
        <DocumentHeader
          document={order}
          backPath="/sales/orders"
          actions={
            <DocumentActionBar
              document={order}
              basePath="/sales/orders"
              isActionPending={isActionPending}
              onConfirm={() => { setConfirmAction('confirm'); }}
              onConvert={() => { setConfirmAction('convertToInvoice'); }}
              onConvertToDelivery={() => { setConfirmAction('convertToDelivery'); }}
              onRevert={() => { setConfirmAction('revert'); }}
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
            !isPaid && outstandingAmount > 0 ? (
              <DocumentOutstandingCallout
                amount={outstandingAmount}
                currency={currentCompany?.currency ?? 'EUR'}
                {...(canRecordPayment ? { onRecordPayment: () => { setShowPaymentModal(true); } } : {})}
              />
            ) : undefined
          }
        >
          {order.status === 'confirmed' && (
            <StatusBadge tone={deliveryStatusTones[String(deliveryStatus)] ?? 'neutral'} className="gap-1.5">
              <Truck className="h-3 w-3" />
              {t(`orders.deliveryStatus.${deliveryStatus}`)}
            </StatusBadge>
          )}
          {order.status === 'confirmed' && paymentStatus !== null && PaymentStatusIcon !== null && (
            <StatusBadge tone={paymentStatusTone(paymentStatus)} className="gap-1.5">
              <PaymentStatusIcon className="h-3 w-3" />
              {t(`sales:invoices.paymentStatus.${paymentStatus}`, {
                defaultValue: paymentStatusFallbackLabel(paymentStatus),
              })}
            </StatusBadge>
          )}
        </DocumentHeader>
      </div>

      {billingRefusal !== null && (
        <section
          role="alert"
          aria-labelledby="billing-refusal-title"
          className={`mb-6 rounded-lg border ${semanticColorTokens.intent.danger.borderSubtle} ${semanticColorTokens.intent.danger.bgSubtle} p-4`}
        >
          <div className="flex items-start gap-3">
            <AlertTriangle aria-hidden="true" className={`mt-0.5 h-5 w-5 shrink-0 ${semanticColorTokens.intent.danger.text}`} />
            <div className="min-w-0 flex-1">
              <h2 id="billing-refusal-title" className={`font-semibold ${semanticColorTokens.intent.danger.textStronger}`}>
                {t('orders.billingRefusal.title')}
              </h2>
              <p className={`mt-1 text-sm ${semanticColorTokens.intent.danger.textStronger}`}>
                {t('orders.billingRefusal.guarantee')}
              </p>
              <ul className="mt-4 space-y-3">
                {billingRefusal.documents.map((document) => (
                  <li key={document.id} className={`rounded-md border ${semanticColorTokens.intent.danger.borderSubtle} ${semanticColorTokens.surface.base} p-3`}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <p className={`font-medium ${semanticColorTokens.text.primary}`}>{document.document_number}</p>
                        <p className={`mt-1 text-sm ${semanticColorTokens.text.muted}`}>
                          {document.invoice_date ?? '—'}
                          {' · '}
                          {document.invoiced_via === null
                            ? t('orders.billingRefusal.billedBy.unknown')
                            : t(`orders.billingRefusal.billedBy.${document.invoiced_via}`, {
                                // The parser types `invoiced_via` as an unconstrained
                                // `string | null` (deliveryNoteBillingRefusal.ts), not the four
                                // lane cases, so any lane the server adds without a locale key
                                // would render the raw key string in en and fr. Matches the two
                                // sibling surfaces, ToBillPage.tsx:85 and
                                // PartnerDeliveryNotesTab.tsx:225. (M5-terminal FE E1.)
                                defaultValue: t('orders.billingRefusal.billedBy.unknown'),
                              })}
                        </p>
                      </div>
                      {document.invoice_id !== null && document.invoice_number !== null && (
                        <Link
                          to={`/sales/invoices/${document.invoice_id}`}
                          className={`text-sm font-medium ${semanticColorTokens.intent.primary.text} hover:underline`}
                        >
                          {t('orders.billingRefusal.openInvoice', { number: document.invoice_number })}
                        </Link>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
              {canDetermineRemainingLines && remainingLines.length > 0 && (
                <div className="mt-4">
                  <Button type="button" onClick={openRemainingLinePicker}>
                    {t('orders.billingRefusal.invoiceRemaining')}
                  </Button>
                </div>
              )}
              {canDetermineRemainingLines && remainingLines.length === 0 && (
                <p className={`mt-4 text-sm ${semanticColorTokens.text.secondary}`}>
                  {t('orders.billingRefusal.noRemaining')}
                </p>
              )}
              {!canDetermineRemainingLines && (
                <p className={`mt-4 text-sm ${semanticColorTokens.text.secondary}`}>
                  {t('orders.billingRefusal.remainingUnavailable')}
                </p>
              )}
            </div>
          </div>
        </section>
      )}

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
                {new Date(order.document_date).toLocaleDateString()}
              </dd>
            </div>

            <div>
              <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                <Building2 className="h-4 w-4" />
                {t('documents.customer')}
              </dt>
              <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                <EntityLink
                  type="partner"
                  id={order.partner_id}
                  partnerType="customer"
                  label={order.partner_name || '-'}
                />
              </dd>
            </div>

            {order.vehicleContext && (
              <div>
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <Car className="h-4 w-4" />
                  {t('documents.vehicle')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                  {order.vehicleContext.vehicle_snapshot?.make} {order.vehicleContext.vehicle_snapshot?.model}
                  {order.vehicleContext.vehicle_snapshot?.license_plate &&
                    ` (${order.vehicleContext.vehicle_snapshot.license_plate})`
                  }
                </dd>
              </div>
            )}

            {order.notes && (
              <div className="sm:col-span-3">
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <FileText className="h-4 w-4" />
                  {t('documents.notes')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900} whitespace-pre-wrap`}>
                  {order.notes}
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
                {order.status === 'confirmed' && (
                  <th className={`px-6 py-3 text-right text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                    {t('orders.delivered')}
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
              {order.lines?.map((line) => (
                <tr key={line.id}>
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                    <EntityLink
                      type="product"
                      id={line.product_id}
                      label={line.description}
                    />
                    {line.notes && (
                      <div className={`text-xs ${colorClasses.textGray500} mt-1`}>{line.notes}</div>
                    )}
                  </td>
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right`}>
                    {formatQuantity(line.quantity, getQuantityDecimals(line))}
                  </td>
                  {order.status === 'confirmed' && (
                    <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right`}>
                      {formatQuantity(line.quantity_delivered || '0', getQuantityDecimals(line))}
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
            <div className="w-full max-w-md">
              <DocumentTotals
                documentId={order.id}
                documentType="sales_order"
                currency={currentCompany?.currency ?? 'EUR'}
              />
            </div>
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
            {order.status === 'confirmed' && (
              <button
                onClick={() => { setActiveTab('payments'); }}
                className={`${
                  activeTab === 'payments'
                    ? `${colorClasses.borderBlue500} ${colorClasses.textBlue600}`
                    : `border-transparent ${colorClasses.textGray500} ${colorClasses.hoverTextGray700} ${colorClasses.hoverBorderGray300}`
                } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
              >
                {t('documents.paymentHistory')}
              </button>
            )}
          </nav>
        </div>

        <div className="mt-6">
          {activeTab === 'related' && <RelatedDocumentsTab documentId={order.id} />}
          {activeTab === 'attachments' && <DocumentAttachments documentId={order.id} />}
          {activeTab === 'payments' && order.status === 'confirmed' && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2">
                <PaymentHistorySection
                  documentId={order.id}
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

      <Modal
        isOpen={showRemainingLinePicker}
        onClose={() => { setShowRemainingLinePicker(false); }}
        title={t('orders.billingRefusal.remainingTitle')}
        size="md"
      >
        <div className="space-y-4">
          <div className="space-y-2">
            {remainingLines.map((line) => (
              <label
                key={line.id}
                className={`flex cursor-pointer items-start gap-3 rounded-md border ${semanticColorTokens.border.subtle} p-3`}
              >
                <Checkbox
                  checked={selectedRemainingLineIds.has(line.id)}
                  onChange={() => { toggleRemainingLine(line.id); }}
                  className="mt-1"
                />
                <span className={`text-sm ${semanticColorTokens.text.primary}`}>{line.description}</span>
              </label>
            ))}
          </div>
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={() => { setShowRemainingLinePicker(false); }}>
              {t('common:cancel')}
            </Button>
            <Button
              onClick={confirmRemainingLines}
              disabled={selectedRemainingLineIds.size === 0 || convertToInvoiceMutation.isPending}
            >
              {t('orders.billingRefusal.confirmRemaining')}
            </Button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        isOpen={confirmAction === 'convertToInvoice'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleConvertToInvoice}
        title={t('orders.convertToInvoiceTitle')}
        message={t('orders.convertToInvoiceMessage')}
        confirmText={t('common:convert')}
        isLoading={convertToInvoiceMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'convertToDelivery'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleConvertToDelivery}
        title={t('orders.convertToDeliveryTitle')}
        message={t('orders.convertToDeliveryMessage')}
        confirmText={t('common:convert')}
        isLoading={convertToDeliveryMutation.isPending}
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

      {/* Record Payment Modal */}
      {order.partner_id && (
        <RecordPaymentModal
          isOpen={showPaymentModal}
          onClose={() => { setShowPaymentModal(false); }}
          onSuccess={handlePaymentSuccess}
          prefill={{
            partner_id: order.partner_id,
            partner_name: order.partner_name || '',
            amount: outstandingAmount,
            reference: order.document_number ?? '',
            document_id: order.id,
            document_type: 'sales_order',
          }}
        />
      )}

      {/* Email Modal */}
      <Modal
        isOpen={showEmailModal}
        onClose={() => { setShowEmailModal(false); }}
        title={t('common:email.title')}
        size="md"
      >
        <div className="space-y-4">
          <div>
            <label className={tokens.label.base}>{t('common:email.recipientEmail')}</label>
            <Input
              type="email"
              value={emailForm.recipientEmail}
              onChange={(e) => { setEmailForm({ ...emailForm, recipientEmail: e.target.value }); }}
            />
          </div>
          <div>
            <label className={tokens.label.base}>{t('common:email.subject')}</label>
            <Input
              type="text"
              value={emailForm.subject}
              onChange={(e) => { setEmailForm({ ...emailForm, subject: e.target.value }); }}
            />
          </div>
          <div>
            <label className={tokens.label.base}>{t('common:email.message')}</label>
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
