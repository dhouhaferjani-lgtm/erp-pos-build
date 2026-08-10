import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Calendar, Building2, FileText, Car, Lock } from 'lucide-react'
import { AxiosError } from 'axios'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { formatCurrency, formatDate } from '../../../lib/format'
import { formatQuantity } from '../../../lib/decimal'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentAttachments } from '../components/DocumentAttachments'
import { DocumentTotals } from '../components/DocumentTotals'
import { DocumentHeader } from '../components/DocumentHeader'
import { DocumentOutstandingCallout } from '../components/DocumentOutstandingCallout'
import { CreateCreditNoteForm } from '../components/CreateCreditNoteForm'
import { CreditNoteList } from '../components/CreditNoteList'
import { DeliveryConfirmationModal } from '../components/DeliveryConfirmationModal'
import type { DeliveryConfirmationVariant, PreDeliveryPolicySource } from '../components/DeliveryConfirmationModal'
import { OutstandingAmountSection } from '../components/OutstandingAmountSection'
import { PaymentHistorySection } from '../components/PaymentHistorySection'
import { isPaymentStatus, paymentStatusFallbackLabel, paymentStatusIcon, paymentStatusTone } from '../components/paymentStatus'
import { useCreditNotes } from '../hooks/useCreditNotes'
import { useSendDocumentEmail } from '../hooks/useDocumentEmail'
import { useDownloadPdf, usePreviewPdf, usePrintPdf } from '../hooks/useDocumentPdf'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { CancelInvoiceModal } from './components/CancelInvoiceModal'
import { useCancelInvoice, useCanCancelInvoice } from './hooks/useCancelInvoice'
import { entityRoutes } from '@/lib/entityRoutes'
import { extractErrorCode } from '@/utils/errorHandling'
import { RecordPaymentModal } from '../../../components/organisms/RecordPaymentModal'
import { Modal } from '../../../components/organisms/Modal/Modal'
import { Button } from '../../../components/atoms/Button/Button'
import { Input } from '../../../components/atoms/Input/Input'
import { StatusBadge } from '../../../components/atoms/StatusBadge/StatusBadge'
import { Textarea } from '../../../components/atoms/Textarea/Textarea'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { tokens, textColors, borderColors, semanticColorTokens } from '../../../lib/designTokens'
import { CloseWithWriteoffSection } from './components/CloseWithWriteoffSection'
import { useCompany } from '../../../hooks/useCompany'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { Document } from '../../../types/document'
import type { InvoiceForCreditNote } from '../../../types/creditNote'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type ConfirmAction = 'confirm' | 'post' | null
type ActiveTab = 'related' | 'attachments' | 'creditNotes' | 'payments'

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

