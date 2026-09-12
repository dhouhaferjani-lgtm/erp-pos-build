import { useTranslation } from 'react-i18next'
import { AlertCircle, CheckCircle, Download, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { importJobErrorMessage } from '../jobErrorMessage'
import type { ImportErrorSummary } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
  const jobErrorMessage = importJobErrorMessage(t, {
    error_code: summary.job_error_code,
    error_message: summary.job_error_message,
    error_detail: summary.job_error_detail,
  })

  return (
    <div className="space-y-4">
      {/* Summary Card */}
      <div
        className={cn(
          'rounded-lg border p-6',
          hasErrors
            ? `${colorTokens.intent.caution.borderSubtle} ${colorTokens.intent.caution.bgSubtle}`
            : `${colorTokens.intent.success.borderSubtle} ${colorTokens.intent.success.bgSubtle}`
        )}
      >
        <div className="flex items-start justify-between">
          <div className="flex items-start gap-3">
            {hasErrors ? (
              <AlertCircle className={`h-6 w-6 ${colorTokens.intent.caution.text} flex-shrink-0 mt-0.5`} />
            ) : (
              <CheckCircle className={`h-6 w-6 ${colorTokens.intent.success.text} flex-shrink-0 mt-0.5`} />
            )}
            <div>
              <h3
                className={cn(
                  'text-lg font-semibold',
                  hasErrors ? colorTokens.intent.caution.textStrongest : colorTokens.intent.success.textStrongest
                )}
              >
                {hasErrors
                  ? t('validation.rowsWithErrors', { count: summary.total_errors })
                  : t('validation.allValid')}
              </h3>
              <p
                className={cn(
                  'mt-1 text-sm',
                  hasErrors ? colorTokens.intent.caution.textStrong : colorTokens.intent.success.textStrong
                )}
              >
                {hasErrors
                  ? t('wizard.partialImportDescription', {
                      valid: validRows,
                      failed: summary.total_errors,
                    })
                  : t('validation.allValidDescription')}
              </p>

              {/* Job-level failure — the coded channel, never `job_error_message`
                  (raw class names, file paths and SQLSTATE text). */}
              {jobErrorMessage !== null && (
                <div role="alert" className={`mt-3 rounded-md ${colorTokens.intent.danger.bgSubtle} border ${colorTokens.intent.danger.borderSubtle} p-3`}>
                  <div className="flex items-start gap-2">
                    <XCircle className={`h-4 w-4 ${colorTokens.intent.danger.text} flex-shrink-0 mt-0.5`} />
                    <p className={`text-sm ${colorTokens.intent.danger.textStronger}`}>
                      {jobErrorMessage}
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
              className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.intent.caution.border} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.intent.caution.textStrong} ${colorTokens.intent.caution.bgHover}`}
            >
              <Download className="h-4 w-4" />
              {t('wizard.complete.downloadFailedRows')}
            </button>
          )}
        </div>

        {/* Statistics Grid */}
        {hasErrors && (
          <div className={`mt-6 grid grid-cols-3 gap-4 border-t ${colorTokens.intent.caution.borderSubtle} pt-4`}>
            <div className="text-center">
              <div className={`text-2xl font-bold ${colorTokens.text.primary}`}>
                {validRows.toLocaleString()}
              </div>
              <div className={`text-xs ${colorTokens.text.subtle} uppercase tracking-wide`}>
                {t('wizard.execute.validRows')}
              </div>
            </div>
            <div className="text-center">
              <div className={`text-2xl font-bold ${colorTokens.intent.caution.text}`}>
                {summary.validation_errors.toLocaleString()}
              </div>
              <div className={`text-xs ${colorTokens.text.subtle} uppercase tracking-wide`}>
                {t('validation.validationErrorsCount', {
                  count: summary.validation_errors,
                })}
              </div>
            </div>
            <div className="text-center">
              <div className={`text-2xl font-bold ${colorTokens.intent.danger.text}`}>
                {summary.execution_errors.toLocaleString()}
              </div>
              <div className={`text-xs ${colorTokens.text.subtle} uppercase tracking-wide`}>
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
