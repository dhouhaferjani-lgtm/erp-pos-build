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
   * Whether unsaved work is still owed to the server.
   *
   * True for the WHOLE window, not just the debounce: a save is scheduled, or
   * it is in flight, or the trailing slot holds a newer body that has not been
   * transmitted yet. It is cleared only when a save settles with nothing left
   * queued. `DocumentForm` feeds it straight into `shouldWarn`, so the flag
   * must not dip while any authored body is still unsent.
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
 * The newest requested save: body plus the callbacks that go with it. The single
 * trailing job re-reads this at EXECUTION time, so a save that waited behind an
 * in-flight request always carries the latest edit rather than the snapshot that
 * happened to schedule it.
 */
interface SaveRequest {
  data: DraftData
  existingDraftId: string | undefined
  onSuccess: ((draftId: string) => void) | undefined
  onError: ((error: Error) => void) | undefined
}

/**
 * The promise every caller collapsed into the current trailing slot awaits.
 */
interface PendingSlot {
  promise: Promise<void>
  resolve: () => void
  reject: (reason: unknown) => void
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

  // ID-12 (Task 14, gate r1 B-1). Strict serialization state — ONE in-flight
  // request and ONE trailing slot, never a FIFO of N:
  // - draftIdRef mirrors `draftId` SYNCHRONOUSLY so a queued save reads the id
  //   the save before it just obtained, instead of the stale render closure.
  // - inFlightRef holds the job physically on the wire (null when idle): a save
  //   requested while it is set does not start a second POST.
  // - pendingRef is a single boolean SLOT. Any number of saves requested during
  //   one flight collapse into it, and latestRequestRef keeps only the newest
  //   body, so the follow-up is exactly one POST carrying the latest edit.
  //   A FIFO would instead replay every intermediate body, and each of those
  //   successes would clear the consumer's dirty baseline while later bodies
  //   were still un-transmitted (DocumentForm's `shouldWarn`).
  // - generationRef invalidates in-flight work on reset and on unmount without
  //   discarding inFlightRef (the physical request is still out there and later
  //   work must still queue behind it).
  const draftIdRef = useRef<string | null>(null)
  const inFlightRef = useRef<Promise<void> | null>(null)
  const pendingRef = useRef(false)
  const pendingSlotRef = useRef<PendingSlot | null>(null)
  const latestRequestRef = useRef<SaveRequest | null>(null)
  const generationRef = useRef(0)

  /**
   * Start ONE physical save now and own the wire until it settles.
   *
   * Stable (no dependencies): everything it needs is read from refs at
   * execution time, so the trailing job it launches from its own settle
   * handler is never a stale closure and always carries the newest body.
   */
  const startSave = useCallback((): Promise<void> => {
    const launch: () => Promise<void> = () => {
      const generation = generationRef.current

      // Read through a call rather than the ref property directly: the property
      // is re-checked after every await, and a direct read would be narrowed
      // away by an earlier guard in this same function scope.
      const isCancelled = (): boolean =>
        isUnmountedRef.current || generation !== generationRef.current

      const run = async (): Promise<void> => {
        // The request is read HERE, not when the save was requested: a save
        // that waited in the trailing slot sends the latest edit.
        const request = latestRequestRef.current
        if (request === null || isCancelled()) return
        setIsSaving(true)
        // Nothing is persisted until this settles, so the consumer's
        // unsaved-changes guard stays armed for the whole flight.
        setAutosavePending(true)
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
              draft_id: request.existingDraftId || draftIdRef.current,
              ...request.data,
            },
          )

          if (isCancelled()) return

          draftIdRef.current = body.draft_id
          setDraftId(body.draft_id)
          setAutosaveFailed(false)
          setLastError(null)

          // Gate r1 B-1: only the save that carries the LAST unsent body may
          // disarm the guard. DocumentForm clears its dirty baseline on every
          // success, so clearing `autosavePending` here while the slot still
          // holds a newer body would leave `shouldWarn` false over work that
          // has never been transmitted. The trailing job re-arms the flag one
          // microtask later, so this guard closes a sub-commit dip rather than
          // a visible state: it is not separately falsifiable in the act
          // harness (no render commits in that window). The observable half —
          // the flag staying true from slot-fill until the trailing save
          // settles — is pinned by 'keeps autosavePending true until the
          // trailing save has been issued and settled'.
          if (!pendingRef.current) {
            setAutosavePending(false)
          }

          // Gate r1 F-5: a lineless call authored NOTHING, so there is nothing to
          // have saved. Stamping `lastSavedAt` would put a "Saved at 14:03" under
          // an editor whose content the server never took — the indicator has to
          // stay honest about that, and the next save (the one carrying a line) is
          // the first one entitled to a timestamp.
          if (body.draft_id !== null) {
            setLastSavedAt(new Date(body.saved_at))
            request.onSuccess?.(body.draft_id)
          }
        } catch (error) {
          if (isCancelled()) return
          const surfacedError = new Error(getErrorMessage(error))
          setAutosaveFailed(true)
          setLastError(surfacedError)
          if (!pendingRef.current) {
            setAutosavePending(false)
          }
          request.onError?.(surfacedError)
          console.error('Auto-save failed:', error)
        }
      }

