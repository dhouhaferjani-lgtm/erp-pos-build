import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Calendar, Building2, FileText, Car, Lock } from 'lucide-react'
import { AxiosError } from 'axios'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentAttachments } from '../components/DocumentAttachments'
import { DocumentTotals } from '../components/DocumentTotals'
import { DocumentHeader } from '../components/DocumentHeader'
import { DocumentOutstandingCallout } from '../components/DocumentOutstandingCallout'
import { CreateCreditNoteForm, CreditNoteList } from '../components'
import { DeliveryConfirmationModal } from '../components/DeliveryConfirmationModal'
import { PaymentStatusBadge } from '../components/PaymentStatusBadge'
import { PaymentHistorySection, OutstandingAmountSection } from '../components'
import { useDownloadPdf, usePreviewPdf, usePrintPdf, useSendDocumentEmail, useCreditNotes } from '../hooks'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { RecordPaymentModal } from '../../../components/organisms/RecordPaymentModal'
import { useCompany } from '../../../hooks/useCompany'
import type { Document } from '../../../types/document'
import type { PaymentStatus } from '../components/PaymentStatusBadge'
import type { InvoiceForCreditNote } from '../../../types/creditNote'

type ConfirmAction = 'confirm' | 'post' | null
type ActiveTab = 'related' | 'attachments' | 'creditNotes' | 'payments'

