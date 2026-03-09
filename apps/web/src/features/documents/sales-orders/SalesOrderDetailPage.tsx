import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Calendar, Building2, FileText, Car, Truck } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentAttachments } from '../components/DocumentAttachments'
import { DocumentTotals } from '../components/DocumentTotals'
import { DocumentHeader } from '../components/DocumentHeader'
import { DocumentOutstandingCallout } from '../components/DocumentOutstandingCallout'
import { PaymentStatusBadge } from '../components/PaymentStatusBadge'
import { PaymentHistorySection, OutstandingAmountSection } from '../components'
import { useDownloadPdf, usePreviewPdf, usePrintPdf, useSendDocumentEmail } from '../hooks'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { RecordPaymentModal } from '../../../components/organisms/RecordPaymentModal'
import { useCompany } from '../../../hooks/useCompany'
import type { Document } from '../../../types/document'
import type { PaymentStatus } from '../components/PaymentStatusBadge'

type ConfirmAction = 'confirm' | 'convertToInvoice' | 'convertToDelivery' | null
type ActiveTab = 'related' | 'attachments' | 'payments'

const deliveryStatusColors = {
  not_delivered: 'bg-gray-100 text-gray-800',
  partially_delivered: 'bg-yellow-100 text-yellow-800',
  fully_delivered: 'bg-green-100 text-green-800',
}

