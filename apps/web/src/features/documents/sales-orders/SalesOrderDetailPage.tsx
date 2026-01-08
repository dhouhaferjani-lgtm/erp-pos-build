import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Edit, Calendar, Building2, FileText, Check, ArrowRight, Printer, Send, Download, Eye, Car, Package, Truck } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentTotals } from '../components/DocumentTotals'
import { useDownloadPdf, usePreviewPdf, usePrintPdf, useSendDocumentEmail } from '../hooks'
import { useCompany } from '../../../hooks/useCompany'
import type { Document } from '../../../types/document'

type ConfirmAction = 'confirm' | 'convertToInvoice' | 'convertToDelivery' | null

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
        toast.success(t('common.emailSent'))
      },
    })
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
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{t('common.errorLoadingData')}</p>
        </div>
      </div>
    )
  }

  const canEdit = order.status === 'draft'
  const canConfirm = order.status === 'draft'
  const canConvert = order.status === 'confirmed'

  // Get delivery status from payload
  const deliveryStatus = order.payload?.delivery_status || 'not_delivered'

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="mb-6">
        <Link
          to="/sales/orders"
          className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
        >
          <ArrowLeft className="h-4 w-4 mr-1" />
          {t('orders.backToList')}
        </Link>

        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">{order.document_number}</h1>
            <div className="mt-2 flex items-center gap-2">
              <span className="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-sm font-medium text-blue-800">
                {t('documents.types.sales_order')}
              </span>
              <span className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ${
                order.status === 'draft' ? 'bg-gray-100 text-gray-800' :
                order.status === 'confirmed' ? 'bg-blue-100 text-blue-800' :
                'bg-red-100 text-red-800'
              }`}>
                {t(`documents.statuses.${order.status}`)}
              </span>
              {order.status === 'confirmed' && (
                <span className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ${deliveryStatusColors[deliveryStatus as keyof typeof deliveryStatusColors]}`}>
                  <Truck className="h-4 w-4" />
                  {t(`orders.deliveryStatus.${deliveryStatus}`)}
                </span>
              )}
            </div>
          </div>

          <div className="flex items-center gap-2">
            {canEdit && (
              <Link
                to={`/sales/orders/${order.id}/edit`}
                className="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
              >
                <Edit className="h-4 w-4" />
                {t('common:edit')}
              </Link>
            )}

            {/* PDF Actions */}
            <button
              onClick={handleDownloadPdf}
              disabled={downloadPdfMutation.isPending}
              className="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              <Download className="h-4 w-4" />
              {t('common:download')}
            </button>

            <button
              onClick={handlePreviewPdf}
              disabled={previewPdfMutation.isPending}
              className="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              <Eye className="h-4 w-4" />
              {t('common:preview')}
            </button>

            <button
              onClick={handlePrintPdf}
              disabled={printPdfMutation.isPending}
              className="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
            >
              <Printer className="h-4 w-4" />
              {t('common:print')}
            </button>

            <button
              onClick={() => setShowEmailModal(true)}
              className="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
            >
              <Send className="h-4 w-4" />
              {t('common:send')}
            </button>

            {canConfirm && (
              <button
                onClick={() => setConfirmAction('confirm')}
                disabled={isActionPending}
                className="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50"
              >
                <Check className="h-4 w-4" />
                {t('documents.confirm')}
              </button>
            )}

            {canConvert && (
              <>
                <button
                  onClick={() => setConfirmAction('convertToDelivery')}
                  disabled={isActionPending}
                  className="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 disabled:opacity-50"
                >
                  <Package className="h-4 w-4" />
                  {t('orders.convertToDelivery')}
                </button>
                <button
                  onClick={() => setConfirmAction('convertToInvoice')}
                  disabled={isActionPending}
                  className="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 disabled:opacity-50"
                >
                  <ArrowRight className="h-4 w-4" />
                  {t('orders.convertToInvoice')}
                </button>
              </>
            )}
          </div>
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
                {new Date(order.document_date).toLocaleDateString()}
              </dd>
            </div>

            <div>
              <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                <Building2 className="h-4 w-4" />
                {t('documents.customer')}
              </dt>
              <dd className="mt-1 text-sm text-gray-900">
                {order.partner?.name || '-'}
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
            <button className="border-blue-500 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
              {t('documents.relatedDocuments')}
            </button>
            <button className="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
              {t('documents.attachments')}
            </button>
          </nav>
        </div>

        <div className="mt-6">
          <RelatedDocumentsTab documentId={order.id} />
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
