import { useCallback, useEffect, useRef, useState } from 'react'
import { apiPost } from '../lib/api'

/**
 * Configuration for draft auto-save behavior
 */
interface AutoSaveConfig {
  /**
   * Debounce delay in milliseconds (default: 3000ms = 3 seconds)
   */
  debounceMs?: number | undefined

  /**
   * Whether auto-save is enabled (default: true)
   */
  enabled?: boolean | undefined

  /**
   * Existing draft ID for updates (when editing existing drafts)
   */
  existingDraftId?: string | undefined

  /**
   * Callback fired when auto-save succeeds
   */
  onSuccess?: ((draftId: string) => void) | undefined

  /**
   * Callback fired when auto-save fails
   */
  onError?: ((error: Error) => void) | undefined
}

/**
 * Auto-save state returned by the hook
 */
interface AutoSaveState {
  /**
   * Current draft ID (null if not yet saved)
   */
  draftId: string | null

  /**
   * Whether an auto-save is currently in progress
   */
  isSaving: boolean

  /**
   * Last successful save timestamp
   */
  lastSavedAt: Date | null

  /**
   * Save the draft immediately (bypasses debounce)
   */
  saveNow: () => Promise<void>

  /**
   * Reset the auto-save state (clears draft ID)
   */
  reset: () => void
}

/**
 * Draft document data structure for auto-save
 */
interface DraftData {
  type: 'quote' | 'sales_order' | 'invoice' | 'purchase_order' | 'delivery_note' | 'credit_note' | 'return_note'
  partner_id?: string | null
  lines?: Array<{
    id?: string
    product_id: string
    quantity: number
    unit_price: number
    tax_rate?: number
  }>
  notes?: string | null
  document_date?: string
  due_date?: string | null
}

/**
 * Custom hook for auto-saving draft documents.
 *
 * Features:
 * - Automatic debouncing (default 3 seconds)
 * - Graceful error handling
 * - Draft ID tracking
 * - Manual save trigger
 * - Save status indicators
 *
 * @example
 * ```tsx
 * const { draftId, isSaving, lastSavedAt, saveNow } = useDraftAutoSave(
 *   draftData,
 *   { debounceMs: 3000 }
 * )
 * ```
 */
export function useDraftAutoSave(
  data: DraftData | null,
  config: AutoSaveConfig = {}
): AutoSaveState {
  const {
    debounceMs = 3000,
    enabled = true,
    existingDraftId,
    onSuccess,
    onError,
  } = config

  const [draftId, setDraftId] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const [lastSavedAt, setLastSavedAt] = useState<Date | null>(null)

  const debounceTimerRef = useRef<NodeJS.Timeout | null>(null)
  const isUnmountedRef = useRef(false)

  /**
   * Perform the actual save operation
   */
  const performSave = useCallback(async () => {
    if (!data || !enabled) return

    setIsSaving(true)

    try {
      const response = await apiPost<{ draft_id: string; saved_at: string }>('/documents/auto-save', {
        draft_id: existingDraftId || draftId,
        ...data,
      })

      if (!isUnmountedRef.current) {
        setDraftId(response.draft_id)
        setLastSavedAt(new Date(response.saved_at))
        setIsSaving(false)

        onSuccess?.(response.draft_id)
      }
    } catch (error) {
      if (!isUnmountedRef.current) {
        setIsSaving(false)
        onError?.(error as Error)
      }
      // Silent failure - don't disrupt user experience
      console.error('Auto-save failed:', error)
    }
  }, [data, draftId, enabled, existingDraftId, onSuccess, onError])

  /**
   * Save immediately without debounce
   */
  const saveNow = useCallback(async () => {
    // Clear any pending debounced save
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current)
      debounceTimerRef.current = null
    }

    await performSave()
  }, [performSave])

  /**
   * Reset the auto-save state
   */
  const reset = useCallback(() => {
    setDraftId(null)
    setLastSavedAt(null)
    setIsSaving(false)

    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current)
      debounceTimerRef.current = null
    }
  }, [])

  /**
   * Auto-save effect with debouncing
   */
  useEffect(() => {
    if (!data || !enabled) return

    // Don't auto-save if data is empty/minimal - only save when there's at least one line item
    const hasMinimalData = data.lines && data.lines.length > 0
    if (!hasMinimalData) return

    // Clear existing timer
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current)
    }

    // Set new timer
    debounceTimerRef.current = setTimeout(() => {
      performSave()
    }, debounceMs)

    // Cleanup
    return () => {
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current)
      }
    }
  }, [data, enabled, debounceMs, performSave])

  /**
   * Cleanup on unmount
   */
  useEffect(() => {
    return () => {
      isUnmountedRef.current = true
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current)
      }
    }
  }, [])

  return {
    draftId,
    isSaving,
    lastSavedAt,
    saveNow,
    reset,
  }
}
