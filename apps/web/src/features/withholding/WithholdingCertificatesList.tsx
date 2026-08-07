import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {

  Download,
  FileText,
  Filter,
  Send,
  Eye,
  CheckCircle2,
  XCircle,
  AlertCircle,
} from 'lucide-react'
import { toast } from 'sonner'
import {
  downloadCertificatePDF,
  downloadCertificateTEJXML,
  downloadBatchTEJXML,
} from './api/withholdingApi'
import { useCurrency } from '@/hooks/useCurrency'
import {
  useWithholdingCertificates,
  useIssueWithholdingCertificate,
  useVoidWithholdingCertificate,
} from './hooks/useWithholding'
import { cn } from '@/lib/utils'
import { formatNumber, formatPercent } from '@/lib/format'
import { tokens, textColors } from '@/lib/designTokens'
import { Button, Input, Select, StatusBadge, statusTone, type StatusTone } from '@/components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '@/components/molecules'
import type {
  CertificateFilters,
  WithholdingCertificate,
  WithholdingDirection,
  CertificateStatus,
} from './types'

/**
 * Certificate lifecycle statuses routed through the one sanctioned StatusBadge
 * palette. `draft` (pending) and `voided` aren't both in the built-in map, so
 * the bespoke certificate states get a semantic tone here.
 */
const certificateStatusTones: Record<string, StatusTone> = {
  draft: 'pending',
  issued: 'success',
  submitted: 'info',
  voided: 'danger',
}

/** Purchase/sales direction mapped to neutral/info tones (no off-theme palette). */
const directionTones: Record<WithholdingDirection, StatusTone> = {
  purchase: 'info',
  sales: 'neutral',
}

const statusIcons: Record<CertificateStatus, React.ReactNode> = {
  draft: <FileText className="h-3 w-3" />,
  issued: <CheckCircle2 className="h-3 w-3" />,
  submitted: <Send className="h-3 w-3" />,
  voided: <XCircle className="h-3 w-3" />,
}