export function InvoiceDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [showCancelModal, setShowCancelModal] = useState(false)
  const [cancelError, setCancelError] = useState<
    { code?: string | undefined; details?: Record<string, unknown> | undefined } | undefined
  >(undefined)
  /**
   * A submit came back failed. Tracked SEPARATELY from `cancelError.code` because a 422
   * can carry no readable code at all — and "no code" must still produce actionable
   * feedback rather than a silently dead button (gate CF round 1, Blocker B2).
   */
  const [cancelSubmitFailed, setCancelSubmitFailed] = useState(false)
  const [showCreditNoteForm, setShowCreditNoteForm] = useState(false)
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [showDeliveryConfirmationModal, setShowDeliveryConfirmationModal] = useState(false)
  const [draftDeliveryNotes, setDraftDeliveryNotes] = useState<Array<{ id: string; number: string; total: string; line_count: number }>>([])
  /**
   * DPA Wave 3 T25c. Which delivery situation the modal is in: confirming
   * delivery notes that already exist, or CREATING one because the country's
   * pre-delivery invoicing policy refuses to post an undelivered goods invoice.
   */
  const [deliveryModalVariant, setDeliveryModalVariant] = useState<DeliveryConfirmationVariant>('confirm-existing')
  const [preDeliveryPolicySource, setPreDeliveryPolicySource] = useState<PreDeliveryPolicySource>('country')
  const [preDeliveryBlockedReason, setPreDeliveryBlockedReason] = useState<string | null>(null)
  const [showPaymentModal, setShowPaymentModal] = useState(false)
  const [activeTab, setActiveTab] = useState<ActiveTab>('related')
  const [emailForm, setEmailForm] = useState({
    recipientEmail: '',
    subject: '',
    message: '',
    ccEmails: '',
  })

  // Fetch invoice
  const { data: invoice, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['document', 'invoice', id]),
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/invoices/${id}`)
      return response.data.data
    },
    enabled: id.length > 0 && tenantId !== null && companyId !== null,
  })

  // Fetch credit notes for posted invoices
  const { data: creditNotesData } = useCreditNotes(
    invoice?.status === 'posted' ? { source_invoice_id: id } : undefined
  )
  const creditNotes = creditNotesData ?? []

  // PDF mutations
  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()
  const sendEmailMutation = useSendDocumentEmail()

  // Confirm invoice mutation
  const confirmMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/invoices/${id}/confirm`, {}),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] }),
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

  // Post invoice mutation (fiscal posting)
  const postMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/invoices/${id}/post`, {}),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
      ])
      toast.success(t('documents.messages.posted'))
    },
    onError: (error: Error) => {
      // Check if error is due to draft delivery notes
      if (error instanceof AxiosError) {
        const errorData = error.response?.data as { error?: { code?: string; details?: { status?: string; can_auto_confirm?: boolean; policy_source?: PreDeliveryPolicySource; blocked_reason?: string | null; draft_dns?: Array<{ id: string; number: string; total: string; line_count: number }> } } } | undefined

        // DPA Wave 3 T25b — the COMPLIANCE refusal. Distinct code, distinct
        // remedy: there is nothing to confirm, so the guided flow creates the
        // delivery note. Note there is no `can_auto_confirm === true` gate on
        // opening the modal here: when the guided path is blocked the modal is
        // what explains WHY and what to do instead, which a toast cannot.
        if (errorData?.error?.code === 'DELIVERY_REQUIRED_BEFORE_INVOICE') {
          setDraftDeliveryNotes([])
          setDeliveryModalVariant('create-new')
          setPreDeliveryPolicySource(errorData.error.details?.policy_source ?? 'country')
          setPreDeliveryBlockedReason(
            errorData.error.details?.can_auto_confirm === true
              ? null
              : (errorData.error.details?.blocked_reason ?? 'UNKNOWN'),
          )
          setShowDeliveryConfirmationModal(true)
          return
        }

        if (
          errorData?.error?.code === 'DELIVERY_NOT_COMPLETED' &&
          errorData?.error?.details?.status === 'draft_dns_found' &&
          errorData?.error?.details?.can_auto_confirm === true
        ) {
          const draftDns = errorData.error.details.draft_dns ?? []
          setDraftDeliveryNotes(draftDns)
          setDeliveryModalVariant('confirm-existing')
          setPreDeliveryBlockedReason(null)
          setShowDeliveryConfirmationModal(true)
          return
        }
      }
      toast.error(getErrorMessage(error))
    },
  })

  // Confirm deliveries and post mutation (one-click workflow)
  const confirmDeliveriesAndPostMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/invoices/${id}/confirm-deliveries-and-post`, {}),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('delivery-notes', tenantId, companyId),
        }),
      ])
      setShowDeliveryConfirmationModal(false)
      toast.success(t('sales:invoices.deliveryConfirmation.success'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  /**
   * DPA Wave 3 T25c / D-30 — the REQUIRED path for a standalone goods invoice.
   *
   * A sibling endpoint, not a widening of confirm-deliveries-and-post: that one
   * confirms delivery notes an ORDER already has and hard-refuses an invoice
   * with no source order. This one creates the note from the invoice's own goods
   * lines, writes the linkage the server resolver reads, confirms it and posts —
   * in one server-side transaction.
   */
  const createDeliveryAndPostMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/invoices/${id}/create-delivery-and-post`, {}),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('delivery-notes', tenantId, companyId),
        }),
        // This mutation ISSUES STOCK — it is the only invoice action on this page
        // that does. Leaving the stock and product caches alone left every
        // on-hand figure in the session showing pre-issue quantities until
        // something else happened to refetch them.
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('stock', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('stock-levels', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('products', tenantId, companyId),
        }),
      ])
      setShowDeliveryConfirmationModal(false)
      toast.success(t('sales:invoices.preDeliveryInvoicing.success'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  /**
   * Plan CF T9/T11. `can-cancel` drives BOTH the button's availability and the modal's
   * content (CF-D5/CF-D6). The `enabled` predicate keeps it off the six non-invoice
   * detail pages that share `DocumentActionBar`, and off a non-posted invoice.
   */
  const canCancelQuery = useCanCancelInvoice(
    id,
    invoice?.type === 'invoice' && invoice.status === 'posted',
  )

  const cancelMutation = useCancelInvoice({
    invoiceId: id ?? '',
    onSuccess: (response) => {
      setShowCancelModal(false)
      setCancelError(undefined)
      setCancelSubmitFailed(false)

      const returnNote = response.return_decision?.return_note ?? null
      if (returnNote) {
        // The ruling's "the user finds it under return notes" is NOT satisfied by a
        // bare toast — the toast carries the link.
        toast.success(
          t('sales:invoices.cancelFlow.success.withReturnNote', { number: returnNote.document_number }),
          {
            action: {
              label: t('sales:invoices.cancelFlow.success.viewReturnNote'),
              onClick: () => {
                void navigate(entityRoutes.document(returnNote.id, { documentType: 'return_note' }))
              },
            },
          },
        )
      } else {
        toast.success(t('sales:invoices.cancelFlow.success.cancelled'))
      }
    },
    onError: (error) => {
      // The modal STAYS OPEN so the user can fix the date or pick another option —
      // and, on a network error, retry. That retry is why the server's
      // identical-replay path has to return 200 with the existing return note.
      setCancelSubmitFailed(true)

      const body = error.response?.data
      setCancelError({
        code: extractErrorCode(error) ?? body?.error?.code,
        // `error.DETAILS`, not the whole `error` object (gate CF round 1, MAJOR M2).
        // The renderer nests the payload one level deeper, so `details.product_id` and
        // `details.remaining_returnable` were both undefined and the product-named
        // quantity refusal interpolated empty strings: "…exceed what was delivered for
        //  — only  is still available to return." Plan §2 makes naming the product
        // MANDATORY precisely because CF-D7 removed the line UI, so that banner is the
        // one refusal that is unactionable without its details.
        details: body?.error?.['details'] as Record<string, unknown> | undefined,
      })
    },
  })

  const isActionPending = confirmMutation.isPending || postMutation.isPending

  // Action handlers
  const handleConfirm = () => {
    confirmMutation.mutate()
    setConfirmAction(null)
  }

  const handlePost = () => {
    postMutation.mutate()
    setConfirmAction(null)
  }

  const handleDownloadPdf = () => {
    if (!invoice) return
    downloadPdfMutation.mutate(invoice.id)
  }

  const handlePreviewPdf = () => {
    if (!invoice) return
    previewPdfMutation.mutate(invoice.id)
  }

  const handlePrintPdf = () => {
    if (!invoice) return
    printPdfMutation.mutate(invoice.id)
  }

  const handleSendEmail = () => {
    if (!invoice) return

    const { recipientEmail, subject, message, ccEmails } = emailForm

    sendEmailMutation.mutate({
      documentId: invoice.id,
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

  const handleCreditNoteCreated = async () => {
    setShowCreditNoteForm(false)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] }),
      queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('documents', tenantId, companyId),
      }),
      queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('credit-notes', tenantId, companyId),
      }),
    ])
  }

  const handlePaymentSuccess = async () => {
    setShowPaymentModal(false)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] }),
      queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('documents', tenantId, companyId),
      }),
      queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('payments', tenantId, companyId),
      }),
    ])
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

  if (error || !invoice) {
    return (
      <div className="py-6">
        <div className={`${colorClasses.bgRed50} border ${colorClasses.borderRed200} rounded-lg p-4`}>
          <p className={`${colorClasses.textRed800}`}>{t('common.errorLoadingData')}</p>
        </div>
      </div>
    )
  }

  const isPosted = invoice.status === 'posted'

  // Use computed outstanding_amount (source of truth) for display
  const outstandingAmount = parseFloat(invoice.outstanding_amount || invoice.balance_due || '0')
  const isPaid = invoice.payment_status === 'paid' || outstandingAmount === 0

  const isConfirmedOrPosted = invoice.status === 'confirmed' || isPosted
  const canRecordPayment = isConfirmedOrPosted && !isPaid && outstandingAmount > 0
  const paymentStatus = isPaymentStatus(invoice.payment_status) ? invoice.payment_status : null
  const PaymentStatusIcon = paymentStatus === null ? null : paymentStatusIcon(paymentStatus)

  // Calculate amounts for OutstandingAmountSection
  const total = parseFloat(invoice.total || '0')
  const amountPaid = parseFloat(invoice.amount_paid || '0')
  // Credit notes applied = payments.allocations with credit_note_type
  // For now, calculate from total - outstanding - payments
  const creditNotesApplied = Math.max(0, total - outstandingAmount - amountPaid)

  return (
    <div className="py-6">
      {/* Header */}
      <div className="mb-6">
        <DocumentHeader
          document={invoice}
          backPath="/sales/invoices"
          actions={
            <DocumentActionBar
              document={invoice}
              basePath="/sales/invoices"
              isActionPending={isActionPending}
              onCancel={() => { setCancelError(undefined); setCancelSubmitFailed(false); setShowCancelModal(true); }}
              canCancelInvoice={canCancelQuery.data?.can_cancel ?? true}
              cancelReasonCode={canCancelQuery.data?.reason_code ?? null}
              onConfirm={() => { setConfirmAction('confirm'); }}
              onPost={() => { setConfirmAction('post'); }}
              onCreateCreditNote={() => { setShowCreditNoteForm(true); }}
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
          {isConfirmedOrPosted && paymentStatus !== null && PaymentStatusIcon !== null && (
            <StatusBadge tone={paymentStatusTone(paymentStatus)} className="gap-1.5">
              <PaymentStatusIcon className="h-3 w-3" />
              {t(`sales:invoices.paymentStatus.${paymentStatus}`, {
                defaultValue: paymentStatusFallbackLabel(paymentStatus),
              })}
            </StatusBadge>
          )}
          {isPosted && (
            <StatusBadge tone="info" className="gap-1.5">
              <Lock className="h-3 w-3" />
              {t('invoices.fiscallySealed')}
            </StatusBadge>
          )}
        </DocumentHeader>

        {/*
          * Plan CF T16 / CF-D5. Without this the owner's "the choice is recorded" would
          * exist only in the database — a cancelled invoice would look identical whether
          * the goods came back, stayed out, or never shipped.
          */}
        {invoice.return_decision && (
          <div className={`rounded-lg border ${borderColors.light} ${semanticColorTokens.intent.neutral.bgSubtle} p-4`}>
            <p className={`text-sm font-medium ${textColors.primary}`}>
              {t('sales:invoices.cancelFlow.recorded.title')}
            </p>
            <p className={`mt-1 text-sm ${textColors.secondary}`}>
              {t(`sales:invoices.cancelFlow.recorded.${invoice.return_decision.mode}`, {
                // Locale-formatted, not the raw ISO string (gate CF round 1, m8).
                // `formatDate`, not `new Date(...).toLocaleDateString()` (gate CF round 2,
                // NB3). A date-only string parses as UTC MIDNIGHT and renders a calendar
                // day early in any zone behind UTC, and a bare `toLocaleDateString()`
                // ignores the active UI language. `lib/format.ts`'s helper documents both
                // hazards — m8's fix had copied local precedent instead of the helper,
                // reintroducing the very bug the round-1 UTC fix removed elsewhere.
                date: invoice.return_decision.returned_on
                  ? formatDate(invoice.return_decision.returned_on)
                  : '',
              })}
            </p>
            {invoice.return_decision.return_note_id && (
              <EntityLink
                type="document"
                documentType="return_note"
                id={invoice.return_decision.return_note_id}
                label={t('sales:invoices.cancelFlow.success.viewReturnNote')}
              />
            )}
          </div>
        )}

        <CloseWithWriteoffSection
          invoiceId={invoice.id}
          invoiceTotal={invoice.total ?? '0'}
          balanceDue={invoice.balance_due ?? invoice.outstanding_amount ?? '0'}
          currency={invoice.currency ?? currentCompany?.currency ?? 'EUR'}
          invoiceStatus={invoice.status ?? ''}
        />
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
                {new Date(invoice.document_date).toLocaleDateString()}
              </dd>
            </div>

            {invoice.due_date && (
              <div>
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <Calendar className="h-4 w-4" />
                  {t('invoices.dueDate')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                  {new Date(invoice.due_date).toLocaleDateString()}
                </dd>
              </div>
            )}

            <div>
              <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                <Building2 className="h-4 w-4" />
                {t('documents.customer')}
              </dt>
              <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                <EntityLink
                  type="partner"
                  id={invoice.partner_id}
                  partnerType="customer"
                  label={invoice.partner_name || '-'}
                />
              </dd>
            </div>

            {invoice.vehicleContext && (
              <div>
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <Car className="h-4 w-4" />
                  {t('documents.vehicle')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                  {invoice.vehicleContext.vehicle_snapshot?.make} {invoice.vehicleContext.vehicle_snapshot?.model}
                  {invoice.vehicleContext.vehicle_snapshot?.license_plate &&
                    ` (${invoice.vehicleContext.vehicle_snapshot.license_plate})`
                  }
                </dd>
              </div>
            )}

            {invoice.notes && (
              <div className="sm:col-span-3">
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <FileText className="h-4 w-4" />
                  {t('documents.notes')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900} whitespace-pre-wrap`}>
                  {invoice.notes}
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
                <th className={`px-6 py-3 text-right text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                  {t('documents.unitPrice')}
                </th>
                <th className={`px-6 py-3 text-right text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                  {t('documents.total')}
                </th>
              </tr>
            </thead>
            <tbody className={`bg-white divide-y ${colorClasses.divideGray200}`}>
              {invoice.lines?.map((line) => (
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
                documentId={invoice.id}
                documentType="invoice"
                currency={currentCompany?.currency ?? 'EUR'}
                showBalanceDue={isPosted}
                balanceDue={outstandingAmount}
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
            {isPosted && (
              <button
                onClick={() => { setActiveTab('creditNotes'); }}
                className={`${
                  activeTab === 'creditNotes'
                    ? `${colorClasses.borderBlue500} ${colorClasses.textBlue600}`
                    : `border-transparent ${colorClasses.textGray500} ${colorClasses.hoverTextGray700} ${colorClasses.hoverBorderGray300}`
                } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
              >
                {t('invoices.creditNotes')} {creditNotes.length > 0 && `(${creditNotes.length})`}
              </button>
            )}
            {isConfirmedOrPosted && (
              <button
                onClick={() => { setActiveTab('payments'); }}
                className={`${
                  activeTab === 'payments'
                    ? `${colorClasses.borderBlue500} ${colorClasses.textBlue600}`
                    : `border-transparent ${colorClasses.textGray500} ${colorClasses.hoverTextGray700} ${colorClasses.hoverBorderGray300}`
                } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
              >
                {t('invoices.paymentHistory.label')}
              </button>
            )}
          </nav>
        </div>

        <div className="mt-6">
          {activeTab === 'related' && <RelatedDocumentsTab documentId={invoice.id} />}
          {activeTab === 'attachments' && <DocumentAttachments documentId={invoice.id} />}
          {activeTab === 'creditNotes' && isPosted && (
            <CreditNoteList creditNotes={creditNotes} onSelect={(cn) => navigate(`/sales/credit-notes/${cn.id}`)} />
          )}
          {activeTab === 'payments' && isConfirmedOrPosted && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2">
                <PaymentHistorySection
                  documentId={invoice.id}
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

      {/*
        * Plan CF T10/T11 — the guided cancel modal. This is the ONLY confirmation step
        * for a cancel: no nested ConfirmDialog, because the modal already asks two
        * deliberate questions and a dialog on top of a dialog is ceremony, not
        * protection.
        */}
      <CancelInvoiceModal
        isOpen={showCancelModal}
        onClose={() => { setShowCancelModal(false); setCancelError(undefined); setCancelSubmitFailed(false) }}
        invoiceNumber={invoice.document_number ?? ''}
        invoiceDocumentDate={invoice.document_date}
        canCancel={canCancelQuery.data}
        canCancelResolved={canCancelQuery.isSuccess}
        canCancelErrored={canCancelQuery.isError}
        onRetryCanCancel={() => { void canCancelQuery.refetch() }}
        isSubmitting={cancelMutation.isPending}
        errorCode={cancelError?.code}
        errorDetails={cancelError?.details}
        submitFailed={cancelSubmitFailed}
        onSubmit={({ reason, mode, returnedOn }) => {
          cancelMutation.mutate({
            reason,
            return_decision: {
              mode,
              ...(returnedOn ? { returned_on: returnedOn } : {}),
            },
          })
        }}
      />

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
        isOpen={confirmAction === 'post'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handlePost}
        title={t('invoices.postTitle')}
        message={t('invoices.postMessage')}
        confirmText={t('invoices.post')}
        isLoading={postMutation.isPending}
      />


      {/* Delivery Confirmation Modal */}
      <DeliveryConfirmationModal
        isOpen={showDeliveryConfirmationModal}
        onClose={() => { setShowDeliveryConfirmationModal(false); }}
        draftDeliveryNotes={draftDeliveryNotes}
        variant={deliveryModalVariant}
        policySource={preDeliveryPolicySource}
        blockedReason={preDeliveryBlockedReason}
        onConfirmAndPost={() => {
          if (deliveryModalVariant === 'create-new') {
            createDeliveryAndPostMutation.mutate()
            return
          }
          confirmDeliveriesAndPostMutation.mutate()
        }}
        isLoading={
          deliveryModalVariant === 'create-new'
            ? createDeliveryAndPostMutation.isPending
            : confirmDeliveriesAndPostMutation.isPending
        }
      />

      {/* Credit Note Form Modal */}
      <Modal
        isOpen={showCreditNoteForm}
        onClose={() => { setShowCreditNoteForm(false); }}
        size="xl"
        className="max-h-[90vh] overflow-y-auto"
      >
        <CreateCreditNoteForm
          invoice={invoice as unknown as InvoiceForCreditNote}
          onSuccess={handleCreditNoteCreated}
          onCancel={() => { setShowCreditNoteForm(false); }}
        />
      </Modal>

      {/* Record Payment Modal */}
      {invoice.partner_id && (
        <RecordPaymentModal
          isOpen={showPaymentModal}
          onClose={() => { setShowPaymentModal(false); }}
          onSuccess={handlePaymentSuccess}
          prefill={{
            partner_id: invoice.partner_id,
            partner_name: invoice.partner_name || '',
            amount: outstandingAmount,
            reference: invoice.document_number ?? '',
            document_id: invoice.id,
            document_type: 'invoice',
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
