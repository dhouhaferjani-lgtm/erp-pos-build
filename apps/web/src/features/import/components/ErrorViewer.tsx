import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle, Download, Loader2 } from 'lucide-react'
import { authenticatedDownload } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { importApi } from '../api/importApi'
import { ValidationGrid } from './ValidationGrid'
import { ValidationResults } from './ValidationResults'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface ErrorViewerProps {
  jobId: string
  totalRows: number
}

export function ErrorViewer({ jobId, totalRows }: ErrorViewerProps) {
  const { t } = useTranslation('import')
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [currentPage, setCurrentPage] = useState(1)
  const perPage = 50

  // Fetch error summary
  const { data: summaryData, isLoading: summaryLoading } = useQuery({
    queryKey: tenantScopedKey(['import-error-summary', jobId]),
    queryFn: () => importApi.getErrorSummary(jobId),
    enabled: !!tenantId && !!companyId,
  })

  // Fetch paginated errors
  const { data: errorsData, isLoading: errorsLoading } = useQuery({
    queryKey: tenantScopedKey(['import-errors', jobId, currentPage, perPage]),
    queryFn: () => importApi.getErrors(jobId, currentPage, perPage),
    enabled: summaryData?.data.has_errors === true && !!tenantId && !!companyId,
  })

  const handleDownloadErrors = async () => {
    await authenticatedDownload(
      importApi.downloadFailedRowsUrl(jobId),
      `import_${jobId}_failed_rows.csv`
    )
  }

  if (summaryLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.text.disabled}`} />
      </div>
    )
  }

  if (!summaryData) {
    return (
      <div className={`rounded-lg border ${colorTokens.intent.danger.borderSubtleSoft} ${colorTokens.intent.danger.bgSubtle} p-6`}>
        <div className="flex items-start gap-3">
          <AlertCircle className={`h-5 w-5 ${colorTokens.intent.danger.text} flex-shrink-0`} />
          <p className={`text-sm ${colorTokens.intent.danger.textStronger}`}>
            {t('preview.loadError')}
          </p>
        </div>
      </div>
    )
  }

  const summary = summaryData.data

  // If no errors, show success message
  if (!summary.has_errors) {
    return (
      <ValidationResults
        summary={summary}
        totalRows={totalRows}
      />
    )
  }

  return (
    <div className="space-y-6">
      {/* Summary */}
      <ValidationResults
        summary={summary}
        totalRows={totalRows}
        onDownloadErrors={handleDownloadErrors}
      />

      {/* Errors Grid */}
      {errorsLoading ? (
        <div className="flex items-center justify-center py-12">
          <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.text.disabled}`} />
        </div>
      ) : errorsData?.data && errorsData.data.length > 0 ? (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h3 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('validation.errorsTitle')}
            </h3>
            <button
              type="button"
              onClick={handleDownloadErrors}
              className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover}`}
            >
              <Download className="h-4 w-4" />
              {t('wizard.complete.downloadFailedRows')}
            </button>
          </div>

          <ValidationGrid rows={errorsData.data} showOnlyErrors={true} />

          {/* Pagination Controls */}
          {errorsData.meta && errorsData.meta.last_page && errorsData.meta.last_page > 1 && (
            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <p className={`text-sm ${colorTokens.text.subtle}`}>
                {t('validation.showingPage', {
                  current: errorsData.meta.current_page,
                  total: errorsData.meta.last_page,
                })}
              </p>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => { setCurrentPage(p => Math.max(1, p - 1)); }}
                  disabled={currentPage === 1}
                  className={`inline-flex items-center rounded-md border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} disabled:cursor-not-allowed disabled:opacity-50`}
                >
                  {t('common:pagination.previous')}
                </button>
                <span className={`inline-flex items-center px-4 py-2 text-sm ${colorTokens.text.secondary}`}>
                  {currentPage} / {errorsData.meta.last_page}
                </span>
                <button
                  type="button"
                  onClick={() => { setCurrentPage(p => Math.min(errorsData.meta!.last_page!, p + 1)); }}
                  disabled={currentPage === errorsData.meta.last_page}
                  className={`inline-flex items-center rounded-md border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} disabled:cursor-not-allowed disabled:opacity-50`}
                >
                  {t('common:pagination.next')}
                </button>
              </div>
            </div>
          )}
        </div>
      ) : null}
    </div>
  )
}
