import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useDraftAutoSave } from '../useDraftAutoSave'
import * as api from '../../lib/api'

// The hook calls `api.post` directly (the /documents/auto-save endpoint returns
// an UNWRAPPED body, so it cannot use apiPost's data.data unwrap).
vi.mock('../../lib/api', () => ({ api: { post: vi.fn() } }))
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
  })
})
