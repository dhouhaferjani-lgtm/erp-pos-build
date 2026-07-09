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
  Link as LinkIcon,
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
import { formatNumber, formatPercent } from '@/lib/format'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { Button } from '@/components/atoms/Button'
import {
  StatusBadge,
  statusTone,
  type StatusTone,
} from '@/components/atoms/StatusBadge'
import { PageHeader } from '@/components/molecules/PageHeader'

/**
 * Certificate-status tone overrides for the shared StatusBadge.
 * `issued` maps to success, `submitted` to info, `voided` to danger;
 * `draft` is covered by the built-in `statusTone` (pending) map.
 */
const statusToneOverrides: Record<CertificateStatus, StatusTone> = {
  draft: 'pending',
  issued: 'success',
  submitted: 'info',
  voided: 'danger',
}

const directionTone: Record<WithholdingDirection, StatusTone> = {
  purchase: 'info',
  sales: 'warning',
}

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
    } catch {
      // Error handled by mutation onError
    }
  }

  const handleVoid = async () => {
    const reason = window.prompt(t('messages.confirmVoid'))
    if (!reason) return

    try {
      await voidMutation.mutateAsync({ id: id!, request: { reason } })
      toast.success(t('messages.certificateVoided'))
    } catch {
      // Error handled by mutation onError
    }
  }

  const handleSubmitToTEJ = async () => {
    const tejReference = window.prompt(t('details.tejReference'))
    if (!tejReference) return

    try {
      await submitToTEJMutation.mutateAsync({ id: id!, request: { tej_reference: tejReference } })
      toast.success(t('messages.tejSubmitted'))
    } catch {
      // Error handled by mutation onError
    }
  }

  const handleDelete = async () => {
    if (!window.confirm(t('messages.confirmDelete'))) return

    try {
      await deleteMutation.mutateAsync(id!)
      toast.success(t('messages.certificateDeleted'))
      navigate('/treasury/withholding-certificates')
    } catch {
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

  const renderStatusBadge = (status: CertificateStatus) => (
    <StatusBadge tone={statusTone(status, statusToneOverrides)}>
      {t(`status.${status}`)}
    </StatusBadge>
  )

  const renderDirectionBadge = (direction: WithholdingDirection) => (
    <StatusBadge tone={directionTone[direction]}>
      {t(`direction.${direction}`)}
    </StatusBadge>
  )

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className={textColors.tertiary}>{t('common:loading')}</div>
      </div>
    )
  }

  if (!certificate) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="text-center">
          <AlertCircle className={cn('mx-auto h-12 w-12', textColors.error)} />
          <p className={cn('mt-2 text-sm', textColors.tertiary)}>
            {t('common:notFound')}
          </p>
        </div>
      </div>
    )
  }

  const backLink = (
    <Link
      to="/treasury/withholding-certificates"
      className={cn(
        'inline-flex items-center gap-2 text-sm',
        textColors.tertiary,
        textColors.hoverPrimary,
      )}
    >
      <ArrowLeft className="h-4 w-4" />
      {t('common:back')}
    </Link>
  )

  const headerSubtitle = (
    <span className="flex items-center gap-2">
      {renderDirectionBadge(certificate.direction)}
      {renderStatusBadge(certificate.status)}
    </span>
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={certificate.certificate_number}
        breadcrumb={backLink}
        actions={
          <>
            {certificate.status === 'draft' && (
              <Button
                variant="danger"
                onClick={() => { void handleDelete() }}
              >
                <XCircle className="me-2 h-4 w-4" />
                {t('common:delete')}
              </Button>
            )}

            {certificate.can_be_issued && (
              <Button
                variant="primary"
                onClick={() => { void handleIssue() }}
              >
                <CheckCircle2 className="me-2 h-4 w-4" />
                {t('certificates.actions.issue')}
              </Button>
            )}

            {certificate.status !== 'draft' && (
              <Button
                variant="secondary"
                onClick={handleDownloadPDF}
              >
                <FileText className="me-2 h-4 w-4" />
                {t('certificates.actions.downloadPDF')}
              </Button>
            )}

            {certificate.status === 'issued' && (
              <>
                <Button
                  variant="secondary"
                  onClick={handleDownloadTEJXML}
                >
                  <Download className="me-2 h-4 w-4" />
                  {t('certificates.actions.downloadTEJ')}
                </Button>
                <Button
                  variant="primary"
                  onClick={() => { void handleSubmitToTEJ() }}
                >
                  <Send className="me-2 h-4 w-4" />
                  {t('certificates.actions.submitTEJ')}
                </Button>
              </>
            )}

            {certificate.can_be_voided && (
              <Button
                variant="danger"
                onClick={() => { void handleVoid() }}
              >
                <XCircle className="me-2 h-4 w-4" />
                {t('certificates.actions.void')}
              </Button>
            )}
          </>
        }
      />

      {/* Direction + status pills under the header */}
      <div>{headerSubtitle}</div>

      {/* Certificate Information */}
      <div className="grid gap-6 md:grid-cols-2">
        {/* Basic Info */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <FileText className="h-5 w-5" />
            {t('details.certificateInfo')}
          </h2>
          <dl className="space-y-3">
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('certificates.number')}</dt>
              <dd className={cn('mt-1 font-mono text-sm', textColors.primary)}>{certificate.certificate_number}</dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('certificates.year')}</dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>{certificate.year}</dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('certificates.direction')}</dt>
              <dd className="mt-1">{renderDirectionBadge(certificate.direction)}</dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('certificates.status')}</dt>
              <dd className="mt-1">{renderStatusBadge(certificate.status)}</dd>
            </div>
            {certificate.issued_at && (
              <div>
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('certificates.issuedAt')}</dt>
                <dd className={cn('mt-1 flex items-center gap-2 text-sm', textColors.primary)}>
                  <Calendar className={cn('h-4 w-4', textColors.disabled)} />
                  {new Date(certificate.issued_at).toLocaleString()}
                </dd>
              </div>
            )}
            {certificate.issuer && (
              <div>
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('common:issuedBy')}</dt>
                <dd className={cn('mt-1 flex items-center gap-2 text-sm', textColors.primary)}>
                  <User className={cn('h-4 w-4', textColors.disabled)} />
                  {certificate.issuer.name}
                </dd>
              </div>
            )}
          </dl>
        </div>

        {/* Partner Info */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <Building2 className="h-5 w-5" />
            {t('details.partnerInfo')}
          </h2>
          <dl className="space-y-3">
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('common:name')}</dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>{certificate.partner?.name ?? '-'}</dd>
            </div>
            {certificate.partner?.vat_number && (
              <div>
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('common:vatNumber')}</dt>
                <dd className={cn('mt-1 font-mono text-sm', textColors.primary)}>{certificate.partner.vat_number}</dd>
              </div>
            )}
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('details.glAccount')}</dt>
              <dd className={cn('mt-1 font-mono text-sm', textColors.primary)}>{certificate.gl_account_code}</dd>
            </div>
          </dl>
        </div>
      </div>

      {/* Amount Breakdown */}
      <div className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>
          {t('details.amountBreakdown')}
        </h2>
        <div className="space-y-3">
          <div className={cn('flex items-center justify-between border-b pb-2', borderColors.light)}>
            <span className={cn('text-sm', textColors.tertiary)}>{t('certificates.grossAmount')}</span>
            <span className={cn('text-lg font-mono font-semibold tabular-nums', textColors.primary)}>
              {formatNumber(certificate.gross_amount, decimals)} {certificate.currency}
            </span>
          </div>
          <div className={cn('flex items-center justify-between border-b pb-2', borderColors.light)}>
            <span className={cn('text-sm', textColors.tertiary)}>
              {t('certificates.rate')} ({formatPercent(certificate.rate_percentage)})
            </span>
            <span className={cn('text-lg font-mono font-semibold tabular-nums', textColors.error)}>
              - {formatNumber(certificate.withholding_amount, decimals)} {certificate.currency}
            </span>
          </div>
          <div className="flex items-center justify-between pt-2">
            <span className={cn('text-base font-semibold', textColors.primary)}>{t('certificates.netAmount')}</span>
            <span className={cn('text-2xl font-mono font-bold tabular-nums', textColors.primary)}>
              {formatNumber(certificate.net_amount, decimals)} {certificate.currency}
            </span>
          </div>
        </div>
      </div>

      {/* Rule Applied or Manual Override */}
      <div className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>
          {certificate.is_manual_override ? t('details.manualOverride') : t('details.ruleApplied')}
        </h2>
        {certificate.rule ? (
          <dl className="space-y-3">
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('rules.code')}</dt>
              <dd className={cn('mt-1 font-mono text-sm', textColors.primary)}>{certificate.rule.code}</dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('rules.name')}</dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>{certificate.rule.name}</dd>
            </div>
          </dl>
        ) : certificate.override_reason ? (
          <div>
            <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('details.overrideReason')}</dt>
            <dd className={cn('mt-1 text-sm', textColors.primary)}>{certificate.override_reason}</dd>
          </div>
        ) : (
          <p className={cn('text-sm', textColors.tertiary)}>{t('common:noData')}</p>
        )}
      </div>

      {/* TEJ Submission Info */}
      {certificate.is_submitted_to_tej && (
        <div className={cn(tokens.card.base, tokens.alert.info)}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <Send className="h-5 w-5" />
            {t('details.tejReference')}
          </h2>
          <dl className="space-y-3">
            <div>
              <dt className="text-sm font-medium">{t('details.tejReference')}</dt>
              <dd className="mt-1 font-mono text-sm">{certificate.tej_reference}</dd>
            </div>
            {certificate.tej_submitted_at && (
              <div>
                <dt className="text-sm font-medium">{t('details.tejSubmittedAt')}</dt>
                <dd className="mt-1 text-sm">
                  {new Date(certificate.tej_submitted_at).toLocaleString()}
                </dd>
              </div>
            )}
          </dl>
        </div>
      )}

      {/* Hash Chain Info */}
      {certificate.hash && (
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <Hash className="h-5 w-5" />
            {t('details.hashChain')}
          </h2>
          <dl className="space-y-3">
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('details.sequence')}</dt>
              <dd className={cn('mt-1 font-mono text-sm', textColors.primary)}>#{certificate.chain_sequence}</dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('details.hash')}</dt>
              <dd className={cn('mt-1 break-all font-mono text-xs', textColors.primary)}>{certificate.hash}</dd>
            </div>
            {certificate.previous_hash && (
              <div>
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('details.previousHash')}</dt>
                <dd className={cn('mt-1 break-all font-mono text-xs', textColors.primary)}>{certificate.previous_hash}</dd>
              </div>
            )}
          </dl>
        </div>
      )}

      {/* Linked Documents */}
      {(certificate.document_id ?? certificate.payment_id) && (
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <LinkIcon className="h-5 w-5" />
            {t('common:linkedDocuments')}
          </h2>
          <div className="space-y-2">
            {certificate.document_id && (
              <Link
                to={`/sales/invoices/${certificate.document_id}`}
                className={cn('block text-sm hover:underline', textColors.brand)}
              >
                {t('common:viewDocument')} →
              </Link>
            )}
            {certificate.payment_id && (
              <Link
                to={`/treasury/payments/${certificate.payment_id}`}
                className={cn('block text-sm hover:underline', textColors.brand)}
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
