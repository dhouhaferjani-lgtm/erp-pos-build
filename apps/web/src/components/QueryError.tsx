import { AlertCircle, RefreshCw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { getErrorMessage } from '@/lib/api'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface QueryErrorProps {
  /** The error object from useQuery */
  error: Error | unknown
  /** Optional retry function - if provided, shows a retry button */
  onRetry?: () => void
  /** Optional custom title - defaults to translated "Failed to load data" */
  title?: string
  /** Optional custom message - overrides error message extraction */
  message?: string
  /** Whether to show in compact mode (smaller padding, no icon) */
  compact?: boolean
  /** Optional className for the container */
  className?: string
}

/**
 * Displays a user-friendly error message for failed queries.
 * Use this component when a useQuery hook returns an error state.
 *
 * @example
 * ```tsx
 * const { data, isLoading, error, refetch } = useQuery({...})
 *
 * if (isLoading) return <LoadingSpinner />
 * if (error) return <QueryError error={error} onRetry={refetch} />
 * ```
 */
export function QueryError({
  error,
  onRetry,
  title,
  message,
  compact = false,
  className = '',
}: QueryErrorProps) {
  const { t } = useTranslation()
  const resolvedTitle = title ?? t('errors.failedToLoad')
  const errorMessage = message ?? getErrorMessage(error)

  if (compact) {
    return (
      <div
        className={`flex items-center justify-between p-3 ${colorTokens.intent.danger.bgSubtle} border ${colorTokens.intent.danger.borderSubtle} rounded-md ${className}`}
      >
        <div className={`flex items-center gap-2 ${colorTokens.intent.danger.textStrong}`}>
          <AlertCircle className="w-4 h-4 flex-shrink-0" />
          <span className="text-sm">{errorMessage}</span>
        </div>
        {onRetry && (
          <button
            onClick={onRetry}
            className={`inline-flex items-center px-2 py-1 text-xs font-medium ${colorTokens.intent.danger.textStrong} ${colorTokens.variants.hoverTextRed800} ${colorTokens.variants.hoverBgRed100} rounded`}
          >
            <RefreshCw className="w-3 h-3 me-1" />
            {t('retry')}
          </button>
        )}
      </div>
    )
  }

  return (
    <div
      className={`flex flex-col items-center justify-center p-8 text-center ${className}`}
    >
      <div className={`w-12 h-12 rounded-full ${colorTokens.intent.danger.bgSoft} flex items-center justify-center mb-4`}>
        <AlertCircle className={`w-6 h-6 ${colorTokens.intent.danger.text}`} />
      </div>

      <h3 className={`text-lg font-medium ${colorTokens.text.primary} mb-2`}>{resolvedTitle}</h3>

      <p className={`${colorTokens.text.muted} mb-4 max-w-md`}>{errorMessage}</p>

      {onRetry && (
        <button
          onClick={onRetry}
          className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-md ${colorTokens.variants.hoverBgBlue700} focus:outline-none focus:ring-2 focus:ring-offset-2 ${colorTokens.focus.primaryRing}`}
        >
          <RefreshCw className="w-4 h-4 me-2" />
          {t('actions.tryAgain')}
        </button>
      )}
    </div>
  )
}

/**
 * Inline error display for use within forms or smaller areas.
 */
export function InlineError({
  message,
  className = '',
}: {
  message: string
  className?: string
}) {
  return (
    <div
      className={`flex items-center gap-2 ${colorTokens.intent.danger.text} text-sm ${className}`}
    >
      <AlertCircle className="w-4 h-4 flex-shrink-0" />
      <span>{message}</span>
    </div>
  )
}
