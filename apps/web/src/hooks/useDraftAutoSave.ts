import { useCallback, useEffect, useRef, useState } from 'react'
import { api, getErrorMessage } from '../lib/api'

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
   * Whether a debounced save is scheduled but has not yet fired
   */
  autosavePending: boolean

  /**
   * Whether the last save attempt failed
   */
  autosaveFailed: boolean

  /**
   * The error from the last failed save attempt (null if no failure)
   */
  lastError: Error | null

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
    product_id?: string
    service_id?: string
    quantity: number | string
    free_quantity?: number | string
    unit_price: number | string
    line_total?: number | string
    price_entry_mode?: 'unit' | 'total'
    discount_percent?: string | null
    discount_amount?: string | null
    tax_rate?: number | string
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
  const [autosavePending, setAutosavePending] = useState(false)
  const [autosaveFailed, setAutosaveFailed] = useState(false)
  const [lastError, setLastError] = useState<Error | null>(null)

  const debounceTimerRef = useRef<NodeJS.Timeout | null>(null)
  const isUnmountedRef = useRef(false)

  // ID-12 (Task 14). Strict serialization state.
  // - draftIdRef mirrors `draftId` SYNCHRONOUSLY so a queued save reads the id
  //   the save before it just obtained, instead of the stale render closure.
  // - tailRef is the promise tail every save chains onto: one physical request
  //   at a time for the same draft, never two concurrent POSTs.
  // - generationRef invalidates already-queued and in-flight work on reset and
  //   on unmount without discarding the tail (the physical request is still
  //   out there and later work must still queue behind it).
  const draftIdRef = useRef<string | null>(null)
  const tailRef = useRef<Promise<void>>(Promise.resolve())
  const generationRef = useRef(0)

  /**
   * Perform the actual save operation.
   *
   * ID-12: every call is appended to a strict promise tail. The job body only
   * begins once the previous job has SETTLED (fulfilled or rejected), so an
   * autosave that is in flight gates the next one and the endpoint never sees
   * two concurrent writes for the same draft.
   */
  const performSave = useCallback((): Promise<void> => {
    if (!data || !enabled || isUnmountedRef.current) return Promise.resolve()
    const generation = generationRef.current

    // Read through a call rather than the ref property directly: the property
    // is re-checked after every await, and a direct read would be narrowed away
    // by the earlier guard in this same function scope.
    const isCancelled = (): boolean =>
      isUnmountedRef.current || generation !== generationRef.current

    const run = async (): Promise<void> => {
      // This executes only after the previous tail settles: re-check lifecycle.
      if (isCancelled()) return
      setIsSaving(true)
      try {
        // NOTE: /documents/auto-save returns an UNWRAPPED body
        // ({ draft_id, saved_at, line_count }) — it does NOT use the standard
        // { data: ... } envelope. So we must use `api.post` and read
        // `response.data` directly; `apiPost` (which unwraps `response.data.data`)
        // yields `undefined` here and crashes on `.draft_id` (every auto-save,
        // even server-side successful ones).
        // N-14: `draft_id` is NULLABLE. A payload with no line authors no
        // document — the server refuses to spend a number on a form nobody has
        // put a line in — and answers 200 with `draft_id: null`. The debounced
        // effect below never produces that payload, but `saveNow()` is callable
        // directly and has no line guard of its own, so the type has to be honest.
        const { data: body } = await api.post<{ draft_id: string | null; saved_at: string }>(
          '/documents/auto-save',
          {
            draft_id: existingDraftId || draftIdRef.current,
            ...data,
          },
        )

        if (isCancelled()) return

        draftIdRef.current = body.draft_id
        setDraftId(body.draft_id)
        setAutosavePending(false)
        setAutosaveFailed(false)
        setLastError(null)

        // Gate r1 F-5: a lineless call authored NOTHING, so there is nothing to
        // have saved. Stamping `lastSavedAt` would put a "Saved at 14:03" under
        // an editor whose content the server never took — the indicator has to
        // stay honest about that, and the next save (the one carrying a line) is
        // the first one entitled to a timestamp.
        if (body.draft_id !== null) {
          setLastSavedAt(new Date(body.saved_at))
          onSuccess?.(body.draft_id)
        }
      } catch (error) {
        if (isCancelled()) return
        const surfacedError = new Error(getErrorMessage(error))
        setAutosaveFailed(true)
        setLastError(surfacedError)
        setAutosavePending(false)
        onError?.(surfacedError)
        console.error('Auto-save failed:', error)
      }
    }

    // Chain from a SWALLOWED predecessor: a rejected tail (a failed request, or
    // a consumer `onError` that itself throws) must not poison the queue. The
    // original rejection still reaches that job's own caller through `scheduled`.
    tailRef.current = tailRef.current.catch(() => undefined).then(run)
    const scheduled: Promise<void> = tailRef.current
    return scheduled.finally(() => {
      // Only the LAST job in the tail owns the tail slot and the spinner: an
      // intermediate job that settles while another is already queued must not
      // claim the editor is idle.
      // NOTE: this ownership check deliberately lives on the promise rather
      // than in a statement-level `finally` inside `run` — a `try/finally`
      // makes the React Compiler bail out, which silently disables every
      // compiler-backed `react-hooks` ESLint rule for this whole hook.
      if (tailRef.current !== scheduled) return
      tailRef.current = Promise.resolve()
      if (!isCancelled()) {
        setIsSaving(false)
      }
    })
  }, [data, enabled, existingDraftId, onError, onSuccess])

  /**
   * Save immediately without debounce
   */
  const saveNow = useCallback(async () => {
    // Clear any pending debounced save
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current)
      debounceTimerRef.current = null
      setAutosavePending(false)
    }

    await performSave()
  }, [performSave])

  /**
   * Reset the auto-save state
   */
  const reset = useCallback(() => {
    // Do NOT replace tailRef: new work must still queue behind the physical
    // in-flight request. The generation bump makes already queued work a no-op
    // and stops the in-flight response repopulating state or the id ref.
    generationRef.current += 1
    draftIdRef.current = null
    setDraftId(null)
    setLastSavedAt(null)
    setIsSaving(false)
    setAutosavePending(false)
    setAutosaveFailed(false)
    setLastError(null)

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
    setAutosavePending(true)
    debounceTimerRef.current = setTimeout(() => {
      performSave()
    }, debounceMs)

    // Cleanup
    return () => {
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current)
        setAutosavePending(false)
      }
    }
  }, [data, enabled, debounceMs, performSave])

  /**
   * Cleanup on unmount
   */
  useEffect(() => {
    // Required for React StrictMode's development setup -> cleanup -> setup
    // replay: without this the replayed cleanup would leave the hook
    // permanently marked unmounted and every later save would no-op.
    isUnmountedRef.current = false

    return () => {
      isUnmountedRef.current = true
      generationRef.current += 1
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current)
      }
    }
  }, [])

  return {
    draftId,
    isSaving,
    lastSavedAt,
    autosavePending,
    autosaveFailed,
    lastError,
    saveNow,
    reset,
  }
}
