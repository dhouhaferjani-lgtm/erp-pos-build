import { AlertCircle, RefreshCw } from 'lucide-react'
import { getErrorMessage } from '@/lib/api'

interface QueryErrorProps {
  /** The error object from useQuery */
  error: Error | unknown
  /** Optional retry function - if provided, shows a retry button */
  onRetry?: () => void
  /** Optional custom title */
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
  title = 'Failed to load data',
  message,
  compact = false,
  className = '',
}: QueryErrorProps) {
  const errorMessage = message ?? getErrorMessage(error)

  if (compact) {
    return (
      <div
        className={`flex items-center justify-between p-3 bg-red-50 border border-red-200 rounded-md ${className}`}
      >
        <div className="flex items-center gap-2 text-red-700">
          <AlertCircle className="w-4 h-4 flex-shrink-0" />
          <span className="text-sm">{errorMessage}</span>
        </div>
        {onRetry && (
          <button
            onClick={onRetry}
            className="inline-flex items-center px-2 py-1 text-xs font-medium text-red-700 hover:text-red-800 hover:bg-red-100 rounded"
          >
            <RefreshCw className="w-3 h-3 me-1" />
            Retry
          </button>
        )}
      </div>
    )
  }

  return (
    <div
      className={`flex flex-col items-center justify-center p-8 text-center ${className}`}
    >
      <div className="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mb-4">
        <AlertCircle className="w-6 h-6 text-red-600" />
      </div>

      <h3 className="text-lg font-medium text-gray-900 mb-2">{title}</h3>

      <p className="text-gray-600 mb-4 max-w-md">{errorMessage}</p>

      {onRetry && (
        <button
          onClick={onRetry}
          className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
        >
          <RefreshCw className="w-4 h-4 me-2" />
          Try Again
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
      className={`flex items-center gap-2 text-red-600 text-sm ${className}`}
    >
      <AlertCircle className="w-4 h-4 flex-shrink-0" />
      <span>{message}</span>
    </div>
  )
}
