import { useEffect, useRef, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { useWebSocketConnection } from '../../../hooks/useWebSocketConnection'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import {
  useImportProgressStore,
  type ImportStatus,
} from '../../../stores/importProgressStore'
import type { Channel } from 'laravel-echo'

/**
 * Import progress event payload from WebSocket.
 * Matches backend ImportProgressBroadcast payload.
 */
export interface ImportProgressPayload {
  import_job_id: string
  status: ImportStatus
  total_rows: number
  processed_rows: number
  successful_rows: number
  failed_rows: number
  progress_percentage: number
  import_type: string
  original_filename: string
}

/**
 * Import completed event payload from WebSocket.
 * Matches backend ImportCompletedBroadcast payload.
 */
export interface ImportCompletedPayload {
  import_job_id: string
  status: ImportStatus
  total_rows: number
  successful_rows: number
  failed_rows: number
  import_type: string
  original_filename: string
  error_message?: string
  completed_at: string
  is_success: boolean
  is_partial_success: boolean
}

export interface UseImportProgressOptions {
  /** Whether to enable real-time updates (default: true) */
  enabled?: boolean
  /** Callback when import progress is updated */
  onProgress?: (data: ImportProgressPayload) => void
  /** Callback when import is completed */
  onCompleted?: (data: ImportCompletedPayload) => void
  /** Callback when subscription fails */
  onError?: (error: Error) => void
}

/**
 * Hook for subscribing to real-time import progress updates.
 *
 * This hook should be mounted at the app root level to receive
 * import progress updates globally across all pages.
 *
 * Subscribes to:
 * - `import.progress` - Real-time progress updates during import
 * - `import.completed` - Final status when import finishes
 *
 * @example
 * ```tsx
 * // In App.tsx or main layout
 * function App() {
 *   useImportProgress({
 *     onCompleted: (data) => {
 *       if (data.is_success) {
 *         toast.success(`Import completed: ${data.successful_rows} records`)
 *       } else {
 *         toast.error(`Import failed: ${data.error_message}`)
 *       }
 *     },
 *   })
 *
 *   return <AppRoutes />
 * }
 * ```
 */
export function useImportProgress(options: UseImportProgressOptions = {}): void {
  const { enabled = true, onProgress, onCompleted, onError } = options
  const { t } = useTranslation()
  const { echo, isConnected } = useWebSocketConnection()
  const { user } = useAuthStore()
  const { currentCompanyId } = useCompanyStore()
  const { updateProgress, completeImport } = useImportProgressStore()

  const channelRef = useRef<Channel | null>(null)
  const onProgressRef = useRef(onProgress)
  const onCompletedRef = useRef(onCompleted)
  const onErrorRef = useRef(onError)

  // Keep refs updated
  useEffect(() => {
    onProgressRef.current = onProgress
  }, [onProgress])

  useEffect(() => {
    onCompletedRef.current = onCompleted
  }, [onCompleted])

  useEffect(() => {
    onErrorRef.current = onError
  }, [onError])

  const handleProgress = useCallback(
    (data: ImportProgressPayload) => {
      updateProgress(data)
      onProgressRef.current?.(data)
    },
    [updateProgress]
  )

  const handleCompleted = useCallback(
    (data: ImportCompletedPayload) => {
      completeImport(data)
      onCompletedRef.current?.(data)
    },
    [completeImport]
  )

  // Only subscribe if we have the required auth context
  const shouldSubscribe = Boolean(
    enabled && echo && isConnected && user && currentCompanyId
  )

  useEffect(() => {
    if (!shouldSubscribe || !echo || !user || !currentCompanyId) {
      return
    }

    const channelName = `tenant.${user.tenant_id}.company.${currentCompanyId}.imports`

    try {
      // Subscribe to private channel
      const channel = echo.private(channelName)
      channelRef.current = channel

      // Listen for progress events
      // Note: Leading dot is required when using broadcastAs() in Laravel
      channel.listen('.import.progress', (data: ImportProgressPayload) => {
        handleProgress(data)
      })

      // Listen for completion events
      channel.listen('.import.completed', (data: ImportCompletedPayload) => {
        handleCompleted(data)
      })

      // Listen for subscription errors
      channel.error((error: Error) => {
        console.error('Import WebSocket subscription error:', error)
        toast.error(t('common:errors.realtimeConnectionFailed'))
        onErrorRef.current?.(error)
      })
    } catch (error) {
      const err = error instanceof Error ? error : new Error('Failed to subscribe to import channel')
      console.error('Import WebSocket subscription error:', err)
      toast.error(t('common:errors.realtimeConnectionFailed'))
      onErrorRef.current?.(err)
    }

    return () => {
      // Unsubscribe and leave channel
      if (channelRef.current && echo) {
        channelRef.current.stopListening('.import.progress')
        channelRef.current.stopListening('.import.completed')
        echo.leave(`tenant.${user.tenant_id}.company.${currentCompanyId}.imports`)
        channelRef.current = null
      }
    }
  }, [shouldSubscribe, echo, user, currentCompanyId, handleProgress, handleCompleted])
}
