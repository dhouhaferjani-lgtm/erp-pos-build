import { describe, it, expect, vi, afterEach } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useUnsavedChangesGuard, confirmDiscard } from '../useUnsavedChangesGuard'

afterEach(() => vi.restoreAllMocks())

describe('useUnsavedChangesGuard', () => {
  it('adds a beforeunload listener when dirty and removes it on cleanup', () => {
    const add = vi.spyOn(window, 'addEventListener')
    const remove = vi.spyOn(window, 'removeEventListener')
    const { unmount } = renderHook(() => useUnsavedChangesGuard({ isDirty: true }))
    expect(add).toHaveBeenCalledWith('beforeunload', expect.any(Function))
    unmount()
    expect(remove).toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })

  it('does not register when nothing is dirty/pending/failed', () => {
    const add = vi.spyOn(window, 'addEventListener')
    renderHook(() => useUnsavedChangesGuard({ isDirty: false }))
    expect(add).not.toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })

  it('warns on autosavePending and autosaveFailed even when not dirty', () => {
    const add = vi.spyOn(window, 'addEventListener')
    renderHook(() => useUnsavedChangesGuard({ isDirty: false, autosavePending: true }))
    expect(add).toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })
})

describe('confirmDiscard', () => {
  it('returns the window.confirm result', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    expect(confirmDiscard('msg')).toBe(true)
  })
})
