import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, FileText, CheckCircle, XCircle, Clock, Loader2, Download } from 'lucide-react'
import { useImportJobs } from '../api/queries'
import { importApi } from '../api/importApi'
import { authenticatedDownload } from '@/lib/api'
import { textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { ImportJob, ImportStatus } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { UnknownUnitSummary } from '../components/UnknownUnitSummary'
import { KNOWN_WARNING_CODES } from '../warningCodes'

const importStateGlyphs: Record<ImportStatus, ReactNode> = {
  pending: <Clock className={`h-4 w-4 ${colorTokens.text.disabled}`} />,
  validating: <Loader2 className={`h-4 w-4 animate-spin ${colorTokens.intent.primary.text}`} />,
  validated: <Clock className={`h-4 w-4 ${colorTokens.text.disabled}`} />,
  importing: <Loader2 className={`h-4 w-4 animate-spin ${colorTokens.intent.primary.text}`} />,
  completed: <CheckCircle className={`h-4 w-4 ${colorTokens.intent.success.text}`} />,
  failed: <XCircle className={`h-4 w-4 ${colorTokens.intent.danger.text}`} />,
}

const importStateTone: Record<ImportStatus, string> = {
  pending: `${colorTokens.surface.muted} ${colorTokens.text.secondary}`,
  validating: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStrong}`,
  validated: `${colorTokens.intent.verified.bgSoft} ${colorTokens.intent.verified.textStrong}`,
  importing: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStrong}`,
  completed: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStrong}`,
  failed: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStrong}`,
}

const defaultImportStateGlyph = <Clock className={`h-4 w-4 ${colorTokens.text.disabled}`} />
const defaultImportStateTone = `${colorTokens.surface.muted} ${colorTokens.text.secondary}`