export function SalesOrderDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [showPaymentModal, setShowPaymentModal] = useState(false)
  const [activeTab, setActiveTab] = useState<ActiveTab>('related')
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [emailForm, setEmailForm] = useState({
    recipientEmail: '',
    subject: '',
    message: '',
    ccEmails: '',
  })

  // Fetch sales order
  const { data: order, isLoading, error } = useQuery({
    queryKey: ['document', 'sales_order', id],
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/orders/${id}`)
      return response.data.data
    },
    enabled: id.length > 0,
  })

  // PDF mutations
  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()
  const sendEmailMutation = useSendDocumentEmail()

  // Confirm order mutation
  const confirmMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/orders/${id}/confirm`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success(t('documents.messages.confirmed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Convert to invoice mutation
  const convertToInvoiceMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/orders/${id}/convert-to-invoice`, {}),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] })
      if (data?.id) {
        void navigate(`/sales/invoices/${data.id}`)
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || t('documents.conversionError'))
      void queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] })
    },
  })

  // Convert to delivery note mutation
  const convertToDeliveryMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/orders/${id}/convert-to-delivery`, {}),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] })
      if (data?.id) {
        void navigate(`/inventory/delivery-notes/${data.id}`)
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || t('documents.conversionError'))
      void queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] })
    },
  })

  const isActionPending = confirmMutation.isPending || convertToInvoiceMutation.isPending || convertToDeliveryMutation.isPending

  // Action handlers
  const handleConfirm = () => {
    confirmMutation.mutate()
    setConfirmAction(null)
  }

  const handleConvertToInvoice = () => {
    convertToInvoiceMutation.mutate()
    setConfirmAction(null)
  }

  const handleConvertToDelivery = () => {
    convertToDeliveryMutation.mutate()
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

  const handlePaymentSuccess = () => {
    setShowPaymentModal(false)
    void queryClient.invalidateQueries({ queryKey: ['document', 'sales_order', id] })
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

  if (error || !order) {
    return (
      <div className="py-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{t('common.errorLoadingData')}</p>
        </div>
      </div>
    )
  }

  // Get delivery status from payload
  const deliveryStatus = order.payload?.delivery_status || 'not_delivered'

  // Payment computation
  const outstandingAmount = parseFloat(order.outstanding_amount || order.balance_due || '0')
  const total = parseFloat(order.total || '0')
  const amountPaid = parseFloat(order.amount_paid || '0')
  const isPaid = order.payment_status === 'paid' || outstandingAmount === 0
  const canRecordPayment = order.status === 'confirmed' && !isPaid && outstandingAmount > 0
  const creditNotesApplied = Math.max(0, total - outstandingAmount - amountPaid)

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
              onConfirm={() => setConfirmAction('confirm')}
              onConvert={() => setConfirmAction('convertToInvoice')}
              onConvertToDelivery={() => setConfirmAction('convertToDelivery')}
              onDownloadPdf={handleDownloadPdf}
              onPreviewPdf={handlePreviewPdf}
              onPrintPdf={handlePrintPdf}
              onSendEmail={() => setShowEmailModal(true)}
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
                {...(canRecordPayment ? { onRecordPayment: () => setShowPaymentModal(true) } : {})}
              />
            ) : undefined
          }
        >
          {order.status === 'confirmed' && (
            <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${deliveryStatusColors[deliveryStatus as keyof typeof deliveryStatusColors]}`}>
              <Truck className="h-3 w-3" />
              {t(`orders.deliveryStatus.${deliveryStatus}`)}
            </span>
          )}
          {order.status === 'confirmed' && order.payment_status && (
            <PaymentStatusBadge status={order.payment_status as 'unpaid' | 'partially_paid' | 'in_payment' | 'paid' | 'overpaid'} />
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
                {new Date(order.document_date).toLocaleDateString()}
              </dd>
            </div>

            <div>
              <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                <Building2 className="h-4 w-4" />
                {t('documents.customer')}
              </dt>
              <dd className="mt-1 text-sm text-gray-900">
                {order.partner_name || '-'}
              </dd>
            </div>

            {order.vehicleContext && (
              <div>
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <Car className="h-4 w-4" />
                  {t('documents.vehicle')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900">
                  {order.vehicleContext.vehicle_snapshot?.make} {order.vehicleContext.vehicle_snapshot?.model}
                  {order.vehicleContext.vehicle_snapshot?.license_plate &&
                    ` (${order.vehicleContext.vehicle_snapshot.license_plate})`
                  }
                </dd>
              </div>
            )}

            {order.notes && (
              <div className="sm:col-span-3">
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <FileText className="h-4 w-4" />
                  {t('documents.notes')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                  {order.notes}
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
                {order.status === 'confirmed' && (
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('orders.delivered')}
                  </th>
                )}
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('documents.unitPrice')}
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('documents.total')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {order.lines?.map((line) => (
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
                  {order.status === 'confirmed' && (
                    <td className="px-6 py-4 text-sm text-gray-900 text-right">
                      {parseFloat(line.quantity_delivered || '0')}
                    </td>
                  )}
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
        <div className="border-b border-gray-200">
          <nav className="-mb-px flex space-x-8">
            <button
              onClick={() => setActiveTab('related')}
              className={`${
                activeTab === 'related'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
            >
              {t('documents.relatedDocuments')}
            </button>
            <button
              onClick={() => setActiveTab('attachments')}
              className={`${
                activeTab === 'attachments'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
            >
              {t('documents.attachments')}
            </button>
            {order.status === 'confirmed' && (
              <button
                onClick={() => setActiveTab('payments')}
                className={`${
                  activeTab === 'payments'
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
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
                  paymentStatus={order.payment_status as PaymentStatus}
                  currency={currentCompany?.currency ?? 'EUR'}
                  onRecordPayment={canRecordPayment ? () => setShowPaymentModal(true) : undefined}
                />
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Confirmation Dialogs */}
      <ConfirmDialog
        isOpen={confirmAction === 'confirm'}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleConfirm}
        title={t('documents.confirmTitle')}
        message={t('documents.confirmMessage')}
        confirmText={t('common:confirm')}
        isLoading={confirmMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'convertToInvoice'}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleConvertToInvoice}
        title={t('orders.convertToInvoiceTitle')}
        message={t('orders.convertToInvoiceMessage')}
        confirmText={t('common:convert')}
        isLoading={convertToInvoiceMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'convertToDelivery'}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleConvertToDelivery}
        title={t('orders.convertToDeliveryTitle')}
        message={t('orders.convertToDeliveryMessage')}
        confirmText={t('common:convert')}
        isLoading={convertToDeliveryMutation.isPending}
      />

      {/* Record Payment Modal */}
      {order.partner_id && (
        <RecordPaymentModal
          isOpen={showPaymentModal}
          onClose={() => setShowPaymentModal(false)}
          onSuccess={handlePaymentSuccess}
          prefill={{
            partner_id: order.partner_id,
            partner_name: order.partner_name || '',
            amount: outstandingAmount,
            reference: order.document_number,
            document_id: order.id,
            document_type: 'sales_order',
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
                  onChange={(e) => setEmailForm({ ...emailForm, recipientEmail: e.target.value })}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('common:email.subject')}</label>
                <input
                  type="text"
                  value={emailForm.subject}
                  onChange={(e) => setEmailForm({ ...emailForm, subject: e.target.value })}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('common:email.message')}</label>
                <textarea
                  value={emailForm.message}
                  onChange={(e) => setEmailForm({ ...emailForm, message: e.target.value })}
                  rows={4}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div className="flex justify-end gap-3">
                <button
                  onClick={() => setShowEmailModal(false)}
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
