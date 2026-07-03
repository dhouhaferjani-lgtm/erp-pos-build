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
})
