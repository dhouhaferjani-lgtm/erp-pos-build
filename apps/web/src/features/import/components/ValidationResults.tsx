import { useTranslation } from 'react-i18next'
import { AlertCircle, CheckCircle, Download, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { ImportErrorSummary } from '../types'

interface ValidationResultsProps {
  summary: ImportErrorSummary
  totalRows: number
  onDownloadErrors?: () => void
}

export function ValidationResults({
  summary,
  totalRows,
  onDownloadErrors,
}: ValidationResultsProps) {
  const { t } = useTranslation('import')

  const validRows = totalRows - summary.total_errors
  const hasErrors = summary.has_errors

  return (
    <div className="space-y-4">
      {/* Summary Card */}
      <div
        className={cn(
          'rounded-lg border p-6',
          hasErrors
            ? 'border-amber-200 bg-amber-50'
            : 'border-green-200 bg-green-50'
        )}
      >
        <div className="flex items-start justify-between">
          <div className="flex items-start gap-3">
            {hasErrors ? (
              <AlertCircle className="h-6 w-6 text-amber-600 flex-shrink-0 mt-0.5" />
            ) : (
              <CheckCircle className="h-6 w-6 text-green-600 flex-shrink-0 mt-0.5" />
            )}
            <div>
              <h3
                className={cn(
                  'text-lg font-semibold',
                  hasErrors ? 'text-amber-900' : 'text-green-900'
                )}
              >
                {hasErrors
                  ? t('validation.rowsWithErrors', { count: summary.total_errors })
                  : t('validation.allValid')}
              </h3>
              <p
                className={cn(
                  'mt-1 text-sm',
                  hasErrors ? 'text-amber-700' : 'text-green-700'
                )}
              >
                {hasErrors
                  ? t('wizard.partialImportDescription', {
                      valid: validRows,
                      failed: summary.total_errors,
                    })
                  : t('validation.allValidDescription')}
              </p>

              {/* Job-level Error Message */}
              {summary.job_error_message && (
                <div className="mt-3 rounded-md bg-red-50 border border-red-200 p-3">
                  <div className="flex items-start gap-2">
                    <XCircle className="h-4 w-4 text-red-600 flex-shrink-0 mt-0.5" />
                    <p className="text-sm text-red-800">
                      {summary.job_error_message}
                    </p>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Download Button */}
          {hasErrors && onDownloadErrors && (
            <button
              type="button"
              onClick={onDownloadErrors}
              className="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50"
            >
              <Download className="h-4 w-4" />
              {t('wizard.complete.downloadFailedRows')}
            </button>
          )}
        </div>

        {/* Statistics Grid */}
        {hasErrors && (
          <div className="mt-6 grid grid-cols-3 gap-4 border-t border-amber-200 pt-4">
            <div className="text-center">
              <div className="text-2xl font-bold text-gray-900">
                {validRows.toLocaleString()}
              </div>
              <div className="text-xs text-gray-500 uppercase tracking-wide">
                {t('wizard.execute.validRows')}
              </div>
            </div>
            <div className="text-center">
              <div className="text-2xl font-bold text-amber-600">
                {summary.validation_errors.toLocaleString()}
              </div>
              <div className="text-xs text-gray-500 uppercase tracking-wide">
                {t('validation.validationErrorsCount', {
                  count: summary.validation_errors,
                })}
              </div>
            </div>
            <div className="text-center">
              <div className="text-2xl font-bold text-red-600">
                {summary.execution_errors.toLocaleString()}
              </div>
              <div className="text-xs text-gray-500 uppercase tracking-wide">
                {t('validation.executionErrorsCount', {
                  count: summary.execution_errors,
                })}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
