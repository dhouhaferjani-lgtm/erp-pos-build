import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useScrollSpy } from './useScrollSpy'

type IntersectionObserverCallback = (entries: IntersectionObserverEntry[]) => void

let capturedCallback: IntersectionObserverCallback | null = null
let observeSpy: ReturnType<typeof vi.fn>
let disconnectSpy: ReturnType<typeof vi.fn>

function makeMockIntersectionObserver() {
  observeSpy = vi.fn()
  disconnectSpy = vi.fn()

  class MockIntersectionObserver {
    constructor(callback: IntersectionObserverCallback) {
      capturedCallback = callback
    }
    observe = observeSpy
    unobserve = vi.fn()
    disconnect = disconnectSpy
  }

  return MockIntersectionObserver as unknown as typeof IntersectionObserver
}

describe('useScrollSpy', () => {
  beforeEach(() => {
    capturedCallback = null
    global.IntersectionObserver = makeMockIntersectionObserver()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('returns null before any intersection fires', () => {
    const { result } = renderHook(() => useScrollSpy(['a', 'b', 'c']))
    expect(result.current).toBeNull()
  })

  it('returns the id of the intersecting element when fired', () => {
    const { result } = renderHook(() => useScrollSpy(['a', 'b', 'c']))

    act(() => {
      capturedCallback?.([
        { isIntersecting: true, target: { id: 'b' } } as unknown as IntersectionObserverEntry,
      ])
    })

    expect(result.current).toBe('b')
  })

  it('updates to the most recently intersecting element', () => {
    const { result } = renderHook(() => useScrollSpy(['a', 'b', 'c']))

    act(() => {
      capturedCallback?.([
        { isIntersecting: true, target: { id: 'a' } } as unknown as IntersectionObserverEntry,
      ])
    })
    expect(result.current).toBe('a')

    act(() => {
      capturedCallback?.([
        { isIntersecting: true, target: { id: 'c' } } as unknown as IntersectionObserverEntry,
      ])
    })
    expect(result.current).toBe('c')
  })

  it('ignores entries where isIntersecting is false', () => {
    const { result } = renderHook(() => useScrollSpy(['a', 'b']))

    act(() => {
      capturedCallback?.([
        { isIntersecting: true, target: { id: 'a' } } as unknown as IntersectionObserverEntry,
      ])
    })
    expect(result.current).toBe('a')

    act(() => {
      capturedCallback?.([
        { isIntersecting: false, target: { id: 'b' } } as unknown as IntersectionObserverEntry,
      ])
    })
    // Should remain 'a' since b is not intersecting
    expect(result.current).toBe('a')
  })

  it('disconnects the observer on unmount', () => {
    const { unmount } = renderHook(() => useScrollSpy(['a', 'b']))
    unmount()
    expect(disconnectSpy).toHaveBeenCalledOnce()
  })
})
