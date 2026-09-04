import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { StrictMode } from 'react'
import { renderHook, act } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { useDraftAutoSave } from '../useDraftAutoSave'
import * as api from '../../lib/api'

// The hook calls `api.post` directly (the /documents/auto-save endpoint returns
// an UNWRAPPED body, so it cannot use apiPost's data.data unwrap).
vi.mock('../../lib/api', async (importOriginal) => ({
  ...await importOriginal<typeof import('../../lib/api')>(),
  api: { post: vi.fn() },
}))
const apiPost = vi.mocked(api.api.post)

const draft = { type: 'invoice' as const, lines: [{ product_id: 'p1', quantity: 1, unit_price: 1 }] }

describe('useDraftAutoSave failure/pending state', () => {
  beforeEach(() => { vi.useFakeTimers(); apiPost.mockReset() })
  afterEach(() => { vi.useRealTimers() })

  it('exposes autosaveFailed=true and lastError when save throws', async () => {
    apiPost.mockRejectedValueOnce(new Error('boom'))
    const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 10 }))
    await act(async () => { await result.current.saveNow().catch(() => {}) })
    // Extra microtask flush to ensure React 19 concurrent scheduler has flushed state updates
    await act(() => Promise.resolve())
    expect(result.current.autosaveFailed).toBe(true)
    expect(result.current.lastError?.message).toBe('boom')
  })

  it('surfaces a typed server failure without entering a fake saved state', async () => {
    const onSuccess = vi.fn()
    const onError = vi.fn()
    const serverMessage = 'Draft auto-save failed. Please try again.'
    const response = {
      data: {
        error: {
          code: 'DRAFT_AUTO_SAVE_FAILED',
          message: serverMessage,
        },
      },
      status: 500,
      statusText: 'Internal Server Error',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }

    apiPost.mockRejectedValueOnce(new AxiosError(
      'Request failed with status code 500',
      'ERR_BAD_RESPONSE',
      undefined,
      undefined,
      response,
    ))

    const { result } = renderHook(() => useDraftAutoSave(draft, {
      debounceMs: 10,
      onError,
      onSuccess,
    }))

    await act(async () => { await result.current.saveNow() })
    await act(() => Promise.resolve())

    expect(result.current.autosaveFailed).toBe(true)
    expect(result.current.lastError?.message).toBe(serverMessage)
    expect(result.current.draftId).toBeNull()
    expect(result.current.lastSavedAt).toBeNull()
    expect(onSuccess).not.toHaveBeenCalled()
    expect(onError).toHaveBeenCalledWith(expect.objectContaining({ message: serverMessage }))
  })

  it('clears autosaveFailed and lastError after a subsequent success', async () => {
    // First attempt: failure
    apiPost.mockRejectedValueOnce(new Error('network error'))
    const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 10 }))
    await act(async () => { await result.current.saveNow().catch(() => {}) })
    await act(() => Promise.resolve())
    expect(result.current.autosaveFailed).toBe(true)
    expect(result.current.lastError?.message).toBe('network error')

    // Second attempt: success — must clear both failure fields
    // api.post returns an axios-shaped response; the hook reads `response.data`.
    apiPost.mockResolvedValueOnce({ data: { draft_id: 'd1', saved_at: new Date(0).toISOString() } })
    await act(async () => { await result.current.saveNow() })
    await act(() => Promise.resolve())
    expect(result.current.autosaveFailed).toBe(false)
    expect(result.current.lastError).toBeNull()
    expect(result.current.draftId).toBe('d1')
  })

  /**
   * N-14. A save that carries no line authors no document — the server will not
   * spend a document number on a form nobody has put a line in — and answers
   * `draft_id: null`. The hook must carry that through instead of treating it as
   * an id, and must not report a draft to `onSuccess` that does not exist.
   */
  it('carries a null draft_id through and does not report it as a saved draft', async () => {
    const onSuccess = vi.fn()
    apiPost.mockResolvedValueOnce({ data: { draft_id: null, saved_at: new Date(0).toISOString() } })

    const lineless = { type: 'invoice' as const, lines: [] }
    const { result } = renderHook(() => useDraftAutoSave(lineless, { debounceMs: 10, onSuccess }))

    await act(async () => { await result.current.saveNow() })
    await act(() => Promise.resolve())

    expect(result.current.draftId).toBeNull()
    expect(result.current.autosaveFailed).toBe(false)
    expect(onSuccess).not.toHaveBeenCalled()
    // Gate r1 F-5: nothing was authored, so nothing was "saved at" a time.
    expect(result.current.lastSavedAt).toBeNull()
  })

  it('stamps lastSavedAt again once a save actually authors a draft', async () => {
    const lineless = { type: 'invoice' as const, lines: [] }
    apiPost.mockResolvedValueOnce({ data: { draft_id: null, saved_at: new Date(0).toISOString() } })
    const { result, rerender } = renderHook(
      ({ data }) => useDraftAutoSave(data, { debounceMs: 10 }),
      { initialProps: { data: lineless as typeof draft } }
    )
    await act(async () => { await result.current.saveNow() })
    await act(() => Promise.resolve())
    expect(result.current.lastSavedAt).toBeNull()

    apiPost.mockResolvedValueOnce({ data: { draft_id: 'd9', saved_at: new Date(0).toISOString() } })
    rerender({ data: draft })
    await act(async () => { await result.current.saveNow() })
    await act(() => Promise.resolve())

    expect(result.current.draftId).toBe('d9')
    expect(result.current.lastSavedAt).toEqual(new Date(0))
  })
})