export function ImportHistoryPage() {
  const { t } = useTranslation('import')
  const [statusFilter, setStatusFilter] = useState<ImportStatus | 'all'>('all')

  const { data: jobs, isLoading } = useImportJobs()

  const filteredJobs = jobs?.data?.filter((job) => {
    if (statusFilter === 'all') return true
    return job.status === statusFilter
  })

  const renderImportStatePill = (value: ImportStatus | string) => {
    const status = value as ImportStatus

    return (
      <span
        className={cn(
          'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
          importStateTone[status] ?? defaultImportStateTone
        )}
      >
        {importStateGlyphs[status] ?? defaultImportStateGlyph}
        {t(`status.${value}`)}
      </span>
    )
  }

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleString()
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/settings/import"
            className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{t('history.title')}</PageHeaderTitle>
            <p className={colorTokens.text.subtle}>{t('history.description')}</p>
          </div>
        </div>
      </div>

      {/* Filters */}
      <div className="flex items-center gap-2">
        <span className={`text-sm ${colorTokens.text.subtle}`}>{t('history.filterByStatus')}:</span>
        <div className="flex gap-2">
          {(['all', 'completed', 'failed', 'importing', 'pending'] as const).map((status) => (
            <button
              key={status}
              type="button"
              onClick={() => { setStatusFilter(status); }}
              className={cn(
                'rounded-full px-3 py-1 text-sm font-medium transition-colors',
                statusFilter === status
                  ? `${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse}`
                  : `${colorTokens.surface.muted} ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHoverStrong}`
              )}
            >
              {status === 'all' ? t('history.all') : t(`status.${status}`)}
            </button>
          ))}
        </div>
      </div>

      {/* Jobs list */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text}`} />
        </div>
      ) : filteredJobs && filteredJobs.length > 0 ? (
        <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base}`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={colorTokens.surface.page}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('history.columns.type')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('history.columns.file')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('history.columns.status')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('history.columns.progress')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('history.columns.date')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('history.columns.actions')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider}`}>
              {filteredJobs.map((job: ImportJob) => (
                <tr key={job.id} className={colorTokens.intent.neutral.bgHover}>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex items-center gap-2">
                      <FileText className={`h-4 w-4 ${colorTokens.text.disabled}`} />
                      <span className={`font-medium ${colorTokens.text.primary}`}>
                        {t(`types.${job.type}.title`)}
                      </span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <span className={`text-sm ${colorTokens.text.primary}`}>{job.original_filename}</span>
                    <div className="mt-2 max-w-xl">
                      <UnknownUnitSummary summary={job.error_summary} compact />
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    {renderImportStatePill(job.status)}
                  </td>
                  <td className="px-6 py-4">
                    {job.status === 'completed' || job.status === 'failed' ? (
                      <div className="space-y-1 text-sm">
                        <div className="whitespace-nowrap">
                          <span data-testid={`import-history-count-imported-${job.id}`} className={colorTokens.intent.success.text}>
                            {t('history.counts.imported', { count: job.successful_rows })}
                          </span>
                          <span className={colorTokens.text.disabled}> / </span>
                          <span data-testid={`import-history-count-skipped-${job.id}`} className={colorTokens.intent.warning.textStronger}>
                            {t('history.counts.skipped', { count: job.skipped_rows })}
                          </span>
                          <span className={colorTokens.text.disabled}> / </span>
                          <span data-testid={`import-history-count-failed-${job.id}`} className={colorTokens.intent.danger.text}>
                            {t('history.counts.failed', { count: job.failed_rows })}
                          </span>
                          <span className={colorTokens.text.disabled}> / </span>
                          <span data-testid={`import-history-count-total-${job.id}`} className={colorTokens.text.muted}>
                            {t('history.counts.total', { count: job.total_rows })}
                          </span>
                        </div>
                        {(job.warning_summary?.['enriched'] ?? 0) > 0 && (
                          <div className={colorTokens.intent.success.text}>
                            {t('history.enriched', { count: job.warning_summary?.['enriched'] ?? 0 })}
                          </div>
                        )}
                        {Object.entries(job.warning_summary ?? {})
                          .filter(([code, count]) => code !== 'enriched' && count > 0)
                          .map(([code, count]) => (
                            <div key={code} className={colorTokens.intent.warning.textStronger}>
                              {KNOWN_WARNING_CODES.has(code)
                                ? t(`warnings.${code}`, { count })
                                : t('warnings.other', { code, count })}
                            </div>
                          ))}
                      </div>
                    ) : job.status === 'importing' ? (
                      <div className="flex items-center gap-2">
                        <div className={`h-2 w-24 rounded-full ${colorTokens.surface.subdued}`}>
                          <div
                            className={`h-2 rounded-full ${colorTokens.intent.primary.bgStrong} transition-all`}
                            style={{
                              width: `${((job.processed_rows ?? 0) / (job.total_rows ?? 1)) * 100}%`,
                            }}
                          />
                        </div>
                        <span className={`text-xs ${colorTokens.text.subtle}`}>
                          {job.processed_rows ?? 0}/{job.total_rows ?? 0}
                        </span>
                      </div>
                    ) : (
                      <span className={`text-sm ${colorTokens.text.disabled}`}>-</span>
                    )}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                    {formatDate(job.created_at)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end">
                    {job.status === 'completed' || job.status === 'failed' ? (
                      <div className="flex flex-col items-end gap-2">
                        <button
                          type="button"
                          onClick={() =>
                            authenticatedDownload(
                              importApi.downloadResultWorkbookUrl(job.id),
                              `import-${job.id}-result.xlsx`
                            )
                          }
                          className={cn('inline-flex items-center gap-1 text-sm', textColors.brand, textColors.hoverBrand)}
                        >
                          <Download className="h-4 w-4" />
                          {t('results.downloadWorkbook')}
                        </button>
                        {(job.failed_rows ?? 0) > 0 && (
                          <button
                            type="button"
                            onClick={() =>
                              authenticatedDownload(
                                importApi.downloadFailedRowsUrl(job.id),
                                `import-${job.id}-failed-rows.csv`
                              )
                            }
                            className={cn('inline-flex items-center gap-1 text-sm', textColors.brand, textColors.hoverBrand)}
                          >
                            <Download className="h-4 w-4" />
                            {t('wizard.complete.downloadFailedRows')}
                          </button>
                        )}
                      </div>
                    ) : (
                      <span className={cn('text-sm', textColors.disabled)}>-</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </div>
      ) : (
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-12 text-center`}>
          <FileText className={`mx-auto h-12 w-12 ${colorTokens.text.faint}`} />
          <h3 className={`mt-2 text-sm font-medium ${colorTokens.text.primary}`}>{t('history.noJobs')}</h3>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>{t('history.noJobsDescription')}</p>
          <Link
            to="/settings/import"
            className={`mt-4 inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
          >
            {t('history.startImport')}
          </Link>
        </div>
      )}
    </div>
  )
}