      const job = run()
      inFlightRef.current = job
      return job.finally(() => {
        // NOTE: this settle handler deliberately lives on the promise rather
        // than in a statement-level `finally` inside `run` — a `try/finally`
        // makes the React Compiler bail out, which silently disables every
        // compiler-backed `react-hooks` ESLint rule for this whole hook.
        if (inFlightRef.current === job) {
          inFlightRef.current = null
        }

        if (!pendingRef.current) {
          if (!isCancelled()) {
            setIsSaving(false)
          }
          return
        }

        // Exactly ONE trailing save, and it re-reads latestRequestRef.
        pendingRef.current = false
        const slot = pendingSlotRef.current
        pendingSlotRef.current = null

        const trailing = launch()
        if (slot) {
          void trailing.then(slot.resolve, slot.reject)
        } else {
          void trailing.catch(() => undefined)
        }
      })
    }

    return launch()
  }, [])

  /**
   * Request a save.
   *
   * ID-12: at most one request is ever physically in flight. A save requested
   * while one is in flight does NOT start a second POST and does NOT append to a
   * queue — it fills a single trailing slot (collapsing with any other save
   * requested during the same flight) that is issued once the in-flight request
   * SETTLES, success or failure, carrying the newest body.
   */
  const performSave = useCallback((): Promise<void> => {
    if (!data || !enabled || isUnmountedRef.current) return Promise.resolve()

    // Record the newest requested body+callbacks BEFORE deciding what to do with
    // it: whether it starts now or waits in the slot, this is what gets sent.
    latestRequestRef.current = { data, existingDraftId, onSuccess, onError }

    if (inFlightRef.current !== null) {
      pendingRef.current = true
      setAutosavePending(true)

      let slot = pendingSlotRef.current
      if (slot === null) {
        let resolve: () => void = () => {}
        let reject: (reason: unknown) => void = () => {}
        const promise = new Promise<void>((res, rej) => { resolve = res; reject = rej })
        // A collapsed save is not necessarily awaited (the debounce timer calls
        // performSave un-awaited), so keep a handler attached: a trailing job
        // rejected by a throwing consumer callback must not surface as an
        // unhandled rejection. Callers awaiting `promise` still see it reject.
        void promise.catch(() => undefined)
        slot = { promise, resolve, reject }
        pendingSlotRef.current = slot
      }
      return slot.promise
    }

    return startSave()
  }, [data, enabled, existingDraftId, onError, onSuccess, startSave])

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
    // Do NOT clear inFlightRef: new work must still queue behind the physical
    // in-flight request. The generation bump makes in-flight work a no-op and
    // stops the response repopulating state or the id ref.
    generationRef.current += 1
    // The trailing slot belongs to the document that was just cleared: empty it
    // and settle anyone awaiting it, rather than sending its body afterwards.
    pendingRef.current = false
    const abandoned = pendingSlotRef.current
    pendingSlotRef.current = null
    abandoned?.resolve()
    latestRequestRef.current = null
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
      // THIS is where an unsent trailing body is discarded (gate r2 NB-r2-2:
      // the settle handler can never see an unmounted hook, because clearing
      // `pendingRef` here makes it return at its own `!pendingRef.current`
      // guard, and `performSave` refuses to refill after unmount). Awaiters are
      // settled so nothing hangs on a save that will never be sent.
      //
      // What makes the discard tolerable is narrower than it once claimed
      // (gate r2 NB-r2-1): `autosavePending` stays true while the slot is
      // occupied, and the consumer's guard warns on tab close/refresh and at
      // the two explicit in-app discard points — it does NOT block in-app
      // navigation. `useUnsavedChangesGuard.ts:9-12` defers full route blocking
      // (`useBlocker`) to a data-router migration, so a sidebar/breadcrumb
      // navigation still drops the unsent body silently. That migration is the
      // registered residual which closes the remaining window.
      pendingRef.current = false
      const abandoned = pendingSlotRef.current
      pendingSlotRef.current = null
      abandoned?.resolve()
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
