import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Calendar, Building2, FileText, Package, TrendingUp, CreditCard } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentAttachments } from '../components/DocumentAttachments'
import { PurchaseOrderLandedCostBreakdown } from '../components/PurchaseOrderLandedCostBreakdown'
import { PaymentStatusBadge } from '../components/PaymentStatusBadge'
import { PaymentHistorySection, OutstandingAmountSection } from '../components'
import { useDownloadPdf, usePreviewPdf, usePrintPdf, useSendDocumentEmail } from '../hooks'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { RecordPaymentModal } from '../../../components/organisms/RecordPaymentModal'
import { useCompany } from '../../../hooks/useCompany'
import type { Document } from '../../../types/document'

type ConfirmAction = 'confirm' | 'receive' | null
type ActiveTab = 'related' | 'attachments' | 'landedCosts' | 'payments'

const receiptStatusColors = {
  not_received: 'bg-gray-100 text-gray-800',
  partially_received: 'bg-yellow-100 text-yellow-800',
  fully_received: 'bg-green-100 text-green-800',
}

export function PurchaseOrderDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
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
    queryKey: ['document', 'purchase_order', id],
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/purchase-orders/${id}`)
      return response.data.data
    },
    enabled: id.length > 0,
  })

  // PDF mutations
  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()
  const sendEmailMutation = useSendDocumentEmail()

  // Confirm PO mutation
  const confirmMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/purchase-orders/${id}/confirm`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success(t('documents.messages.confirmed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Receive goods mutation
  const receiveGoodsMutation = useMutation({
    mutationFn: () => apiPost<{ message: string }>(`/purchase-orders/${id}/receive`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', id] })
      void queryClient.invalidateQueries({ queryKey: ['stock-levels'] })
      toast.success(t('documents.messages.goodsReceived'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const isActionPending = confirmMutation.isPending || receiveGoodsMutation.isPending

  // Action handlers
  const handleConfirm = () => {
    confirmMutation.mutate()
    setConfirmAction(null)
  }

  const handleReceiveGoods = () => {
    receiveGoodsMutation.mutate()
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

  const handlePaymentSuccess = () => {
    setShowPaymentModal(false)
    void queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', id] })
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

  if (error || !purchaseOrder) {
    return (
      <div className="py-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{t('common.errorLoadingData')}</p>
        </div>
      </div>
    )
  }

  // Get receipt status from status field
  const receiptStatus = purchaseOrder.status === 'received' ? 'fully_received' :
                       purchaseOrder.status === 'confirmed' ? 'not_received' : 'not_received'

  // Payment computation
  const outstandingAmount = parseFloat(purchaseOrder.outstanding_amount || purchaseOrder.balance_due || '0')
  const total = parseFloat(purchaseOrder.total || '0')
  const amountPaid = parseFloat(purchaseOrder.amount_paid || '0')
  const isPaid = purchaseOrder.payment_status === 'paid' || outstandingAmount === 0
  const canRecordPayment = ['confirmed', 'received'].includes(purchaseOrder.status) && !isPaid && outstandingAmount > 0
  const creditNotesApplied = Math.max(0, total - outstandingAmount - amountPaid)

  return (
    <div className="py-6">
      {/* Header */}
      <div className="mb-6">
        <Link
          to="/purchases/orders"
          className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
        >
          <ArrowLeft className="h-4 w-4 mr-1" />
          {t('purchaseOrders.backToList')}
        </Link>

        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">{purchaseOrder.document_number}</h1>
            <div className="mt-2 flex items-center gap-2">
              <span className="inline-flex items-center rounded-full bg-purple-100 px-3 py-1 text-sm font-medium text-purple-800">
                {t('documents.types.purchase_order')}
              </span>
              <span className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ${
                purchaseOrder.status === 'draft' ? 'bg-gray-100 text-gray-800' :
                purchaseOrder.status === 'confirmed' ? 'bg-blue-100 text-blue-800' :
                purchaseOrder.status === 'received' ? 'bg-green-100 text-green-800' :
                'bg-red-100 text-red-800'
              }`}>
                {t(`documents.statuses.${purchaseOrder.status}`)}
              </span>
              {purchaseOrder.status === 'confirmed' && (
                <span className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ${receiptStatusColors[receiptStatus as keyof typeof receiptStatusColors]}`}>
                  <Package className="h-4 w-4" />
                  {t(`purchaseOrders.receiptStatus.${receiptStatus}`)}
                </span>
              )}
              {['confirmed', 'received'].includes(purchaseOrder.status) && purchaseOrder.payment_status && (
                <>
                  <PaymentStatusBadge status={purchaseOrder.payment_status as any} />
                  {!isPaid && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-orange-100 px-3 py-1 text-sm font-medium text-orange-800">
                      <CreditCard className="h-4 w-4" />
                      {t('purchaseOrders.outstandingAmount')}: {formatCurrency(outstandingAmount, { currency: currentCompany?.currency ?? 'EUR' })}
                    </span>
                  )}
                </>
              )}
            </div>
          </div>

          <DocumentActionBar
            document={purchaseOrder}
            basePath="/purchases/orders"
            isActionPending={isActionPending}
            onConfirm={() => setConfirmAction('confirm')}
            onReceiveGoods={() => setConfirmAction('receive')}
            onRecordPayment={canRecordPayment ? () => setShowPaymentModal(true) : undefined}
            onDownloadPdf={handleDownloadPdf}
            onPreviewPdf={handlePreviewPdf}
            onPrintPdf={handlePrintPdf}
            onSendEmail={() => setShowEmailModal(true)}
            isDownloading={downloadPdfMutation.isPending}
            isPreviewing={previewPdfMutation.isPending}
            isPrinting={printPdfMutation.isPending}
          />
        </div>
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
                {new Date(purchaseOrder.document_date).toLocaleDateString()}
              </dd>
            </div>

            {purchaseOrder.due_date && (
              <div>
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <Calendar className="h-4 w-4" />
                  {t('purchaseOrders.expectedDate')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900">
                  {new Date(purchaseOrder.due_date).toLocaleDateString()}
                </dd>
              </div>
            )}

            <div>
              <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                <Building2 className="h-4 w-4" />
                {t('documents.supplier')}
              </dt>
              <dd className="mt-1 text-sm text-gray-900">
                {purchaseOrder.partner_name || '-'}
              </dd>
            </div>

            {purchaseOrder.notes && (
              <div className="sm:col-span-3">
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <FileText className="h-4 w-4" />
                  {t('documents.notes')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                  {purchaseOrder.notes}
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
                {purchaseOrder.status === 'confirmed' && (
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('purchaseOrders.received')}
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
              {purchaseOrder.lines?.map((line) => (
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
                  {purchaseOrder.status === 'confirmed' && (
                    <td className="px-6 py-4 text-sm text-gray-900 text-right">
                      {parseFloat(line.quantity_received || '0')}
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
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between gap-12">
                <dt className="text-gray-500">{t('documents.subtotal')}</dt>
                <dd className="text-gray-900 font-medium">
                  {formatCurrency(parseFloat(purchaseOrder.subtotal), { currency: currentCompany?.currency ?? 'EUR' })}
                </dd>
              </div>
              {parseFloat(purchaseOrder.tax_amount) > 0 && (
                <div className="flex justify-between gap-12">
                  <dt className="text-gray-500">{t('documents.tax')}</dt>
                  <dd className="text-gray-900 font-medium">
                    {formatCurrency(parseFloat(purchaseOrder.tax_amount), { currency: currentCompany?.currency ?? 'EUR' })}
                  </dd>
                </div>
              )}
              <div className="flex justify-between gap-12 text-base font-bold pt-2 border-t border-gray-200">
                <dt className="text-gray-900">{t('documents.total')}</dt>
                <dd className="text-gray-900">
                  {formatCurrency(parseFloat(purchaseOrder.total), { currency: currentCompany?.currency ?? 'EUR' })}
                </dd>
              </div>
            </dl>
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
            {purchaseOrder.status === 'received' && (
              <button
                onClick={() => setActiveTab('landedCosts')}
                className={`${
                  activeTab === 'landedCosts'
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-1`}
              >
                <TrendingUp className="h-4 w-4" />
                {t('purchaseOrders.landedCosts')}
              </button>
            )}
            {['confirmed', 'received'].includes(purchaseOrder.status) && (
              <button
                onClick={() => setActiveTab('payments')}
                className={`${
                  activeTab === 'payments'
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                } whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm`}
              >
                {t('purchaseOrders.paymentHistory')}
              </button>
            )}
          </nav>
        </div>

        <div className="mt-6">
          {activeTab === 'related' && <RelatedDocumentsTab documentId={purchaseOrder.id} />}
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
                  paymentStatus={purchaseOrder.payment_status as any}
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
        isOpen={confirmAction === 'receive'}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleReceiveGoods}
        title={t('purchaseOrders.receiveGoodsTitle')}
        message={t('purchaseOrders.receiveGoodsMessage')}
        confirmText={t('purchaseOrders.receiveGoods')}
        isLoading={receiveGoodsMutation.isPending}
      />

      {/* Record Payment Modal */}
      {purchaseOrder.partner_id && (
        <RecordPaymentModal
          isOpen={showPaymentModal}
          onClose={() => setShowPaymentModal(false)}
          onSuccess={handlePaymentSuccess}
          prefill={{
            partner_id: purchaseOrder.partner_id,
            partner_name: purchaseOrder.partner_name || '',
            amount: outstandingAmount,
            reference: purchaseOrder.document_number,
            document_id: purchaseOrder.id,
            document_type: 'purchase_order',
          }}
        />
      )}

      {/* Email Modal */}
      {showEmailModal && (
        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-lg max-w-md w-full p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">{t('common.sendEmail')}</h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('common.recipientEmail')}</label>
                <input
                  type="email"
                  value={emailForm.recipientEmail}
                  onChange={(e) => setEmailForm({ ...emailForm, recipientEmail: e.target.value })}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('common.subject')}</label>
                <input
                  type="text"
                  value={emailForm.subject}
                  onChange={(e) => setEmailForm({ ...emailForm, subject: e.target.value })}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('common.message')}</label>
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
