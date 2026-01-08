import { useState, useMemo } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Edit, Calendar, Building2, FileText, Check, ArrowRight, Printer, Send, Download, Eye, Car, AlertTriangle } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentTotals } from '../components/DocumentTotals'
import { useDownloadPdf, usePreviewPdf, usePrintPdf, useSendDocumentEmail } from '../hooks'
import { useRelatedDocuments } from '../hooks/useRelatedDocuments'
import { useCompany } from '../../../hooks/useCompany'
import type { Document } from '../../../types/document'

type ConfirmAction = 'confirm' | 'convert' | null

interface QuoteExpiryInfo {
  status: 'valid' | 'expired' | 'expiring_soon'
  message: string
  days?: number
}

export function QuoteDetailPage() {
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

  // Fetch quote
  const { data: quote, isLoading, error } = useQuery({
    queryKey: ['document', 'quote', id],
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/quotes/${id}`)
      return response.data.data
    },
    enabled: id.length > 0,
  })

  // Fetch related documents to check if already converted
  const { data: relatedDocs } = useRelatedDocuments(id)

  // Calculate quote expiry status
  const quoteExpiryInfo = useMemo((): QuoteExpiryInfo | null => {
    if (!quote || quote.status === 'cancelled') {
      return null
    }

    if (!quote.valid_until) {
      return null
    }

    const validUntil = new Date(quote.valid_until)
    const today = new Date()
    today.setHours(0, 0, 0, 0)

    const diffTime = validUntil.getTime() - today.getTime()
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))

    if (diffDays < 0) {
      return { status: 'expired', message: 'expired' }
    } else if (diffDays <= 7) {
      return { status: 'expiring_soon', message: 'expiresIn', days: diffDays }
    } else {
      return { status: 'valid', message: 'valid' }
    }
  }, [quote])

  // PDF mutations
  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()
  const sendEmailMutation = useSendDocumentEmail()

  // Confirm quote mutation
  const confirmMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/quotes/${id}/confirm`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', 'quote', id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success(t('documents.messages.confirmed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Convert to order mutation
  const convertMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/quotes/${id}/convert-to-order`, {}),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['document', 'quote', id] })
      if (data?.id) {
        void navigate(`/sales/orders/${data.id}`)
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || t('documents.conversionError'))
      void queryClient.invalidateQueries({ queryKey: ['document', 'quote', id] })
    },
  })

  const isActionPending = confirmMutation.isPending || convertMutation.isPending

  // Action handlers
  const handleConfirm = () => {
    confirmMutation.mutate()
    setConfirmAction(null)
  }

  const handleConvert = () => {
    convertMutation.mutate()
    setConfirmAction(null)
  }

  const handleDownloadPdf = () => {
    if (!quote) return
    downloadPdfMutation.mutate(quote.id)
  }

  const handlePreviewPdf = () => {
    if (!quote) return
    previewPdfMutation.mutate(quote.id)
  }

  const handlePrintPdf = () => {
    if (!quote) return
    printPdfMutation.mutate(quote.id)
  }

  const handleSendEmail = () => {
    if (!quote) return

    const { recipientEmail, subject, message, ccEmails } = emailForm

    sendEmailMutation.mutate({
      documentId: quote.id,
      recipientEmail,
      subject,
      message,
      ccEmails: ccEmails.split(',').map(e => e.trim()).filter(e => e),
    }, {
      onSuccess: () => {
        setShowEmailModal(false)
        setEmailForm({ recipientEmail: '', subject: '', message: '', ccEmails: '' })
        toast.success(t('email.success'))
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

  if (error || !quote) {
    return (
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{t('errors.loadingFailed')}</p>
        </div>
      </div>
    )
  }

  const canEdit = quote.status === 'draft'
  const canConfirm = quote.status === 'draft'
  // Only allow conversion if confirmed and no sales orders exist yet
  const canConvert = quote.status === 'confirmed' && (relatedDocs?.descendants.length === 0 || !relatedDocs)

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      {/* Header */}
      <div className="mb-6">
        <Link
          to="/sales/quotes"
          className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
        >
          <ArrowLeft className="h-4 w-4 mr-1" />
          {t('quotes.backToList')}
        </Link>

        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">{quote.document_number}</h1>
            <div className="mt-2 flex items-center gap-2">
              <span className="inline-flex items-center rounded-full bg-yellow-100 px-3 py-1 text-sm font-medium text-yellow-800">
                {t('documents.types.quote')}
              </span>
              <span className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ${
                quote.status === 'draft' ? 'bg-gray-100 text-gray-800' :
                quote.status === 'confirmed' ? 'bg-blue-100 text-blue-800' :
                'bg-red-100 text-red-800'
              }`}>
                {t(`documents.statuses.${quote.status}`)}
              </span>
              {quoteExpiryInfo && (
                <span className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ${
                  quoteExpiryInfo.status === 'expired'
                    ? 'bg-red-100 text-red-800'
                    : quoteExpiryInfo.status === 'expiring_soon'
                    ? 'bg-orange-100 text-orange-800'
                    : 'bg-green-100 text-green-800'
                }`}>
                  {quoteExpiryInfo.status === 'expired' && <AlertTriangle className="h-4 w-4" />}
                  {quoteExpiryInfo.message === 'expiresIn'
                    ? t('quotes.expiry.expiresIn', { days: quoteExpiryInfo.days })
                    : t(`quotes.expiry.${quoteExpiryInfo.message}`)}
                </span>
              )}
            </div>
          </div>

          <div className="flex items-center gap-2">
            {canEdit && (
              <Link
                to={`/sales/quotes/${quote.id}/edit`}
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
              <button
                onClick={() => setConfirmAction('convert')}
                disabled={isActionPending}
                className="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 disabled:opacity-50"
              >
                <ArrowRight className="h-4 w-4" />
                {t('quotes.convertToOrder')}
              </button>
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
                {new Date(quote.document_date).toLocaleDateString()}
              </dd>
            </div>

            {quote.valid_until && (
              <div>
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <Calendar className="h-4 w-4" />
                  {t('quotes.validUntil')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900">
                  {new Date(quote.valid_until).toLocaleDateString()}
                </dd>
              </div>
            )}

            <div>
              <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                <Building2 className="h-4 w-4" />
                {t('documents.customer')}
              </dt>
              <dd className="mt-1 text-sm text-gray-900">
                {quote.partner_name || '-'}
              </dd>
            </div>

            {quote.vehicleContext && (
              <div>
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <Car className="h-4 w-4" />
                  {t('documents.vehicle')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900">
                  {quote.vehicleContext.vehicle_snapshot?.make} {quote.vehicleContext.vehicle_snapshot?.model}
                  {quote.vehicleContext.vehicle_snapshot?.license_plate &&
                    ` (${quote.vehicleContext.vehicle_snapshot.license_plate})`
                  }
                </dd>
              </div>
            )}

            {quote.notes && (
              <div className="sm:col-span-3">
                <dt className="text-sm font-medium text-gray-500 flex items-center gap-1">
                  <FileText className="h-4 w-4" />
                  {t('documents.notes')}
                </dt>
                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                  {quote.notes}
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
              {quote.lines?.map((line) => (
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
                documentId={quote.id}
                documentType="quote"
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
          <RelatedDocumentsTab documentId={quote.id} />
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
        isOpen={confirmAction === 'convert'}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleConvert}
        title={t('quotes.convertToOrderTitle')}
        message={t('quotes.convertToOrderMessage')}
        confirmText={t('common:convert')}
        isLoading={convertMutation.isPending}
      />

      {/* Email Modal */}
      {showEmailModal && (
        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-lg max-w-md w-full p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">{t('email.title')}</h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('email.recipientEmail')}</label>
                <input
                  type="email"
                  value={emailForm.recipientEmail}
                  onChange={(e) => setEmailForm({ ...emailForm, recipientEmail: e.target.value })}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('email.subject')}</label>
                <input
                  type="text"
                  value={emailForm.subject}
                  onChange={(e) => setEmailForm({ ...emailForm, subject: e.target.value })}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">{t('email.message')}</label>
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
