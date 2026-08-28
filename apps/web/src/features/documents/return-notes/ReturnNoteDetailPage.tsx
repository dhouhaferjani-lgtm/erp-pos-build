import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Calendar, Building2, FileText, Car, RotateCcw } from 'lucide-react'
import { api, getErrorMessage } from '../../../lib/api'
import { confirmReturnNote } from '../api/returnNotes'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { formatCurrency } from '../../../lib/format'
import { bccomp, formatQuantity } from '../../../lib/decimal'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { Modal } from '../../../components/organisms/Modal/Modal'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { ReturnNoteMetadata } from '../components/ReturnNoteMetadata'
import { useSendDocumentEmail } from '../hooks/useDocumentEmail'
import { useDownloadPdf, usePreviewPdf, usePrintPdf } from '../hooks/useDocumentPdf'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { useCompany } from '../../../hooks/useCompany'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type ConfirmAction = 'confirm' | null

export function ReturnNoteDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [emailForm, setEmailForm] = useState({
    recipientEmail: '',
    subject: '',
    message: '',
    ccEmails: '',
  })

  const { data: returnNote, isLoading, error } = useQuery<Document>({
    queryKey: tenantScopedKey(['document', id]),
    queryFn: async () => {
      const response = await api.get(`/documents/${id}`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && !!id,
  })

  const confirmMutation = useMutation({
    mutationFn: async () => {
      return confirmReturnNote(id)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['document', id] })
      toast.success(t('returnNotes.confirmed'))
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
    if (!returnNote?.id) return

    try {
      await sendEmailMutation.mutateAsync({
        documentId: returnNote.id,
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
          <div className={`animate-spin rounded-full h-12 w-12 border-b-2 ${colorClasses.borderBlue600} mx-auto`}></div>
          <p className={`mt-4 ${colorClasses.textGray600}`}>{t('common:loading')}</p>
        </div>
      </div>
    )
  }

  if (error || !returnNote) {
    return (
      <div className="py-6">
        <div className={`${colorClasses.bgRed50} border ${colorClasses.borderRed200} rounded-lg p-6 text-center`}>
          <p className={`${colorClasses.textRed800}`}>{t('common:error')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="py-6">
      {/* Header */}
      <div className="mb-6">
        <Link to="/inventory/return-notes" className={`${colorClasses.textBlue600} ${colorClasses.hoverTextBlue700} flex items-center gap-2 mb-4`}>
          <ArrowLeft className="w-4 h-4" />
          {t('returnNotes.backToList')}
        </Link>

        <div className="flex items-start justify-between">
          <div>
            <h1 className={`text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>{returnNote.document_number ?? t('sales:documents.draftNumberPlaceholder')}</h1>
            <div className="mt-2 flex items-center gap-3">
              <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${colorClasses.bgOrange100} ${colorClasses.textOrange800}`}>
                {t('documents.types.return_note')}
              </span>
              <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                returnNote.status === 'confirmed' ? `${colorClasses.bgGreen100} ${colorClasses.textGreen800}` : `${colorClasses.bgGray100} ${colorClasses.textGray800}`
              }`}>
                {t(`documents.status.${returnNote.status}`)}
              </span>
            </div>
          </div>

          <DocumentActionBar
            document={returnNote}
            basePath="/inventory/return-notes"
            isActionPending={confirmMutation.isPending}
            onConfirm={() => { setConfirmAction('confirm'); }}
            onDownloadPdf={() => { downloadPdfMutation.mutate(returnNote.id); }}
            onPreviewPdf={() => { previewPdfMutation.mutate(returnNote.id); }}
            onPrintPdf={() => { printPdfMutation.mutate(returnNote.id); }}
            isDownloading={downloadPdfMutation.isPending}
            isPreviewing={previewPdfMutation.isPending}
            isPrinting={printPdfMutation.isPending}
            onSendEmail={() => {
              setEmailForm({
                ...emailForm,
                recipientEmail: returnNote.partner_email || '',
                subject: `${t('returnNotes.emailSubject')} ${returnNote.document_number}`,
              })
              setShowEmailModal(true)
            }}
          />
        </div>
      </div>

      {/* Source Document Link */}
      {returnNote.source_document_id && (
        <div className={`mb-6 ${colorClasses.bgBlue50} border ${colorClasses.borderBlue200} rounded-lg p-4`}>
          <div className={`flex items-center gap-2 text-sm ${colorClasses.textBlue800}`}>
            <FileText className="w-4 h-4" />
            <span>{t('returnNotes.sourceDocument')}:</span>
            <Link
              to={`/${returnNote.source_document_type === 'invoice' ? 'sales/invoices' : 'inventory/delivery-notes'}/${returnNote.source_document_id}`}
              className="font-medium hover:underline"
            >
              {returnNote.source_document_number}
            </Link>
          </div>
        </div>
      )}

      {/* Return Note Metadata */}
      {returnNote.payload && (
        <ReturnNoteMetadata
          metadata={returnNote.payload as Parameters<typeof ReturnNoteMetadata>[0]['metadata']}
        />
      )}

      {/* Document Info */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div className="bg-white shadow rounded-lg p-6">
          <h3 className={`text-sm font-medium ${colorClasses.textGray500} mb-4 flex items-center gap-2`}>
            <Building2 className="w-4 h-4" />
            {t('documents.customer')}
          </h3>
          <p className={`text-lg font-medium ${colorClasses.textGray900}`}>{returnNote.partner_name}</p>
          {returnNote.partner_email && (
            <p className={`text-sm ${colorClasses.textGray600} mt-1`}>{returnNote.partner_email}</p>
          )}
        </div>

        <div className="bg-white shadow rounded-lg p-6">
          <h3 className={`text-sm font-medium ${colorClasses.textGray500} mb-4 flex items-center gap-2`}>
            <Calendar className="w-4 h-4" />
            {t('documents.returnDate')}
          </h3>
          <p className={`text-lg font-medium ${colorClasses.textGray900}`}>
            {new Date(returnNote.document_date).toLocaleDateString()}
          </p>
        </div>

        {returnNote.vehicle_context && (
          <div className="bg-white shadow rounded-lg p-6">
            <h3 className={`text-sm font-medium ${colorClasses.textGray500} mb-4 flex items-center gap-2`}>
              <Car className="w-4 h-4" />
              {t('documents.vehicle')}
            </h3>
            <p className={`text-lg font-medium ${colorClasses.textGray900}`}>{returnNote.vehicle_context.display}</p>
            {returnNote.vehicle_context.mileage && (
              <p className={`text-sm ${colorClasses.textGray600} mt-1`}>
                {/* eslint-disable-next-line local/no-untranslated-literal -- km is an ISO unit symbol */}
                {returnNote.vehicle_context.mileage.toLocaleString()} km
              </p>
            )}
          </div>
        )}
      </div>

      {/* Document Lines */}
      <div className="bg-white shadow rounded-lg overflow-hidden mb-6">
        <div className={`px-6 py-4 border-b ${colorClasses.borderGray200} flex items-center gap-2`}>
          <RotateCcw className={`w-5 h-5 ${colorClasses.textGray400}`} />
          <h2 className={`text-lg font-medium ${colorClasses.textGray900}`}>{t('returnNotes.returnedItems')}</h2>
        </div>

        <div className="overflow-x-auto">
          <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
            <thead className={`${colorClasses.bgGray50}`}>
              <tr>
                <th className={`px-6 py-3 text-left text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                  {t('documents.description')}
                </th>
                <th className={`px-6 py-3 text-right text-xs font-medium ${colorClasses.textGray500} uppercase tracking-wider`}>
                  {t('returnNotes.returnedQty')}
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
              {(returnNote.lines ?? []).map((line) => (
                <tr key={line.id}>
                  <td className="px-6 py-4">
                    <div className={`text-sm font-medium ${colorClasses.textGray900}`}>{line.description}</div>
                    {line.notes && (
                      <div className={`text-sm ${colorClasses.textGray500} mt-1`}>{line.notes}</div>
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
        <div className={`px-6 py-4 ${colorClasses.bgGray50} border-t ${colorClasses.borderGray200}`}>
          <div className="flex justify-end">
            <dl className="space-y-2 text-sm w-64">
              <div className="flex justify-between gap-12">
                <dt className={`${colorClasses.textGray500}`}>{t('documents.subtotal')}</dt>
                <dd className={`${colorClasses.textGray900} font-medium`}>
                  {formatCurrency(returnNote.subtotal || '0', { currency: currentCompany?.currency ?? 'EUR' })}
                </dd>
              </div>
              {bccomp(returnNote.tax_amount || '0', '0') > 0 && (
                <div className="flex justify-between gap-12">
                  <dt className={`${colorClasses.textGray500}`}>{t('documents.tax')}</dt>
                  <dd className={`${colorClasses.textGray900} font-medium`}>
                    {formatCurrency(returnNote.tax_amount, { currency: currentCompany?.currency ?? 'EUR' })}
                  </dd>
                </div>
              )}
              <div className={`flex justify-between gap-12 text-base font-bold pt-2 border-t ${colorClasses.borderGray200}`}>
                <dt className={`${colorClasses.textGray900}`}>{t('returnNotes.returnValue')}</dt>
                <dd className={`${colorClasses.textGray900}`}>
                  {formatCurrency(returnNote.total || '0', { currency: currentCompany?.currency ?? 'EUR' })}
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </div>

      {/* Related Documents Tab */}
      <div className="bg-white shadow rounded-lg p-6">
        <RelatedDocumentsTab documentId={returnNote.id} />
      </div>

      {/* Confirm Dialog */}
      <ConfirmDialog
        isOpen={confirmAction === 'confirm'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={() => { confirmMutation.mutate(); }}
        title={t('returnNotes.confirmTitle')}
        message={t('returnNotes.confirmMessage')}
        confirmText={t('common:confirm')}
        isLoading={confirmMutation.isPending}
      />

      {/* Email Modal */}
      <Modal
        isOpen={showEmailModal}
        onClose={() => { setShowEmailModal(false); }}
        title={t('common:email.title')}
      >
        <div className="space-y-4">
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-1`}>
              {t('common:email.recipientEmail')}
            </label>
            <input
              type="email"
              value={emailForm.recipientEmail}
              onChange={(e) => { setEmailForm({ ...emailForm, recipientEmail: e.target.value }); }}
              className={`w-full px-3 py-2 border ${colorClasses.borderGray300} rounded-md`}
            />
          </div>
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-1`}>
              {t('common:email.subject')}
            </label>
            <input
              type="text"
              value={emailForm.subject}
              onChange={(e) => { setEmailForm({ ...emailForm, subject: e.target.value }); }}
              className={`w-full px-3 py-2 border ${colorClasses.borderGray300} rounded-md`}
            />
          </div>
          <div>
            <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-1`}>
              {t('common:email.message', 'Message')}
            </label>
            <textarea
              value={emailForm.message}
              onChange={(e) => { setEmailForm({ ...emailForm, message: e.target.value }); }}
              rows={4}
              className={`w-full px-3 py-2 border ${colorClasses.borderGray300} rounded-md`}
            />
          </div>
          <div className="flex justify-end gap-3">
            <button
              onClick={() => { setShowEmailModal(false); }}
              className={`px-4 py-2 border ${colorClasses.borderGray300} rounded-md text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`}
            >
              {t('common:cancel')}
            </button>
            <button
              onClick={handleSendEmail}
              disabled={sendEmailMutation.isPending || !emailForm.recipientEmail}
              className={`px-4 py-2 ${colorClasses.bgBlue600} text-white rounded-md text-sm font-medium ${colorClasses.hoverBgBlue700} disabled:opacity-50`}
            >
              {sendEmailMutation.isPending ? t('common:email.sending') : t('common:send')}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
