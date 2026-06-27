import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useDraftAutoSave } from '../useDraftAutoSave'
import * as api from '../../lib/api'

vi.mock('../../lib/api', () => ({ apiPost: vi.fn() }))
const apiPost = api.apiPost as unknown as ReturnType<typeof vi.fn>

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

  it('clears autosaveFailed after a subsequent success', async () => {
    apiPost.mockResolvedValueOnce({ draft_id: 'd1', saved_at: new Date(0).toISOString() })
    const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 10 }))
    await act(async () => { await result.current.saveNow() })
    expect(result.current.autosaveFailed).toBe(false)
    expect(result.current.draftId).toBe('d1')
  })
})
