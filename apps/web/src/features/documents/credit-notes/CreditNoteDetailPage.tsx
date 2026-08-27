import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Calendar, Building2, Car, FileText, Lock, MinusCircle } from 'lucide-react'
import { api, getErrorMessage } from '../../../lib/api'
import { confirmCreditNote, postCreditNote } from '../api/creditNotes'
import { formatCurrency } from '../../../lib/format'
import { formatQuantity } from '../../../lib/decimal'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { Button } from '../../../components/atoms/Button/Button'
import { EntityLink } from '../../../components/molecules/EntityLink'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { Modal } from '../../../components/organisms/Modal/Modal'
import { RelatedDocumentsTab } from '../components/RelatedDocumentsTab'
import { DocumentTotals } from '../components/DocumentTotals'
import { ProformaBanner } from '../components/ProformaBanner'
import { useSendDocumentEmail } from '../hooks/useDocumentEmail'
import { useDownloadPdf, usePreviewPdf, usePrintPdf } from '../hooks/useDocumentPdf'
import { DocumentActionBar } from '../components/DocumentActionBar'
import { useCompany } from '../../../hooks/useCompany'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'
import { PageHeader } from '@/components/molecules/PageHeader'
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
      return confirmCreditNote(id)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['document', id] })
      toast.success(t('creditNotes.confirmed'))
      setConfirmAction(null)
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      return postCreditNote(id)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['document', id] })
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

  /**
   * C-F0w / SPEC §2.4 — the SERVER's proforma predicate (`ProformaOutputPolicy`,
   * keyed on the fiscal seal), the same one the PDF uses. Never re-derived from
   * `status`: `isPosted` above is true for a `posted` credit note the chain never
   * sealed, and that document is an estimate, not a claim.
   *
   * Fiscal gate r1 F-3: `!== false`, not `=== true`. Every real API response sets
   * this field explicitly (`DocumentData::$is_proforma` is a non-nullable `bool`),
   * so behaviour on a genuine payload is unchanged; but the field stays optional on
   * this hand-maintained type (dozens of fixtures construct it without proforma in
   * mind), and an omitted field must degrade toward "print less" (treated as a
   * proforma) rather than toward "claim a seal" on a document nothing has sealed.
   */
  const isProforma = creditNote.is_proforma !== false
  const proformaLineAmounts = new Map(
    (creditNote.proforma?.lines ?? []).map((line) => [line.line_id, line] as const)
  )

  /**
   * The figure the items table prints for one line: the NET one on a definitive
   * credit note, the server's TAX-INCLUSIVE one on a proforma, and NOTHING when
   * the projection does not cover the line — never a net figure under a gross
   * total, which is a VAT breakdown written as a subtraction.
   */
  const displayLineAmount = (
    lineId: string,
    net: string,
    field: 'unit_price' | 'line_total'
  ): string | null => {
    const currency = currentCompany?.currency ?? 'EUR'
    if (!isProforma) {
      return formatCurrency(net, { currency })
    }
    const gross = proformaLineAmounts.get(lineId)

    return gross === undefined ? null : formatCurrency(gross[field], { currency })
  }

  return (
    <div className="py-6">
      {/* Header */}
      <PageHeader
        className="mb-2"
        title={creditNote.document_number ?? t('sales:documents.draftNumberPlaceholder')}
        breadcrumb={
          <Link to="/sales/credit-notes" className={`${colorClasses.textBlue600} ${colorClasses.hoverTextBlue700} inline-flex items-center gap-2`}>
            <ArrowLeft className="w-4 h-4" />
            {t('creditNotes.backToList')}
          </Link>
        }
        actions={
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
        }
      />
      <div className="mb-6 flex items-center gap-3">
        <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${colorClasses.bgRed100} ${colorClasses.textRed800}`}>
          {t('documents.types.credit_note')}
        </span>
        {/*
          * No status chip and no SEALED chip on a proforma (C-F0w): a `posted`
          * credit note the chain never sealed wearing either one contradicts the
          * banner below it, which is the only claim this page makes about it.
          */}
        {!isProforma && (
          <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
            creditNote.status === 'posted' ? `${colorClasses.bgGreen100} ${colorClasses.textGreen800}` :
            creditNote.status === 'confirmed' ? `${colorClasses.bgBlue100} ${colorClasses.textBlue800}` :
            `${colorClasses.bgGray100} ${colorClasses.textGray800}`
          }`}>
            {t(`documents.status.${creditNote.status}`)}
          </span>
        )}
        {isPosted && !isProforma && (
          <span className={`inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium ${colorClasses.bgPurple100} ${colorClasses.textPurple800}`}>
            <Lock className="w-3 h-3" />
            {t('documents.sealed')}
          </span>
        )}
      </div>

      {isProforma && <ProformaBanner className="mb-6" />}

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
                    {formatQuantity(line.quantity, getQuantityDecimals(line))}
                  </td>
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right`}>
                    {displayLineAmount(line.id, line.unit_price, 'unit_price')}
                  </td>
                  <td className={`px-6 py-4 text-sm ${colorClasses.textGray900} text-right font-medium`}>
                    {displayLineAmount(line.id, line.line_total, 'line_total')}
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
                isProforma={isProforma}
                proformaTotals={creditNote.proforma ?? null}
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
