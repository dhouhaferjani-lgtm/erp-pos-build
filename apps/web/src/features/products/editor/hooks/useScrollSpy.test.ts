import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useScrollSpy } from './useScrollSpy'

type IntersectionObserverCallback = (entries: IntersectionObserverEntry[]) => void
type IntersectionObserverInit = { rootMargin?: string; threshold?: number | number[] }

let capturedCallback: IntersectionObserverCallback | null = null
let capturedOptions: IntersectionObserverInit | null = null
let observeSpy: ReturnType<typeof vi.fn>
let disconnectSpy: ReturnType<typeof vi.fn>

function makeMockIntersectionObserver() {
  observeSpy = vi.fn()
  disconnectSpy = vi.fn()

  class MockIntersectionObserver {
    constructor(callback: IntersectionObserverCallback, options?: IntersectionObserverInit) {
      capturedCallback = callback
      capturedOptions = options ?? null
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
    capturedOptions = null
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
        {
          isIntersecting: true,
          target: { id: 'b' },
          boundingClientRect: { top: 100 },
        } as unknown as IntersectionObserverEntry,
      ])
    })

    expect(result.current).toBe('b')
  })

  it('updates to the most recently intersecting element', () => {
    const { result } = renderHook(() => useScrollSpy(['a', 'b', 'c']))

    act(() => {
      capturedCallback?.([
        {
          isIntersecting: true,
          target: { id: 'a' },
          boundingClientRect: { top: 50 },
        } as unknown as IntersectionObserverEntry,
      ])
    })
    expect(result.current).toBe('a')

    act(() => {
      capturedCallback?.([
        {
          isIntersecting: true,
          target: { id: 'c' },
          boundingClientRect: { top: 50 },
        } as unknown as IntersectionObserverEntry,
      ])
    })
    expect(result.current).toBe('c')
  })

  it('ignores entries where isIntersecting is false', () => {
    const { result } = renderHook(() => useScrollSpy(['a', 'b']))

    act(() => {
      capturedCallback?.([
        {
          isIntersecting: true,
          target: { id: 'a' },
          boundingClientRect: { top: 50 },
        } as unknown as IntersectionObserverEntry,
      ])
    })
    expect(result.current).toBe('a')

    act(() => {
      capturedCallback?.([
        {
          isIntersecting: false,
          target: { id: 'b' },
          boundingClientRect: { top: 50 },
        } as unknown as IntersectionObserverEntry,
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

  it('picks the topmost visible section when multiple entries intersect in the same batch', () => {
    const { result } = renderHook(() => useScrollSpy(['a', 'b']))

    act(() => {
      capturedCallback?.([
        // 'a' is lower on screen (larger top value)
        {
          isIntersecting: true,
          target: { id: 'a' },
          boundingClientRect: { top: 300 },
        } as unknown as IntersectionObserverEntry,
        // 'b' is higher on screen (smaller top value) — should win
        {
          isIntersecting: true,
          target: { id: 'b' },
          boundingClientRect: { top: 80 },
        } as unknown as IntersectionObserverEntry,
      ])
    })

    expect(result.current).toBe('b')
  })

  it('forwards the rootMargin option to the IntersectionObserver constructor', () => {
    const customMargin = '-20% 0px -60% 0px'
    renderHook(() => useScrollSpy(['a', 'b'], { rootMargin: customMargin }))

    expect(capturedOptions).not.toBeNull()
    expect(capturedOptions?.rootMargin).toBe(customMargin)
  })
})
