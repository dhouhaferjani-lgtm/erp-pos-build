import { describe, it, expect, vi, afterEach } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useUnsavedChangesGuard, confirmDiscard } from '../useUnsavedChangesGuard'

afterEach(() => vi.restoreAllMocks())

describe('useUnsavedChangesGuard', () => {
  it('adds a beforeunload listener when dirty and removes the SAME handler on cleanup', () => {
    const add = vi.spyOn(window, 'addEventListener')
    const remove = vi.spyOn(window, 'removeEventListener')
    const { unmount } = renderHook(() => useUnsavedChangesGuard({ isDirty: true }))
    const addedHandler = add.mock.calls.find((c) => c[0] === 'beforeunload')?.[1]
    expect(addedHandler).toBeTypeOf('function')
    unmount()
    const removedHandler = remove.mock.calls.find((c) => c[0] === 'beforeunload')?.[1]
    expect(removedHandler).toBe(addedHandler)
  })

  it('does not register when nothing is dirty/pending/failed', () => {
    const add = vi.spyOn(window, 'addEventListener')
    renderHook(() => useUnsavedChangesGuard({ isDirty: false }))
    expect(add).not.toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })

  it('warns on autosavePending even when not dirty', () => {
    const add = vi.spyOn(window, 'addEventListener')
    renderHook(() => useUnsavedChangesGuard({ isDirty: false, autosavePending: true }))
    expect(add).toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })

  it('warns on autosaveFailed even when not dirty', () => {
    const add = vi.spyOn(window, 'addEventListener')
    renderHook(() => useUnsavedChangesGuard({ isDirty: false, autosaveFailed: true }))
    expect(add).toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })
})

describe('confirmDiscard', () => {
  it('returns true when window.confirm returns true', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    expect(confirmDiscard('msg')).toBe(true)
  })

  it('returns false when window.confirm returns false', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    expect(confirmDiscard('msg')).toBe(false)
  })
})