export function InvoiceDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [showCreditNoteForm, setShowCreditNoteForm] = useState(false)
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [showDeliveryConfirmationModal, setShowDeliveryConfirmationModal] = useState(false)
  const [draftDeliveryNotes, setDraftDeliveryNotes] = useState<Array<{ id: string; number: string; total: string; line_count: number }>>([])
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
    queryKey: ['document', 'invoice', id],
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/invoices/${id}`)
      return response.data.data
    },
    enabled: id.length > 0,
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success(t('documents.messages.confirmed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Post invoice mutation (fiscal posting)
  const postMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/invoices/${id}/post`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success(t('documents.messages.posted'))
    },
    onError: (error: Error) => {
      // Check if error is due to draft delivery notes
      if (error instanceof AxiosError) {
        const errorData = error.response?.data as { error?: { code?: string; details?: { status?: string; can_auto_confirm?: boolean; draft_dns?: Array<{ id: string; number: string; total: string; line_count: number }> } } } | undefined
        if (
          errorData?.error?.code === 'DELIVERY_NOT_COMPLETED' &&
          errorData?.error?.details?.status === 'draft_dns_found' &&
          errorData?.error?.details?.can_auto_confirm === true
        ) {
          const draftDns = errorData.error.details.draft_dns ?? []
          setDraftDeliveryNotes(draftDns)
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['delivery-notes'] })
      setShowDeliveryConfirmationModal(false)
      toast.success(t('sales:invoices.deliveryConfirmation.success'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
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

  const handleCreditNoteCreated = () => {
    setShowCreditNoteForm(false)
    void queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] })
    void queryClient.invalidateQueries({ queryKey: ['documents'] })
    void queryClient.invalidateQueries({ queryKey: ['credit-notes'] })
  }

  const handlePaymentSuccess = () => {
    setShowPaymentModal(false)
    void queryClient.invalidateQueries({ queryKey: ['document', 'invoice', id] })
    void queryClient.invalidateQueries({ queryKey: ['documents'] })
    void queryClient.invalidateQueries({ queryKey: ['payments'] })
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
          <p className="mt-4 text-gray-600">{t('common:loading')}</p>
        </div>
      </div>
    )
  }

  if (error || !invoice) {
    return (
      <div className="py-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{t('common.errorLoadingData')}</p>
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
          {isConfirmedOrPosted && invoice.payment_status && (
            <PaymentStatusBadge status={invoice.payment_status as 'unpaid' | 'partially_paid' | 'in_payment' | 'paid' | 'overpaid'} />
          )}
          {isPosted && (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800">
              <Lock className="h-3 w-3" />
              {t('invoices.fiscallySealed')}
            </span>
          )}
        </DocumentHeader>
      </div>

      {/* Main Content */}
      <div className="bg-white shadow overflow-hidden sm:rounded-lg">
        {/* Details Section */}
        <div className="px-4 py-5 sm:px-6">
          <div className="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-3">
            <div>
              <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                <Calendar className="h-4 w-4" />
                {t('documents.documentDate')}
              </dt>
              <dd className="mt-1 text-sm text-gray-900">
                {new Date(invoice.document_date).toLocaleDateString()}
              </dd>
            </div>

            {invoice.due_date && (
              <div>
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <Calendar className="h-4 w-4" />
                  {t('invoices.dueDate')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900">
                  {new Date(invoice.due_date).toLocaleDateString()}
                </dd>
              </div>
            )}

            <div>
              <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                <Building2 className="h-4 w-4" />
                {t('documents.customer')}
              </dt>
              <dd className="mt-1 text-sm text-gray-900">
                {invoice.partner_name || '-'}
              </dd>
            </div>

            {invoice.vehicleContext && (
              <div>
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <Car className="h-4 w-4" />
                  {t('documents.vehicle')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900">
                  {invoice.vehicleContext.vehicle_snapshot?.make} {invoice.vehicleContext.vehicle_snapshot?.model}
                  {invoice.vehicleContext.vehicle_snapshot?.license_plate &&
                    ` (${invoice.vehicleContext.vehicle_snapshot.license_plate})`
                  }
                </dd>
              </div>
            )}

            {invoice.notes && (
              <div className="sm:col-span-3">
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <FileText className="h-4 w-4" />
                  {t('documents.notes')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                  {invoice.notes}
                </dd>
              </div>
            )}
          </div>
        </div>

        {/* Lines Table */}
        <div className="border-t border-gray-200">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('documents.description')}
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('documents.quantity')}
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('documents.unitPrice')}
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('documents.total')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {invoice.lines?.map((line) => (
                <tr key={line.id}>
                  <td className="px-6 py-4 text-sm text-gray-900">
                    {line.description}
                    {line.notes && (
                      <div className="text-xs text-gray-500 mt-1">{line.notes}</div>
                    )}
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-900 text-right">
                    {parseFloat(line.quantity)}
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-900 text-right">
                    {formatCurrency(parseFloat(line.unit_price), { currency: currentCompany?.currency ?? 'EUR' })}
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-900 text-right font-medium">
                    {formatCurrency(parseFloat(line.line_total), { currency: currentCompany?.currency ?? 'EUR' })}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Totals */}
        <div className="bg-gray-50 px-4 py-5 sm:px-6">
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
        <div className="border-b border-gray-200">
          <nav className="-mb-px flex space-x-8">
            <button
              onClick={() => { setActiveTab('related'); }}
              className={`${
                activeTab === 'related'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
            >
              {t('documents.relatedDocuments')}
            </button>
            <button
              onClick={() => { setActiveTab('attachments'); }}
              className={`${
                activeTab === 'attachments'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
            >
              {t('documents.attachments')}
            </button>
            {isPosted && (
              <button
                onClick={() => { setActiveTab('creditNotes'); }}
                className={`${
                  activeTab === 'creditNotes'
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
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
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
              >
                {t('invoices.paymentHistory')}
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
                  paymentStatus={invoice.payment_status as PaymentStatus}
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
        onConfirmAndPost={() => { confirmDeliveriesAndPostMutation.mutate(); }}
        isLoading={confirmDeliveriesAndPostMutation.isPending}
      />

      {/* Credit Note Form Modal */}
      {showCreditNoteForm && (
        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-lg max-w-4xl w-full p-6 max-h-[90vh] overflow-y-auto">
            <CreateCreditNoteForm
              invoice={invoice as unknown as InvoiceForCreditNote}
              onSuccess={handleCreditNoteCreated}
              onCancel={() => { setShowCreditNoteForm(false); }}
            />
          </div>
        </div>
      )}

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
            reference: invoice.document_number,
            document_id: invoice.id,
            document_type: 'invoice',
          }}
        />
      )}

      {/* Email Modal */}
      {showEmailModal && (
        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-lg max-w-md w-full p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">{t('common:email.title')}</h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('common:email.recipientEmail')}</label>
                <input
                  type="email"
                  value={emailForm.recipientEmail}
                  onChange={(e) => { setEmailForm({ ...emailForm, recipientEmail: e.target.value }); }}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('common:email.subject')}</label>
                <input
                  type="text"
                  value={emailForm.subject}
                  onChange={(e) => { setEmailForm({ ...emailForm, subject: e.target.value }); }}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('common:email.message')}</label>
                <textarea
                  value={emailForm.message}
                  onChange={(e) => { setEmailForm({ ...emailForm, message: e.target.value }); }}
                  rows={4}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div className="flex justify-end gap-3">
                <button
                  onClick={() => { setShowEmailModal(false); }}
                  className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  {t('common:cancel')}
                </button>
                <button
                  onClick={handleSendEmail}
                  disabled={sendEmailMutation.isPending || !emailForm.recipientEmail}
                  className="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50"
                >
                  {t('common:send')}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
