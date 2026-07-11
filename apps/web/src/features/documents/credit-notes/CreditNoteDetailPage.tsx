import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Calendar, Building2, Car, FileText, Lock, MinusCircle } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { formatCurrency, formatQuantity } from '../../../lib/format'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { Button } from '../../../components/atoms/Button/Button'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { Modal } from '../../../components/organisms/Modal/Modal'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentTotals } from '../components/DocumentTotals'
import { useSendDocumentEmail } from '../hooks/useDocumentEmail'
import { useDownloadPdf, usePreviewPdf, usePrintPdf } from '../hooks/useDocumentPdf'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { useCompany } from '../../../hooks/useCompany'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

type ConfirmAction = 'confirm' | 'post' | null

export function CreditNoteDetailPage() {
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

  const { data: creditNote, isLoading, error } = useQuery<Document>({
    queryKey: tenantScopedKey(['document', id]),
    queryFn: async () => {
      const response = await api.get(`/documents/${id}`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && !!id,
  })

  const confirmMutation = useMutation({
    mutationFn: async () => {
      return apiPost(`/documents/${id}/confirm`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['document', id]) })
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
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['document', id]) })
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
          <div className={`animate-spin rounded-full h-12 w-12 border-b-2 ${colorClasses.borderBlue600} mx-auto`}></div>
          <p className={`mt-4 ${colorClasses.textGray600}`}>{t('common:loading')}</p>
        </div>
      </div>
    )
  }

  if (error || !creditNote) {
    return (
      <div className="py-6">
        <div className={`${colorClasses.bgRed50} border ${colorClasses.borderRed200} rounded-lg p-6 text-center`}>
          <p className={`${colorClasses.textRed800}`}>{t('common:error')}</p>
        </div>
      </div>
    )
  }

  const isPosted = creditNote.status === 'posted'

  return (
    <div className="py-6">
      {/* Header */}
      <div className="mb-6">
        <Link to="/sales/credit-notes" className={`${colorClasses.textBlue600} ${colorClasses.hoverTextBlue700} flex items-center gap-2 mb-4`}>
          <ArrowLeft className="w-4 h-4" />
          {t('creditNotes.backToList')}
        </Link>

        <div className="flex items-start justify-between">
          <div>
            <h1 className={`text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>{creditNote.document_number}</h1>
            <div className="mt-2 flex items-center gap-3">
              <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${colorClasses.bgRed100} ${colorClasses.textRed800}`}>
                {t('documents.types.credit_note')}
              </span>
              <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                creditNote.status === 'posted' ? `${colorClasses.bgGreen100} ${colorClasses.textGreen800}` :
                creditNote.status === 'confirmed' ? `${colorClasses.bgBlue100} ${colorClasses.textBlue800}` :
                `${colorClasses.bgGray100} ${colorClasses.textGray800}`
              }`}>
                {t(`documents.status.${creditNote.status}`)}
              </span>
              {isPosted && (
                <span className={`inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium ${colorClasses.bgPurple100} ${colorClasses.textPurple800}`}>
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
        <div className={`mb-6 ${colorClasses.bgBlue50} border ${colorClasses.borderBlue200} rounded-lg p-4`}>
          <div className={`flex items-center gap-2 text-sm ${colorClasses.textBlue800}`}>
            <FileText className="w-4 h-4" />
            <span>{t('creditNotes.sourceInvoice')}:</span>
            <EntityLink
              type="document"
              id={creditNote.source_document_id}
              documentType="invoice"
              label={creditNote.source_document_number}
              className="font-medium"
            />
          </div>
        </div>
      )}

      {/* Document Info */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div className="bg-white shadow rounded-lg p-6">
          <h3 className={`text-sm font-medium ${colorClasses.textGray500} mb-4 flex items-center gap-2`}>
            <Building2 className="w-4 h-4" />
            {t('documents.customer')}
          </h3>
          <p className={`text-lg font-medium ${colorClasses.textGray900}`}>
            <EntityLink
              type="partner"
              id={creditNote.partner_id}
              partnerType="customer"
              label={creditNote.partner_name}
            />
          </p>
          {creditNote.partner_email && (
            <p className={`text-sm ${colorClasses.textGray600} mt-1`}>{creditNote.partner_email}</p>
          )}
        </div>

        <div className="bg-white shadow rounded-lg p-6">
          <h3 className={`text-sm font-medium ${colorClasses.textGray500} mb-4 flex items-center gap-2`}>
            <Calendar className="w-4 h-4" />
            {t('documents.date')}
          </h3>
          <p className={`text-lg font-medium ${colorClasses.textGray900}`}>
            {new Date(creditNote.document_date).toLocaleDateString()}
          </p>
        </div>

        {creditNote.vehicle_context && (
          <div className="bg-white shadow rounded-lg p-6">
            <h3 className={`text-sm font-medium ${colorClasses.textGray500} mb-4 flex items-center gap-2`}>
              <Car className="w-4 h-4" />
              {t('documents.vehicle')}
            </h3>
            <p className={`text-lg font-medium ${colorClasses.textGray900}`}>{creditNote.vehicle_context.display}</p>
            {creditNote.vehicle_context.mileage && (
              <p className={`text-sm ${colorClasses.textGray600} mt-1`}>
                {/* eslint-disable-next-line local/no-untranslated-literal -- km is an ISO unit symbol */}
                {creditNote.vehicle_context.mileage.toLocaleString()} km
              </p>
            )}
          </div>
        )}
      </div>

      {/* Document Lines */}
      <div className="bg-white shadow rounded-lg overflow-hidden mb-6">
        <div className={`px-6 py-4 border-b ${colorClasses.borderGray200} flex items-center gap-2`}>
          <MinusCircle className={`w-5 h-5 ${colorClasses.textGray400}`} />
          <h2 className={`text-lg font-medium ${colorClasses.textGray900}`}>{t('documents.items')}</h2>
        </div>

        <div className="overflow-x-auto">
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
              {(creditNote.lines ?? []).map((line) => (
                <tr key={line.id}>
                  <td className="px-6 py-4">
                    <div className={`text-sm font-medium ${colorClasses.textGray900}`}>
                      <EntityLink
                        type="product"
                        id={line.product_id}
                        label={line.description}
                      />
                    </div>
                    {line.notes && (
                      <div className={`text-sm ${colorClasses.textGray500} mt-1`}>{line.notes}</div>
                    )}
                  </td>
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right`}>
                    {formatQuantity(line.quantity)}
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
              {sendEmailMutation.isPending ? t('common:email.sending') : t('common:send')}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