export function WithholdingCertificatesList() {
  const { t } = useTranslation(['withholding', 'common'])
  const { decimals } = useCurrency()

  const [filters, setFilters] = useState<CertificateFilters>({})
  const [showFilters, setShowFilters] = useState(false)

  const { data, isLoading } = useWithholdingCertificates(filters)
  const issueMutation = useIssueWithholdingCertificate()
  const voidMutation = useVoidWithholdingCertificate()

  const certificates = data?.data ?? []

  const handleIssue = async (id: string) => {
    if (!window.confirm(t('messages.confirmIssue'))) return

    try {
      await issueMutation.mutateAsync(id)
    } catch {
      // Error handled by mutation onError
    }
  }

  const handleVoid = async (id: string) => {
    const reason = window.prompt(t('messages.confirmVoid'))
    if (!reason) return

    try {
      await voidMutation.mutateAsync({ id, request: { reason } })
    } catch {
      // Error handled by mutation onError
    }
  }

  const handleDownloadPDF = async (id: string) => {
    try {
      await downloadCertificatePDF(id)
      toast.success(t('messages.downloadStarted'))
    } catch {
      toast.error(t('common:errors.generic'))
    }
  }

  const handleDownloadTEJXML = async (id: string) => {
    try {
      await downloadCertificateTEJXML(id)
      toast.success(t('messages.downloadStarted'))
    } catch {
      toast.error(t('common:errors.generic'))
    }
  }

  const handleDownloadBatchXML = async () => {
    try {
      await downloadBatchTEJXML(filters.year, filters.direction)
      toast.success(t('messages.downloadStarted'))
    } catch {
      toast.error(t('common:errors.generic'))
    }
  }

  const columns: DataTableColumn<WithholdingCertificate>[] = [
    {
      key: 'number',
      header: t('certificates.number'),
      render: (cert) => (
        <Link
          to={`/treasury/withholding-certificates/${cert.id}`}
          className={cn('font-medium', textColors.brand, 'hover:underline')}
        >
          {cert.certificate_number}
        </Link>
      ),
    },
    {
      key: 'direction',
      header: t('certificates.direction'),
      render: (cert) => (
        <StatusBadge tone={directionTones[cert.direction]}>
          {t(`direction.${cert.direction}Short`)}
        </StatusBadge>
      ),
    },
    {
      key: 'partner',
      header: t('certificates.partner'),
      render: (cert) => (
        <span className={textColors.primary}>{cert.partner?.name ?? '-'}</span>
      ),
    },
    {
      key: 'grossAmount',
      header: t('certificates.grossAmount'),
      numeric: true,
      cellClassName: 'font-mono',
      render: (cert) => `${formatNumber(cert.gross_amount, decimals)} ${cert.currency}`,
    },
    {
      key: 'rate',
      header: t('certificates.rate'),
      numeric: true,
      cellClassName: 'font-mono',
      render: (cert) => formatPercent(cert.rate_percentage),
    },
    {
      key: 'withholdingAmount',
      header: t('certificates.withholdingAmount'),
      numeric: true,
      cellClassName: cn('font-mono font-semibold', textColors.error),
      render: (cert) =>
        `${formatNumber(cert.withholding_amount, decimals)} ${cert.currency}`,
    },
    {
      key: 'status',
      header: t('certificates.status'),
      align: 'center',
      render: (cert) => (
        <StatusBadge tone={statusTone(cert.status, certificateStatusTones)} className="gap-1">
          {statusIcons[cert.status]}
          {t(`status.${cert.status}`)}
        </StatusBadge>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('common:actions')}</span>,
      align: 'center',
      render: (cert) => (
        <div className="flex items-center justify-center gap-1">
          <Link
            to={`/treasury/withholding-certificates/${cert.id}`}
            className={cn('inline-flex items-center justify-center rounded p-1', textColors.tertiary, tokens.button.ghost)}
            title={t('certificates.actions.viewDetails')}
          >
            <Eye className="h-4 w-4" />
          </Link>

          {cert.can_be_issued && (
            <Button
              variant="ghost"
              size="sm"
              className="p-1"
              onClick={() => { void handleIssue(cert.id) }}
              title={t('certificates.actions.issue')}
            >
              <CheckCircle2 className="h-4 w-4" />
            </Button>
          )}

          {cert.status !== 'draft' && (
            <Button
              variant="ghost"
              size="sm"
              className="p-1"
              onClick={() => { void handleDownloadPDF(cert.id) }}
              title={t('certificates.actions.downloadPDF')}
            >
              <FileText className="h-4 w-4" />
            </Button>
          )}

          {cert.status === 'issued' && (
            <Button
              variant="ghost"
              size="sm"
              className="p-1"
              onClick={() => { void handleDownloadTEJXML(cert.id) }}
              title={t('certificates.actions.downloadTEJ')}
            >
              <Download className="h-4 w-4" />
            </Button>
          )}

          {cert.can_be_voided && (
            <Button
              variant="ghost"
              size="sm"
              className="p-1"
              onClick={() => { void handleVoid(cert.id) }}
              title={t('certificates.actions.void')}
            >
              <XCircle className="h-4 w-4" />
            </Button>
          )}
        </div>
      ),
    },
  ]

  return (
    <ListPageLayout
      title={t('certificates.title')}
      actions={
        <>
          <Button variant="secondary" className="gap-2" onClick={handleDownloadBatchXML}>
            <Download className="h-4 w-4" />
            {t('certificates.export.batchTEJ')}
          </Button>
          <Button
            variant="secondary"
            className="gap-2"
            onClick={() => { setShowFilters(!showFilters) }}
          >
            <Filter className="h-4 w-4" />
            {t('common:filtersLabel')}
          </Button>
        </>
      }
      filters={
        showFilters ? (
          <div className={cn('w-full', tokens.card.base)}>
            <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
              <div>
                <label className={cn('mb-1', tokens.label.base)}>
                  {t('certificates.filters.direction')}
                </label>
                <Select
                  value={filters.direction ?? ''}
                  onChange={(e) => {
                    setFilters({
                      ...filters,
                      direction: (e.target.value as WithholdingDirection) || undefined,
                    })
                  }}
                >
                  <option value="">{t('common:all')}</option>
                  <option value="purchase">{t('direction.purchase')}</option>
                  <option value="sales">{t('direction.sales')}</option>
                </Select>
              </div>

              <div>
                <label className={cn('mb-1', tokens.label.base)}>
                  {t('certificates.filters.status')}
                </label>
                <Select
                  value={filters.status ?? ''}
                  onChange={(e) => {
                    setFilters({
                      ...filters,
                      status: (e.target.value as CertificateStatus) || undefined,
                    })
                  }}
                >
                  <option value="">{t('common:all')}</option>
                  <option value="draft">{t('status.draft')}</option>
                  <option value="issued">{t('status.issued')}</option>
                  <option value="submitted">{t('status.submitted')}</option>
                  <option value="voided">{t('status.voided')}</option>
                </Select>
              </div>

              <div>
                <label className={cn('mb-1', tokens.label.base)}>
                  {t('certificates.filters.year')}
                </label>
                <Input
                  type="number"
                  value={filters.year ?? ''}
                  onChange={(e) => {
                    setFilters({
                      ...filters,
                      year: e.target.value ? parseInt(e.target.value) : undefined,
                    })
                  }}
                  placeholder={new Date().getFullYear().toString()}
                />
              </div>

              <div className="flex items-end">
                <Button
                  variant="secondary"
                  className="w-full"
                  onClick={() => { setFilters({}) }}
                >
                  {t('common:clearFilters')}
                </Button>
              </div>
            </div>
          </div>
        ) : undefined
      }
    >
      <DataTable
        columns={columns}
        data={certificates}
        keyExtractor={(cert) => cert.id}
        isLoading={isLoading}
        emptyState={
          <EmptyState
            icon={<AlertCircle className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
            title={t('certificates.noCertificates')}
          />
        }
      />
    </ListPageLayout>
  )
}
