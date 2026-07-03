import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, FileText, CheckCircle, XCircle, Clock, Loader2, Download } from 'lucide-react'
import { useImportJobs } from '../api/queries'
import { importApi } from '../api/importApi'
import { authenticatedDownload } from '@/lib/api'
import { textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { ImportJob, ImportStatus } from '../types'

export function ImportHistoryPage() {
  const { t } = useTranslation('import')
  const [statusFilter, setStatusFilter] = useState<ImportStatus | 'all'>('all')

  const { data: jobs, isLoading } = useImportJobs()

  const filteredJobs = jobs?.data?.filter((job) => {
    if (statusFilter === 'all') return true
    return job.status === statusFilter
  })

  const getStatusIcon = (status: ImportStatus) => {
    switch (status) {
      case 'completed':
        return <CheckCircle className="h-4 w-4 text-green-600" />
      case 'failed':
        return <XCircle className="h-4 w-4 text-red-600" />
      case 'importing':
      case 'validating':
        return <Loader2 className="h-4 w-4 animate-spin text-blue-600" />
      default:
        return <Clock className="h-4 w-4 text-gray-400" />
    }
  }

  const getStatusBadge = (status: ImportStatus) => {
    const styles: Record<ImportStatus, string> = {
      pending: 'bg-gray-100 text-gray-700',
      validating: 'bg-blue-100 text-blue-700',
      validated: 'bg-indigo-100 text-indigo-700',
      importing: 'bg-blue-100 text-blue-700',
      completed: 'bg-green-100 text-green-700',
      failed: 'bg-red-100 text-red-700',
    }

    return (
      <span
        className={cn(
          'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
          styles[status]
        )}
      >
        {getStatusIcon(status)}
        {t(`status.${status}`)}
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
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{t('history.title')}</h1>
            <p className="text-gray-500">{t('history.description')}</p>
          </div>
        </div>
      </div>

      {/* Filters */}
      <div className="flex items-center gap-2">
        <span className="text-sm text-gray-500">{t('history.filterByStatus')}:</span>
        <div className="flex gap-2">
          {(['all', 'completed', 'failed', 'importing', 'pending'] as const).map((status) => (
            <button
              key={status}
              type="button"
              onClick={() => { setStatusFilter(status); }}
              className={cn(
                'rounded-full px-3 py-1 text-sm font-medium transition-colors',
                statusFilter === status
                  ? 'bg-blue-600 text-white'
                  : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
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
          <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
        </div>
      ) : filteredJobs && filteredJobs.length > 0 ? (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('history.columns.type')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('history.columns.file')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('history.columns.status')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('history.columns.progress')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('history.columns.date')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('history.columns.actions')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {filteredJobs.map((job: ImportJob) => (
                <tr key={job.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex items-center gap-2">
                      <FileText className="h-4 w-4 text-gray-400" />
                      <span className="font-medium text-gray-900">
                        {t(`types.${job.type}.title`)}
                      </span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <span className="text-sm text-gray-900">{job.original_filename}</span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    {getStatusBadge(job.status)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    {job.status === 'completed' || job.status === 'failed' ? (
                      <div className="text-sm">
                        <span className="text-green-600">{job.successful_rows ?? 0}</span>
                        <span className="text-gray-400"> / </span>
                        <span className="text-red-600">{job.failed_rows ?? 0}</span>
                        <span className="text-gray-400"> / </span>
                        <span className="text-gray-600">{job.total_rows ?? 0}</span>
                      </div>
                    ) : job.status === 'importing' ? (
                      <div className="flex items-center gap-2">
                        <div className="h-2 w-24 rounded-full bg-gray-200">
                          <div
                            className="h-2 rounded-full bg-blue-600 transition-all"
                            style={{
                              width: `${((job.processed_rows ?? 0) / (job.total_rows ?? 1)) * 100}%`,
                            }}
                          />
                        </div>
                        <span className="text-xs text-gray-500">
                          {job.processed_rows ?? 0}/{job.total_rows ?? 0}
                        </span>
                      </div>
                    ) : (
                      <span className="text-sm text-gray-400">-</span>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
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
          </table>
        </div>
      ) : (
        <div className="rounded-lg border border-gray-200 bg-white p-12 text-center">
          <FileText className="mx-auto h-12 w-12 text-gray-300" />
          <h3 className="mt-2 text-sm font-medium text-gray-900">{t('history.noJobs')}</h3>
          <p className="mt-1 text-sm text-gray-500">{t('history.noJobsDescription')}</p>
          <Link
            to="/settings/import"
            className="mt-4 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
          >
            {t('history.startImport')}
          </Link>
        </div>
      )}
    </div>
  )
}
