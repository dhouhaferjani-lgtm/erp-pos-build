import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Calendar, Building2, Car, Truck } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { formatQuantity } from '../../../lib/decimal'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { bccomp } from '../../../lib/decimal'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { Modal } from '../../../components/organisms/Modal/Modal'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { CreateReturnNoteForm } from '../components/CreateReturnNoteForm'
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

export function DeliveryNoteDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [showReturnNoteForm, setShowReturnNoteForm] = useState(false)
  const [emailForm, setEmailForm] = useState({
    recipientEmail: '',
    subject: '',
    message: '',
    ccEmails: '',
  })

  const { data: deliveryNote, isLoading, error } = useQuery<Document>({
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
      await queryClient.invalidateQueries({ queryKey: ['document', id] })
      toast.success(t('deliveryNotes.confirmed'))
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
    if (!deliveryNote?.id) return

    try {
      await sendEmailMutation.mutateAsync({
        documentId: deliveryNote.id,
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

  if (error || !deliveryNote) {
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
        <Link to="/inventory/delivery-notes" className={`${colorClasses.textBlue600} ${colorClasses.hoverTextBlue700} flex items-center gap-2 mb-4`}>
          <ArrowLeft className="w-4 h-4" />
          {t('deliveryNotes.backToList')}
        </Link>

        <div className="flex items-start justify-between">
          <div>
            <h1 className={`text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>{deliveryNote.document_number}</h1>
            <div className="mt-2 flex items-center gap-3">
              <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${colorClasses.bgPurple100} ${colorClasses.textPurple800}`}>
                {t('documents.types.delivery_note')}
              </span>
              <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                deliveryNote.status === 'confirmed' ? `${colorClasses.bgGreen100} ${colorClasses.textGreen800}` : `${colorClasses.bgGray100} ${colorClasses.textGray800}`
              }`}>
                {t(`documents.status.${deliveryNote.status}`)}
              </span>
            </div>
          </div>

          <DocumentActionBar
            document={deliveryNote}
            basePath="/inventory/delivery-notes"
            isActionPending={confirmMutation.isPending}
            onConfirm={() => { setConfirmAction('confirm'); }}
            onCreateReturnNote={() => { setShowReturnNoteForm(true); }}
            onRecordPayment={() => navigate(`/treasury/payments/new?delivery_note=${deliveryNote.id}`)}
            onDownloadPdf={() => { downloadPdfMutation.mutate(deliveryNote.id); }}
            onPreviewPdf={() => { previewPdfMutation.mutate(deliveryNote.id); }}
            onPrintPdf={() => { printPdfMutation.mutate(deliveryNote.id); }}
            isDownloading={downloadPdfMutation.isPending}
            isPreviewing={previewPdfMutation.isPending}
            isPrinting={printPdfMutation.isPending}
            onSendEmail={() => {
              setEmailForm({
                ...emailForm,
                recipientEmail: deliveryNote.partner_email || '',
                subject: `${t('deliveryNotes.emailSubject')} ${deliveryNote.document_number}`,
              })
              setShowEmailModal(true)
            }}
          />
        </div>
      </div>

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
              id={deliveryNote.partner_id}
              partnerType="customer"
              label={deliveryNote.partner_name}
            />
          </p>
          {deliveryNote.partner_email && (
            <p className={`text-sm ${colorClasses.textGray600} mt-1`}>{deliveryNote.partner_email}</p>
          )}
        </div>

        <div className="bg-white shadow rounded-lg p-6">
          <h3 className={`text-sm font-medium ${colorClasses.textGray500} mb-4 flex items-center gap-2`}>
            <Calendar className="w-4 h-4" />
            {t('documents.deliveryDate')}
          </h3>
          <p className={`text-lg font-medium ${colorClasses.textGray900}`}>
            {new Date(deliveryNote.document_date).toLocaleDateString()}
          </p>
        </div>

        {deliveryNote.vehicle_context && (
          <div className="bg-white shadow rounded-lg p-6">
            <h3 className={`text-sm font-medium ${colorClasses.textGray500} mb-4 flex items-center gap-2`}>
              <Car className="w-4 h-4" />
              {t('documents.vehicle')}
            </h3>
            <p className={`text-lg font-medium ${colorClasses.textGray900}`}>{deliveryNote.vehicle_context.display}</p>
            {deliveryNote.vehicle_context.mileage && (
              <p className={`text-sm ${colorClasses.textGray600} mt-1`}>
                {/* eslint-disable-next-line local/no-untranslated-literal -- km is an ISO unit symbol */}
                {deliveryNote.vehicle_context.mileage.toLocaleString()} km
              </p>
            )}
          </div>
        )}
      </div>

      {/* Document Lines */}
      <div className="bg-white shadow rounded-lg overflow-hidden mb-6">
        <div className={`px-6 py-4 border-b ${colorClasses.borderGray200} flex items-center gap-2`}>
          <Truck className={`w-5 h-5 ${colorClasses.textGray400}`} />
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
              {(deliveryNote.lines ?? []).map((line) => (
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
                  {formatCurrency(deliveryNote.subtotal || '0', { currency: currentCompany?.currency ?? 'EUR' })}
                </dd>
              </div>
              {bccomp(deliveryNote.tax_amount || '0', '0') > 0 && (
                <div className="flex justify-between gap-12">
                  <dt className={`${colorClasses.textGray500}`}>{t('documents.tax')}</dt>
                  <dd className={`${colorClasses.textGray900} font-medium`}>
                    {formatCurrency(deliveryNote.tax_amount, { currency: currentCompany?.currency ?? 'EUR' })}
                  </dd>
                </div>
              )}
              <div className={`flex justify-between gap-12 text-base font-bold pt-2 border-t ${colorClasses.borderGray200}`}>
                <dt className={`${colorClasses.textGray900}`}>{t('documents.total')}</dt>
                <dd className={`${colorClasses.textGray900}`}>
                  {formatCurrency(deliveryNote.total || '0', { currency: currentCompany?.currency ?? 'EUR' })}
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </div>

      {/* Related Documents Tab */}
      <div className="bg-white shadow rounded-lg p-6">
        <RelatedDocumentsTab documentId={deliveryNote.id} />
      </div>

      {/* Confirm Dialog */}
      <ConfirmDialog
        isOpen={confirmAction === 'confirm'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={() => { confirmMutation.mutate(); }}
        title={t('deliveryNotes.confirmTitle')}
        message={t('deliveryNotes.confirmMessage')}
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

      {/* Create Return Note Modal */}
      {showReturnNoteForm && (
        <Modal
          isOpen={true}
          onClose={() => { setShowReturnNoteForm(false); }}
          title={t('returnNotes.create')}
        >
          <CreateReturnNoteForm
            sourceDocument={deliveryNote as unknown as Parameters<typeof CreateReturnNoteForm>[0]['sourceDocument']}
            sourceType="delivery_note"
            onSuccess={() => {
              setShowReturnNoteForm(false)
              void queryClient.invalidateQueries({ queryKey: ['document', deliveryNote.id] })
            }}
            onCancel={() => { setShowReturnNoteForm(false); }}
          />
        </Modal>
      )}
    </div>
  )
}
