import { useState, useMemo } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Calendar, Building2, FileText, Car } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { formatCurrency } from '../../../lib/format'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentAttachments } from '../components/DocumentAttachments'
import { DocumentTotals } from '../components/DocumentTotals'
import { DocumentHeader } from '../components/DocumentHeader'
import { DocumentLines } from '../components/DocumentLines'
import type { QuoteExpiryInfo } from '../components/DocumentHeader'
import { useSendDocumentEmail } from '../hooks/useDocumentEmail'
import { useDownloadPdf, usePreviewPdf, usePrintPdf } from '../hooks/useDocumentPdf'
import { useRevertDocument } from '../hooks/useRevertDocument'
import { useRelatedDocuments } from '../hooks/useRelatedDocuments'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { Modal } from '../../../components/organisms/Modal'
import { Button } from '../../../components/atoms/Button/Button'
import { Input } from '../../../components/atoms/Input/Input'
import { Textarea } from '../../../components/atoms/Textarea/Textarea'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { tokens } from '../../../lib/designTokens'
import { useCompany } from '../../../hooks/useCompany'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'

type ConfirmAction = 'confirm' | 'convert' | 'revert' | null
type ActiveTab = 'related' | 'attachments'

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

export function QuoteDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  const [activeTab, setActiveTab] = useState<ActiveTab>('related')
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [emailForm, setEmailForm] = useState({
    recipientEmail: '',
    subject: '',
    message: '',
    ccEmails: '',
  })

  // Fetch quote
  const { data: quote, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['document', 'quote', id]),
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/quotes/${id}`)
      return response.data.data
    },
    enabled: id.length > 0 && tenantId !== null && companyId !== null,
  })

  // Fetch related documents to check if already converted
  const { data: relatedDocs } = useRelatedDocuments(id)

  // Calculate quote expiry status (maps to DocumentHeader's QuoteExpiryInfo)
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
      return { status: 'expired', days: 0, message: 'expired' }
    } else if (diffDays <= 7) {
      return { status: 'warning', days: diffDays, message: 'expiresIn' }
    } else {
      return null
    }
  }, [quote])

  // PDF mutations
  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()
  const sendEmailMutation = useSendDocumentEmail()
  const revertMutation = useRevertDocument(id, 'quote')

  // Confirm quote mutation
  const confirmMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/quotes/${id}/confirm`, {}),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['document', 'quote', id] }),
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

  // Convert to order mutation
  const convertMutation = useMutation({
    mutationFn: () => apiPost<Document>(`/quotes/${id}/convert-to-order`, {}),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: ['document', 'quote', id] }),
      ])
      if (data?.id) {
        void navigate(`/sales/orders/${data.id}`)
      }
    },
    onError: async (error: Error) => {
      toast.error(error.message || t('documents.conversionError'))
      await queryClient.invalidateQueries({ queryKey: ['document', 'quote', id] })
    },
  })

  const isActionPending = confirmMutation.isPending || convertMutation.isPending || revertMutation.isPending

  // Action handlers
  const handleConfirm = () => {
    confirmMutation.mutate()
    setConfirmAction(null)
  }

  const handleConvert = () => {
    convertMutation.mutate()
    setConfirmAction(null)
  }

  const handleRevert = () => {
    revertMutation.mutate(undefined, {
      onSuccess: () => {
        toast.success(t('documents.messages.revertedToDraft'))
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
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
        toast.success(t('common:email.success'))
      },
    })
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

  if (error || !quote) {
    return (
      <div className="py-6">
        <div className={`${colorClasses.bgRed50} border ${colorClasses.borderRed200} rounded-lg p-4`}>
          <p className={`${colorClasses.textRed800}`}>{t('common:errorMessages.generic')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="py-6">
      {/* Header */}
      <div className="mb-6">
        <DocumentHeader
          document={quote}
          backPath="/sales/quotes"
          quoteExpiryInfo={quoteExpiryInfo}
          actions={
            <DocumentActionBar
              document={quote}
              basePath="/sales/quotes"
              isActionPending={isActionPending}
              onConfirm={() => { setConfirmAction('confirm'); }}
              {...((!relatedDocs || relatedDocs.descendants.length === 0) ? { onConvert: () => { setConfirmAction('convert'); } } : {})}
              onRevert={() => { setConfirmAction('revert'); }}
              onDownloadPdf={handleDownloadPdf}
              onPreviewPdf={handlePreviewPdf}
              onPrintPdf={handlePrintPdf}
              onSendEmail={() => { setShowEmailModal(true); }}
              isDownloading={downloadPdfMutation.isPending}
              isPreviewing={previewPdfMutation.isPending}
              isPrinting={printPdfMutation.isPending}
            />
          }
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
                {new Date(quote.document_date).toLocaleDateString()}
              </dd>
            </div>

            {quote.valid_until && (
              <div>
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <Calendar className="h-4 w-4" />
                  {t('quotes.validUntil')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                  {new Date(quote.valid_until).toLocaleDateString()}
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
                  id={quote.partner_id}
                  partnerType="customer"
                  label={quote.partner_name || '-'}
                />
              </dd>
            </div>

            {quote.vehicleContext && (
              <div>
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <Car className="h-4 w-4" />
                  {t('documents.vehicle')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900}`}>
                  {quote.vehicleContext.vehicle_snapshot?.make} {quote.vehicleContext.vehicle_snapshot?.model}
                  {quote.vehicleContext.vehicle_snapshot?.license_plate &&
                    ` (${quote.vehicleContext.vehicle_snapshot.license_plate})`
                  }
                </dd>
              </div>
            )}

            {quote.notes && (
              <div className="sm:col-span-3">
                <dt className={`text-sm font-medium ${colorClasses.textGray500} flex items-center gap-1`}>
                  <FileText className="h-4 w-4" />
                  {t('documents.notes')}
                </dt>
                <dd className={`mt-1 text-sm ${colorClasses.textGray900} whitespace-pre-wrap`}>
                  {quote.notes}
                </dd>
              </div>
            )}
          </div>
        </div>

        <div className={`border-t ${colorClasses.borderGray200}`}>
          <DocumentLines
            lines={quote.lines ?? []}
            formatAmount={(amount) => formatCurrency(amount, { currency: currentCompany?.currency ?? 'EUR' })}
            className="border-0"
          />
        </div>

        {/* Totals */}
        <div className={`${colorClasses.bgGray50} px-4 py-5 sm:px-6`}>
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
          </nav>
        </div>

        <div className="mt-6">
          {activeTab === 'related' && <RelatedDocumentsTab documentId={quote.id} />}
          {activeTab === 'attachments' && <DocumentAttachments documentId={quote.id} />}
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
        isOpen={confirmAction === 'convert'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleConvert}
        title={t('quotes.convertToOrderTitle')}
        message={t('quotes.convertToOrderMessage')}
        confirmText={t('common:convert')}
        isLoading={convertMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'revert'}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleRevert}
        title={t('documents.revertToDraftTitle')}
        message={t('documents.revertToDraftMessage')}
        confirmText={t('documents.revertToDraft')}
        isLoading={revertMutation.isPending}
      />

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
