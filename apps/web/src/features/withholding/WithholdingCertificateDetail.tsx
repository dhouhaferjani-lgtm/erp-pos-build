import { Link, useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Download,
  FileText,
  Send,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Hash,
  Building2,
  User,
  Calendar,
  Link as LinkIcon
} from 'lucide-react'
import { toast } from 'sonner'
import {
  useWithholdingCertificate,
  useIssueWithholdingCertificate,
  useVoidWithholdingCertificate,
  useSubmitCertificateToTEJ,
  useDeleteWithholdingCertificate,
} from './hooks/useWithholding'
import {
  downloadCertificatePDF,
  downloadCertificateTEJXML,
} from './api/withholdingApi'
import type { CertificateStatus, WithholdingDirection } from './types'
import { useCurrency } from '@/hooks/useCurrency'

export function WithholdingCertificateDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { t } = useTranslation(['withholding', 'common'])
  const { decimals } = useCurrency()

  const { data: certificate, isLoading } = useWithholdingCertificate(id!)
  const issueMutation = useIssueWithholdingCertificate()
  const voidMutation = useVoidWithholdingCertificate()
  const submitToTEJMutation = useSubmitCertificateToTEJ()
  const deleteMutation = useDeleteWithholdingCertificate()

  const handleIssue = async () => {
    if (!window.confirm(t('messages.confirmIssue'))) return

    try {
      await issueMutation.mutateAsync(id!)
      toast.success(t('messages.certificateIssued'))
    } catch (error) {
      // Error handled by mutation onError
    }
  }

  const handleVoid = async () => {
    const reason = window.prompt(t('messages.confirmVoid'))
    if (!reason) return

    try {
      await voidMutation.mutateAsync({ id: id!, request: { reason } })
      toast.success(t('messages.certificateVoided'))
    } catch (error) {
      // Error handled by mutation onError
    }
  }

  const handleSubmitToTEJ = async () => {
    const tejReference = window.prompt(t('details.tejReference'))
    if (!tejReference) return

    try {
      await submitToTEJMutation.mutateAsync({ id: id!, request: { tej_reference: tejReference } })
      toast.success(t('messages.tejSubmitted'))
    } catch (error) {
      // Error handled by mutation onError
    }
  }

  const handleDelete = async () => {
    if (!window.confirm(t('messages.confirmDelete'))) return

    try {
      await deleteMutation.mutateAsync(id!)
      toast.success(t('messages.certificateDeleted'))
      navigate('/treasury/withholding-certificates')
    } catch (error) {
      // Error handled by mutation onError
    }
  }

  const handleDownloadPDF = () => {
    const url = downloadCertificatePDF(id!)
    window.open(url, '_blank')
    toast.success(t('messages.downloadStarted'))
  }

  const handleDownloadTEJXML = () => {
    const url = downloadCertificateTEJXML(id!)
    window.open(url, '_blank')
    toast.success(t('messages.downloadStarted'))
  }

  const getStatusBadge = (status: CertificateStatus) => {
    const styles = {
      draft: 'bg-gray-100 text-gray-800',
      issued: 'bg-green-100 text-green-800',
      submitted: 'bg-blue-100 text-blue-800',
      voided: 'bg-red-100 text-red-800',
    }

    const icons = {
      draft: <FileText className="h-3 w-3" />,
      issued: <CheckCircle2 className="h-3 w-3" />,
      submitted: <Send className="h-3 w-3" />,
      voided: <XCircle className="h-3 w-3" />,
    }

    return (
      <span className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ${styles[status]}`}>
        {icons[status]}
        {t(`status.${status}`)}
      </span>
    )
  }

  const getDirectionBadge = (direction: WithholdingDirection) => {
    const styles = {
      purchase: 'bg-purple-100 text-purple-800 border-purple-200',
      sales: 'bg-orange-100 text-orange-800 border-orange-200',
    }

    return (
      <span className={`inline-flex items-center gap-1 rounded-lg border px-3 py-1 text-sm font-medium ${styles[direction]}`}>
        {t(`direction.${direction}`)}
      </span>
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-gray-500">{t('common:loading')}</div>
      </div>
    )
  }

  if (!certificate) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-center">
          <AlertCircle className="mx-auto h-12 w-12 text-red-500" />
          <p className="mt-2 text-sm text-gray-600">
            {t('common:notFound')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/treasury/withholding-certificates"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {certificate.certificate_number}
            </h1>
            <div className="mt-1 flex items-center gap-2">
              {getDirectionBadge(certificate.direction)}
              {getStatusBadge(certificate.status)}
            </div>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-2">
          {certificate.status === 'draft' && (
            <button
              type="button"
              onClick={() => { void handleDelete() }}
              className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors"
            >
              <XCircle className="h-4 w-4" />
              {t('common:delete')}
            </button>
          )}

          {certificate.can_be_issued && (
            <button
              type="button"
              onClick={() => { void handleIssue() }}
              className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 transition-colors"
            >
              <CheckCircle2 className="h-4 w-4" />
              {t('certificates.actions.issue')}
            </button>
          )}

          {certificate.status !== 'draft' && (
            <button
              type="button"
              onClick={handleDownloadPDF}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
            >
              <FileText className="h-4 w-4" />
              {t('certificates.actions.downloadPDF')}
            </button>
          )}

          {certificate.status === 'issued' && (
            <>
              <button
                type="button"
                onClick={handleDownloadTEJXML}
                className="inline-flex items-center gap-2 rounded-lg border border-purple-300 bg-white px-4 py-2 text-sm font-medium text-purple-700 hover:bg-purple-50 transition-colors"
              >
                <Download className="h-4 w-4" />
                {t('certificates.actions.downloadTEJ')}
              </button>
              <button
                type="button"
                onClick={() => { void handleSubmitToTEJ() }}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
              >
                <Send className="h-4 w-4" />
                {t('certificates.actions.submitTEJ')}
              </button>
            </>
          )}

          {certificate.can_be_voided && (
            <button
              type="button"
              onClick={() => { void handleVoid() }}
              className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors"
            >
              <XCircle className="h-4 w-4" />
              {t('certificates.actions.void')}
            </button>
          )}
        </div>
      </div>

      {/* Certificate Information */}
      <div className="grid gap-6 md:grid-cols-2">
        {/* Basic Info */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
            <FileText className="h-5 w-5" />
            {t('details.certificateInfo')}
          </h2>
          <dl className="space-y-3">
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('certificates.number')}</dt>
              <dd className="mt-1 text-sm font-mono text-gray-900">{certificate.certificate_number}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('certificates.year')}</dt>
              <dd className="mt-1 text-sm text-gray-900">{certificate.year}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('certificates.direction')}</dt>
              <dd className="mt-1">{getDirectionBadge(certificate.direction)}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('certificates.status')}</dt>
              <dd className="mt-1">{getStatusBadge(certificate.status)}</dd>
            </div>
            {certificate.issued_at && (
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('certificates.issuedAt')}</dt>
                <dd className="mt-1 flex items-center gap-2 text-sm text-gray-900">
                  <Calendar className="h-4 w-4 text-gray-400" />
                  {new Date(certificate.issued_at).toLocaleString()}
                </dd>
              </div>
            )}
            {certificate.issuer && (
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('common:issuedBy')}</dt>
                <dd className="mt-1 flex items-center gap-2 text-sm text-gray-900">
                  <User className="h-4 w-4 text-gray-400" />
                  {certificate.issuer.name}
                </dd>
              </div>
            )}
          </dl>
        </div>

        {/* Partner Info */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
            <Building2 className="h-5 w-5" />
            {t('details.partnerInfo')}
          </h2>
          <dl className="space-y-3">
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('common:name')}</dt>
              <dd className="mt-1 text-sm text-gray-900">{certificate.partner?.name ?? '-'}</dd>
            </div>
            {certificate.partner?.vat_number && (
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('common:vatNumber')}</dt>
                <dd className="mt-1 text-sm font-mono text-gray-900">{certificate.partner.vat_number}</dd>
              </div>
            )}
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('details.glAccount')}</dt>
              <dd className="mt-1 text-sm font-mono text-gray-900">{certificate.gl_account_code}</dd>
            </div>
          </dl>
        </div>
      </div>

      {/* Amount Breakdown */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-gray-900">
          {t('details.amountBreakdown')}
        </h2>
        <div className="space-y-3">
          <div className="flex items-center justify-between border-b border-gray-200 pb-2">
            <span className="text-sm text-gray-600">{t('certificates.grossAmount')}</span>
            <span className="text-lg font-mono font-semibold text-gray-900">
              {parseFloat(certificate.gross_amount).toFixed(decimals)} {certificate.currency}
            </span>
          </div>
          <div className="flex items-center justify-between border-b border-gray-200 pb-2">
            <span className="text-sm text-gray-600">
              {t('certificates.rate')} ({certificate.rate_percentage}%)
            </span>
            <span className="text-lg font-mono font-semibold text-red-600">
              - {parseFloat(certificate.withholding_amount).toFixed(decimals)} {certificate.currency}
            </span>
          </div>
          <div className="flex items-center justify-between pt-2">
            <span className="text-base font-semibold text-gray-900">{t('certificates.netAmount')}</span>
            <span className="text-2xl font-mono font-bold text-gray-900">
              {parseFloat(certificate.net_amount).toFixed(decimals)} {certificate.currency}
            </span>
          </div>
        </div>
      </div>

      {/* Rule Applied or Manual Override */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-gray-900">
          {certificate.is_manual_override ? t('details.manualOverride') : t('details.ruleApplied')}
        </h2>
        {certificate.rule ? (
          <dl className="space-y-3">
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('rules.code')}</dt>
              <dd className="mt-1 text-sm font-mono text-gray-900">{certificate.rule.code}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('rules.name')}</dt>
              <dd className="mt-1 text-sm text-gray-900">{certificate.rule.name}</dd>
            </div>
          </dl>
        ) : certificate.override_reason ? (
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('details.overrideReason')}</dt>
            <dd className="mt-1 text-sm text-gray-900">{certificate.override_reason}</dd>
          </div>
        ) : (
          <p className="text-sm text-gray-500">{t('common:noData')}</p>
        )}
      </div>

      {/* TEJ Submission Info */}
      {certificate.is_submitted_to_tej && (
        <div className="rounded-lg border border-blue-200 bg-blue-50 p-6">
          <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-blue-900">
            <Send className="h-5 w-5" />
            {t('details.tejReference')}
          </h2>
          <dl className="space-y-3">
            <div>
              <dt className="text-sm font-medium text-blue-700">{t('details.tejReference')}</dt>
              <dd className="mt-1 text-sm font-mono text-blue-900">{certificate.tej_reference}</dd>
            </div>
            {certificate.tej_submitted_at && (
              <div>
                <dt className="text-sm font-medium text-blue-700">{t('details.tejSubmittedAt')}</dt>
                <dd className="mt-1 text-sm text-blue-900">
                  {new Date(certificate.tej_submitted_at).toLocaleString()}
                </dd>
              </div>
            )}
          </dl>
        </div>
      )}

      {/* Hash Chain Info */}
      {certificate.hash && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
            <Hash className="h-5 w-5" />
            {t('details.hashChain')}
          </h2>
          <dl className="space-y-3">
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('details.sequence')}</dt>
              <dd className="mt-1 text-sm font-mono text-gray-900">#{certificate.chain_sequence}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">{t('details.hash')}</dt>
              <dd className="mt-1 break-all text-xs font-mono text-gray-900">{certificate.hash}</dd>
            </div>
            {certificate.previous_hash && (
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('details.previousHash')}</dt>
                <dd className="mt-1 break-all text-xs font-mono text-gray-900">{certificate.previous_hash}</dd>
              </div>
            )}
          </dl>
        </div>
      )}

      {/* Linked Documents */}
      {(certificate.document_id || certificate.payment_id) && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
            <LinkIcon className="h-5 w-5" />
            {t('common:linkedDocuments')}
          </h2>
          <div className="space-y-2">
            {certificate.document_id && (
              <Link
                to={`/sales/invoices/${certificate.document_id}`}
                className="block text-sm text-blue-600 hover:text-blue-800 hover:underline"
              >
                {t('common:viewDocument')} →
              </Link>
            )}
            {certificate.payment_id && (
              <Link
                to={`/treasury/payments/${certificate.payment_id}`}
                className="block text-sm text-blue-600 hover:text-blue-800 hover:underline"
              >
                {t('common:viewPayment')} →
              </Link>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