/**
 * ID-12 (Task 14). Draft autosave must be STRICTLY SERIALIZED: an autosave that
 * is physically in flight gates the next one. A change made while a request is
 * in flight is queued as exactly one trailing save issued after the in-flight
 * one settles — success or failure — and two concurrent POSTs for the same
 * draft must never exist.
 */
describe('useDraftAutoSave strict serialization', () => {
  beforeEach(() => { vi.useFakeTimers(); apiPost.mockReset() })
  afterEach(() => { vi.useRealTimers() })

  /**
   * Gate r1 B-1. Three concurrent callers, one flight: the second and third
   * COLLAPSE into the single trailing slot, so the wire sees 2 POSTs, not 3 —
   * and the trailing one carries the draft id the first save just obtained.
   * Every caller's promise still settles with that trailing save.
   */
  it('collapses three concurrent callers into one in-flight save plus one trailing save', async () => {
    let resolveFirst: ((value: {
      data: { draft_id: string | null; saved_at: string }
    }) => void) = () => {}
    apiPost
      .mockImplementationOnce(() => new Promise<{
        data: { draft_id: string | null; saved_at: string }
      }>((resolve) => { resolveFirst = resolve }))
      .mockResolvedValue({ data: { draft_id: 'd1', saved_at: new Date(1).toISOString() } })
    const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 100_000 }))

    // Wrapped in act(): each saveNow() drives state (isSaving / autosavePending)
    // synchronously, so calling it bare makes React log an unwrapped-update warning.
    let saves: Promise<void>[] = []
    act(() => {
      saves = [result.current.saveNow(), result.current.saveNow(), result.current.saveNow()]
    })
    await act(() => Promise.resolve())
    expect(apiPost).toHaveBeenCalledTimes(1)
    expect(apiPost.mock.calls[0]?.[1]).toEqual(expect.objectContaining({ draft_id: null, type: 'invoice' }))

    await act(async () => {
      resolveFirst({ data: { draft_id: 'd1', saved_at: new Date(0).toISOString() } })
      await Promise.all(saves)
    })
    expect(apiPost).toHaveBeenCalledTimes(2)
    expect(apiPost.mock.calls[1]?.[1]).toEqual(expect.objectContaining({ draft_id: 'd1' }))
  })

  it('continues the promise tail after a failed save', async () => {
    let rejectFirst: (reason: Error) => void = () => {}
    apiPost
      .mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectFirst = reject }))
      .mockResolvedValueOnce({ data: { draft_id: 'd2', saved_at: new Date(0).toISOString() } })
    const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 100_000 }))

    let first!: Promise<void>
    let second!: Promise<void>
    act(() => { first = result.current.saveNow(); second = result.current.saveNow() })
    await act(() => Promise.resolve())
    expect(apiPost).toHaveBeenCalledTimes(1)

    await act(async () => {
      rejectFirst(new Error('first failed'))
      await Promise.all([first, second])
    })
    expect(apiPost).toHaveBeenCalledTimes(2)
    expect(result.current.draftId).toBe('d2')
  })

  it('runs the next save even when the onError callback throws', async () => {
    let rejectFirst: (reason: Error) => void = () => {}
    const onError = vi.fn(() => { throw new Error('onError failed') })
    apiPost
      .mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectFirst = reject }))
      .mockResolvedValueOnce({
        data: { draft_id: 'after-callback-error', saved_at: new Date(0).toISOString() },
      })
    const { result } = renderHook(() => useDraftAutoSave(draft, {
      debounceMs: 100_000,
      onError,
    }))

    let first!: Promise<void>
    let second!: Promise<void>
    act(() => { first = result.current.saveNow(); second = result.current.saveNow() })
    await act(() => Promise.resolve())
    expect(apiPost).toHaveBeenCalledTimes(1)

    await act(async () => {
      rejectFirst(new Error('request failed'))
      await expect(first).rejects.toThrow('onError failed')
      await second
    })
    expect(onError).toHaveBeenCalledTimes(1)
    expect(apiPost).toHaveBeenCalledTimes(2)
    expect(result.current.draftId).toBe('after-callback-error')
  })

  it('still saves after StrictMode replays mount cleanup and setup', async () => {
    apiPost.mockResolvedValueOnce({
      data: { draft_id: 'strict-draft', saved_at: new Date(0).toISOString() },
    })
    // NOTE (deviation from the plan snippet, proven by probe): an intermediate
    // wrapper component that renders <StrictMode>{children}</StrictMode> makes
    // React double-RENDER but NOT replay effects, so the guard would be
    // vacuous. Passing StrictMode as the wrapper itself produces the real
    // setup -> cleanup -> setup replay this test exists to cover.
    const { result } = renderHook(
      () => useDraftAutoSave(draft, { debounceMs: 100_000 }),
      { wrapper: StrictMode },
    )

    await act(async () => { await result.current.saveNow() })

    expect(apiPost).toHaveBeenCalledTimes(1)
    expect(result.current.draftId).toBe('strict-draft')
  })

  it('reset clears the synchronous id and cancels queued work', async () => {
    let resolveFirst: ((value: {
      data: { draft_id: string | null; saved_at: string }
    }) => void) = () => {}
    apiPost.mockImplementationOnce(() => new Promise<{
      data: { draft_id: string | null; saved_at: string }
    }>((resolve) => { resolveFirst = resolve }))
    const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 100_000 }))
    let first!: Promise<void>
    let queued!: Promise<void>
    act(() => { first = result.current.saveNow(); queued = result.current.saveNow() })
    await act(() => Promise.resolve())
    expect(apiPost).toHaveBeenCalledTimes(1)
    act(() => { result.current.reset() })
    await act(async () => {
      resolveFirst({ data: { draft_id: 'ignored', saved_at: new Date(0).toISOString() } })
      await Promise.all([first, queued])
    })
    expect(apiPost).toHaveBeenCalledTimes(1)
    expect(result.current.draftId).toBeNull()
  })

  it('unmount prevents queued network work after the current save settles', async () => {
    let resolveFirst: ((value: {
      data: { draft_id: string | null; saved_at: string }
    }) => void) = () => {}
    apiPost.mockImplementationOnce(() => new Promise<{
      data: { draft_id: string | null; saved_at: string }
    }>((resolve) => { resolveFirst = resolve }))
    const hook = renderHook(() => useDraftAutoSave(draft, { debounceMs: 100_000 }))
    let first!: Promise<void>
    let queued!: Promise<void>
    act(() => { first = hook.result.current.saveNow(); queued = hook.result.current.saveNow() })
    await act(() => Promise.resolve())
    expect(apiPost).toHaveBeenCalledTimes(1)
    hook.unmount()
    resolveFirst({ data: { draft_id: 'ignored', saved_at: new Date(0).toISOString() } })
    await Promise.all([first, queued])
    expect(apiPost).toHaveBeenCalledTimes(1)
  })

  it('coalesces rapid data changes into one request carrying the final data', async () => {
    apiPost.mockResolvedValue({
      data: { draft_id: 'debounced-draft', saved_at: new Date(0).toISOString() },
    })
    const hook = renderHook(
      ({ data }) => useDraftAutoSave(data, { debounceMs: 250 }),
      { initialProps: { data: { ...draft, notes: 'first' } } },
    )

    act(() => { vi.advanceTimersByTime(100) })
    hook.rerender({ data: { ...draft, notes: 'second' } })
    act(() => { vi.advanceTimersByTime(100) })
    hook.rerender({ data: { ...draft, notes: 'final' } })
    act(() => { vi.advanceTimersByTime(249) })
    expect(apiPost).not.toHaveBeenCalled()

    await act(async () => {
      vi.advanceTimersByTime(1)
      await Promise.resolve()
    })
    expect(apiPost).toHaveBeenCalledTimes(1)
    expect(apiPost).toHaveBeenCalledWith('/documents/auto-save', expect.objectContaining({
      draft_id: null,
      notes: 'final',
      type: 'invoice',
    }))
  })

  /**
   * Gate r1 B-1 rename. This measures DEBOUNCE coalescing under an in-flight
   * request: the three edits below are 100 ms apart under a 250 ms debounce, so
   * the debounce — not the serializer — merges them into one scheduled save. It
   * is a lock on "the debounce still coalesces while a request is in flight",
   * NOT a proof of the single trailing slot; that guarantee is measured by
   * 'collapses edits spanning several debounce windows...' below, whose edits
   * are each a FULL debounce window apart so the debounce cannot merge them.
   */
  it('coalesces edits made within one debounce window during an in-flight request', async () => {
    let resolveFirst: ((value: {
      data: { draft_id: string | null; saved_at: string }
    }) => void) = () => {}
    apiPost
      .mockImplementationOnce(() => new Promise<{
        data: { draft_id: string | null; saved_at: string }
      }>((resolve) => { resolveFirst = resolve }))
      .mockResolvedValue({ data: { draft_id: 'inflight-draft', saved_at: new Date(0).toISOString() } })

    const hook = renderHook(
      ({ data }) => useDraftAutoSave(data, { debounceMs: 250 }),
      { initialProps: { data: { ...draft, notes: 'v0' } } },
    )

    await act(async () => { vi.advanceTimersByTime(250); await Promise.resolve() })
    expect(apiPost).toHaveBeenCalledTimes(1)

    // Three edits land while request 1 is still physically in flight.
    hook.rerender({ data: { ...draft, notes: 'v1' } })
    act(() => { vi.advanceTimersByTime(100) })
    hook.rerender({ data: { ...draft, notes: 'v2' } })
    act(() => { vi.advanceTimersByTime(100) })
    hook.rerender({ data: { ...draft, notes: 'v3' } })
    await act(async () => { vi.advanceTimersByTime(250); await Promise.resolve() })

    // Still gated behind the in-flight request — no second concurrent POST.
    expect(apiPost).toHaveBeenCalledTimes(1)

    await act(async () => {
      resolveFirst({ data: { draft_id: 'inflight-draft', saved_at: new Date(0).toISOString() } })
      await Promise.resolve()
      await Promise.resolve()
    })

    expect(apiPost).toHaveBeenCalledTimes(2)
    expect(apiPost.mock.calls[1]?.[1]).toEqual(expect.objectContaining({
      draft_id: 'inflight-draft',
      notes: 'v3',
    }))
  })

  /**
   * Gate r1 B-1 (the falsifying probe, promoted to a permanent test). The single
   * trailing SLOT, not a FIFO of N: three edits made while one request is
   * physically in flight, each a FULL debounce window apart so the debounce
   * cannot merge them, must still produce exactly ONE follow-up POST, and that
   * POST must carry the LATEST body ('v3') — not three POSTs replaying stale
   * bodies v1, v2, v3.
   */
  it('collapses edits spanning several debounce windows into one trailing save carrying the latest body', async () => {
    let resolveFirst: ((value: {
      data: { draft_id: string | null; saved_at: string }
    }) => void) = () => {}
    apiPost
      .mockImplementationOnce(() => new Promise<{
        data: { draft_id: string | null; saved_at: string }
      }>((resolve) => { resolveFirst = resolve }))
      .mockResolvedValue({ data: { draft_id: 'slot-draft', saved_at: new Date(0).toISOString() } })

    const hook = renderHook(
      ({ data }) => useDraftAutoSave(data, { debounceMs: 100 }),
      { initialProps: { data: { ...draft, notes: 'v0' } } },
    )

    await act(async () => { vi.advanceTimersByTime(100); await Promise.resolve() })
    expect(apiPost).toHaveBeenCalledTimes(1)

    // Three edits, each separated by a FULL debounce window, all while request 1
    // is still physically in flight. Each one therefore *schedules* a save.
    for (const notes of ['v1', 'v2', 'v3']) {
      hook.rerender({ data: { ...draft, notes } })
      await act(async () => { vi.advanceTimersByTime(100); await Promise.resolve() })
    }
    expect(apiPost).toHaveBeenCalledTimes(1)

    await act(async () => {
      resolveFirst({ data: { draft_id: 'slot-draft', saved_at: new Date(0).toISOString() } })
      await Promise.resolve()
      await Promise.resolve()
      await Promise.resolve()
    })

    expect(apiPost).toHaveBeenCalledTimes(2)
    expect(apiPost.mock.calls[1]?.[1]).toEqual(expect.objectContaining({
      draft_id: 'slot-draft',
      notes: 'v3',
    }))
  })

  /**
   * Gate r1 B-1, second harm: the unsaved-changes guard must stay armed while
   * anything is unsent. DocumentForm builds
   * `shouldWarn = isDirty || autosavePending || autosaveFailed` and clears the
   * RHF dirty baseline on EVERY autosave success, so `autosavePending` is the
   * only signal left once an intermediate save succeeds. It must stay true
   * while the trailing slot is occupied AND while the trailing save is itself
   * in flight — it may only clear once the last body has actually settled.
   */
  it('keeps autosavePending true until the trailing save has been issued and settled', async () => {
    let resolveFirst: ((value: {
      data: { draft_id: string | null; saved_at: string }
    }) => void) = () => {}
    let resolveTrailing: ((value: {
      data: { draft_id: string | null; saved_at: string }
    }) => void) = () => {}
    apiPost
      .mockImplementationOnce(() => new Promise<{
        data: { draft_id: string | null; saved_at: string }
      }>((resolve) => { resolveFirst = resolve }))
      .mockImplementationOnce(() => new Promise<{
        data: { draft_id: string | null; saved_at: string }
      }>((resolve) => { resolveTrailing = resolve }))
      .mockResolvedValue({ data: { draft_id: 'guard-draft', saved_at: new Date(0).toISOString() } })

    const hook = renderHook(
      ({ data }) => useDraftAutoSave(data, { debounceMs: 100 }),
      { initialProps: { data: { ...draft, notes: 'v0' } } },
    )

    await act(async () => { vi.advanceTimersByTime(100); await Promise.resolve() })
    expect(apiPost).toHaveBeenCalledTimes(1)

    for (const notes of ['v1', 'v2']) {
      hook.rerender({ data: { ...draft, notes } })
      await act(async () => { vi.advanceTimersByTime(100); await Promise.resolve() })
    }
    // Slot occupied, nothing sent for v2 yet: the guard must be armed.
    expect(hook.result.current.autosavePending).toBe(true)

    await act(async () => {
      resolveFirst({ data: { draft_id: 'guard-draft', saved_at: new Date(0).toISOString() } })
      await Promise.resolve()
      await Promise.resolve()
      await Promise.resolve()
    })

    // The v0 save succeeded — which in DocumentForm clears isDirty — but the
    // latest body is only now on the wire, so the guard must still be armed.
    expect(apiPost).toHaveBeenCalledTimes(2)
    expect(hook.result.current.autosavePending).toBe(true)
    expect(hook.result.current.isSaving).toBe(true)

    await act(async () => {
      resolveTrailing({ data: { draft_id: 'guard-draft', saved_at: new Date(0).toISOString() } })
      await Promise.resolve()
      await Promise.resolve()
      await Promise.resolve()
    })

    expect(apiPost).toHaveBeenCalledTimes(2)
    expect(hook.result.current.autosavePending).toBe(false)
    expect(hook.result.current.isSaving).toBe(false)
  })

  /**
   * Gate r1 B-1, recorded behaviour on unmount: the occupied slot is DISCARDED
   * (the body was never transmitted, and there is no component left to report a
   * failure to). That is only safe because the guard is armed while the slot is
   * occupied — `autosavePending` is true here, so DocumentForm's `shouldWarn`
   * blocks the navigation that would reach this cleanup. This test locks both
   * halves: guard armed before unmount, no network work after it.
   */
  it('arms the pending guard before unmount and discards the unsent body after it', async () => {
    let resolveFirst: ((value: {
      data: { draft_id: string | null; saved_at: string }
    }) => void) = () => {}
    apiPost
      .mockImplementationOnce(() => new Promise<{
        data: { draft_id: string | null; saved_at: string }
      }>((resolve) => { resolveFirst = resolve }))
      .mockResolvedValue({ data: { draft_id: 'unmount-draft', saved_at: new Date(0).toISOString() } })

    const hook = renderHook(
      ({ data }) => useDraftAutoSave(data, { debounceMs: 100 }),
      { initialProps: { data: { ...draft, notes: 'v0' } } },
    )

    await act(async () => { vi.advanceTimersByTime(100); await Promise.resolve() })
    expect(apiPost).toHaveBeenCalledTimes(1)

    hook.rerender({ data: { ...draft, notes: 'v1' } })
    await act(async () => { vi.advanceTimersByTime(100); await Promise.resolve() })

    // Unsent edit queued -> the beforeunload/navigation guard must be armed.
    expect(hook.result.current.autosavePending).toBe(true)

    hook.unmount()
    resolveFirst({ data: { draft_id: 'unmount-draft', saved_at: new Date(0).toISOString() } })
    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()

    // The queued 'v1' body is dropped, not sent after teardown.
    expect(apiPost).toHaveBeenCalledTimes(1)
  })
})
