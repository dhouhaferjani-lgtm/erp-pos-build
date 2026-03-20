import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Calendar, Building2, Car, FileText, Lock, MinusCircle } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { Button } from '../../../components/atoms/Button/Button'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { Modal } from '../../../components/organisms'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentTotals } from '../components/DocumentTotals'
import { useDownloadPdf, usePreviewPdf, usePrintPdf, useSendDocumentEmail } from '../hooks'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { useCompany } from '../../../hooks/useCompany'
import type { Document } from '../../../types/document'

type ConfirmAction = 'confirm' | 'post' | null

export function CreditNoteDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
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

  const { data: creditNote, isLoading, error } = useQuery<Document>({
    queryKey: ['document', id],
    queryFn: async () => {
      const response = await api.get(`/documents/${id}`)
      return response.data.data
    },
    enabled: !!id,
  })

  const confirmMutation = useMutation({
    mutationFn: async () => {
      return apiPost(`/documents/${id}/confirm`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['document', id] })
      toast.success(t('creditNotes.confirmed'))
      setConfirmAction(null)
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      return apiPost(`/documents/${id}/post`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['document', id] })
      toast.success(t('creditNotes.posted'))
      setConfirmAction(null)
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()
  const sendEmailMutation = useSendDocumentEmail()

  const handleSendEmail = async () => {
    if (!creditNote?.id) return

    try {
      await sendEmailMutation.mutateAsync({
        documentId: creditNote.id,
        recipientEmail: emailForm.recipientEmail,
        subject: emailForm.subject,
        message: emailForm.message,
        ccEmails: emailForm.ccEmails.split(',').map((e) => e.trim()).filter(Boolean),
      })
      setShowEmailModal(false)
      setEmailForm({ recipientEmail: '', subject: '', message: '', ccEmails: '' })
    } catch (error) {
      // Error handled by mutation
    }
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

  if (error || !creditNote) {
    return (
      <div className="py-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
          <p className="text-red-800">{t('common.error')}</p>
        </div>
      </div>
    )
  }

  const isPosted = creditNote.status === 'posted'

  return (
    <div className="py-6">
      {/* Header */}
      <div className="mb-6">
        <Link to="/sales/credit-notes" className="text-blue-600 hover:text-blue-700 flex items-center gap-2 mb-4">
          <ArrowLeft className="w-4 h-4" />
          {t('creditNotes.backToList')}
        </Link>

        <div className="flex items-start justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">{creditNote.document_number}</h1>
            <div className="mt-2 flex items-center gap-3">
              <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                {t('documents.types.credit_note')}
              </span>
              <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                creditNote.status === 'posted' ? 'bg-green-100 text-green-800' :
                creditNote.status === 'confirmed' ? 'bg-blue-100 text-blue-800' :
                'bg-gray-100 text-gray-800'
              }`}>
                {t(`documents.status.${creditNote.status}`)}
              </span>
              {isPosted && (
                <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
                  <Lock className="w-3 h-3" />
                  {t('documents.sealed')}
                </span>
              )}
            </div>
          </div>

          <DocumentActionBar
            document={creditNote}
            basePath="/sales/credit-notes"
            isActionPending={confirmMutation.isPending || postMutation.isPending}
            onConfirm={() => { setConfirmAction('confirm'); }}
            onPost={() => { setConfirmAction('post'); }}
            onDownloadPdf={() => { downloadPdfMutation.mutate(creditNote.id); }}
            onPreviewPdf={() => { previewPdfMutation.mutate(creditNote.id); }}
            onPrintPdf={() => { printPdfMutation.mutate(creditNote.id); }}
            isDownloading={downloadPdfMutation.isPending}
            isPreviewing={previewPdfMutation.isPending}
            isPrinting={printPdfMutation.isPending}
            onSendEmail={() => {
              setEmailForm({
                ...emailForm,
                recipientEmail: creditNote.partner_email || '',
                subject: `${t('creditNotes.emailSubject')} ${creditNote.document_number}`,
              })
              setShowEmailModal(true)
            }}
          />
        </div>
      </div>

      {/* Source Invoice Link */}
      {creditNote.source_document_id && (
        <div className="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div className="flex items-center gap-2 text-sm text-blue-800">
            <FileText className="w-4 h-4" />
            <span>{t('creditNotes.sourceInvoice')}:</span>
            <Link
              to={`/sales/invoices/${creditNote.source_document_id}`}
              className="font-medium hover:underline"
            >
              {creditNote.source_document_number}
            </Link>
          </div>
        </div>
      )}

      {/* Document Info */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div className="bg-white shadow rounded-lg p-6">
          <h3 className="text-sm font-medium text-gray-500 mb-4 flex items-center gap-2">
            <Building2 className="w-4 h-4" />
            {t('documents.customer')}
          </h3>
          <p className="text-lg font-medium text-gray-900">{creditNote.partner_name}</p>
          {creditNote.partner_email && (
            <p className="text-sm text-gray-600 mt-1">{creditNote.partner_email}</p>
          )}
        </div>

        <div className="bg-white shadow rounded-lg p-6">
          <h3 className="text-sm font-medium text-gray-500 mb-4 flex items-center gap-2">
            <Calendar className="w-4 h-4" />
            {t('documents.date')}
          </h3>
          <p className="text-lg font-medium text-gray-900">
            {new Date(creditNote.document_date).toLocaleDateString()}
          </p>
        </div>

        {creditNote.vehicle_context && (
          <div className="bg-white shadow rounded-lg p-6">
            <h3 className="text-sm font-medium text-gray-500 mb-4 flex items-center gap-2">
              <Car className="w-4 h-4" />
              {t('documents.vehicle')}
            </h3>
            <p className="text-lg font-medium text-gray-900">{creditNote.vehicle_context.display}</p>
            {creditNote.vehicle_context.mileage && (
              <p className="text-sm text-gray-600 mt-1">
                {creditNote.vehicle_context.mileage.toLocaleString()} km
              </p>
            )}
          </div>
        )}
      </div>

      {/* Document Lines */}
      <div className="bg-white shadow rounded-lg overflow-hidden mb-6">
        <div className="px-6 py-4 border-b border-gray-200 flex items-center gap-2">
          <MinusCircle className="w-5 h-5 text-gray-400" />
          <h2 className="text-lg font-medium text-gray-900">{t('documents.items')}</h2>
        </div>

        <div className="overflow-x-auto">
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
              {(creditNote.lines ?? []).map((line) => (
                <tr key={line.id}>
                  <td className="px-6 py-4">
                    <div className="text-sm font-medium text-gray-900">{line.description}</div>
                    {line.notes && (
                      <div className="text-sm text-gray-500 mt-1">{line.notes}</div>
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
        <div className="px-6 py-4 bg-gray-50 border-t border-gray-200">
          <div className="flex justify-end">
            <div className="w-full max-w-md">
              <DocumentTotals
                documentId={creditNote.id}
                documentType="credit_note"
                currency={currentCompany?.currency ?? 'EUR'}
              />
            </div>
          </div>
        </div>
      </div>

      {/* Related Documents Tab */}
      <div className="bg-white shadow rounded-lg p-6">
        <RelatedDocumentsTab documentId={creditNote.id} />
      </div>

      {/* Confirm Dialog */}
      <ConfirmDialog
        isOpen={confirmAction === 'confirm'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={() => { confirmMutation.mutate(); }}
        title={t('creditNotes.confirmTitle')}
        message={t('creditNotes.confirmMessage')}
        confirmText={t('common:confirm')}
        isLoading={confirmMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'post'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={() => { postMutation.mutate(); }}
        title={t('creditNotes.postTitle')}
        message={t('creditNotes.postMessage')}
        confirmText={t('invoices.post')}
        variant="warning"
        isLoading={postMutation.isPending}
      />

      {/* Email Modal */}
      <Modal
        isOpen={showEmailModal}
        onClose={() => { setShowEmailModal(false); }}
        title={t('common.sendEmail')}
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {t('common.recipient')}
            </label>
            <input
              type="email"
              value={emailForm.recipientEmail}
              onChange={(e) => { setEmailForm({ ...emailForm, recipientEmail: e.target.value }); }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {t('common.subject')}
            </label>
            <input
              type="text"
              value={emailForm.subject}
              onChange={(e) => { setEmailForm({ ...emailForm, subject: e.target.value }); }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {t('common.message')}
            </label>
            <textarea
              value={emailForm.message}
              onChange={(e) => { setEmailForm({ ...emailForm, message: e.target.value }); }}
              rows={4}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div className="flex justify-end gap-3">
            <Button
              variant="secondary"
              onClick={() => { setShowEmailModal(false); }}
            >
              {t('common:cancel')}
            </Button>
            <Button
              variant="primary"
              onClick={handleSendEmail}
              disabled={sendEmailMutation.isPending || !emailForm.recipientEmail}
            >
              {sendEmailMutation.isPending ? t('common.sending') : t('common:send')}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
