import { describe, expect, it, vi } from 'vitest'

import { handleStalePreloadError } from './staleChunkReload'

function makeStorage(initial: Record<string, string> = {}): Pick<Storage, 'getItem' | 'setItem'> {
  const store = new Map(Object.entries(initial))
  return {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => {
      store.set(key, value)
    },
  }
}

function makeEvent(): Event {
  return new Event('vite:preloadError', { cancelable: true })
}

// Realistic epoch-scale base so a `last=0` (no prior reload recorded) never
// falls inside the 30s suppression window by test-data coincidence.
const BASE_NOW = 1_700_000_000_000

describe('handleStalePreloadError', () => {
  it('reloads once on the first preload error', () => {
    const reload = vi.fn()
    const storage = makeStorage()

    handleStalePreloadError(makeEvent(), { reload, now: () => BASE_NOW, storage })

    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('calls preventDefault so the router does not also show its error UI', () => {
    const event = makeEvent()
    const preventDefaultSpy = vi.spyOn(event, 'preventDefault')

    handleStalePreloadError(event, { reload: vi.fn(), now: () => BASE_NOW, storage: makeStorage() })

    expect(preventDefaultSpy).toHaveBeenCalledTimes(1)
  })

  it('records the reload timestamp in storage', () => {
    const storage = makeStorage()

    handleStalePreloadError(makeEvent(), { reload: vi.fn(), now: () => BASE_NOW, storage })

    expect(storage.getItem('autoerp-chunk-reload-at')).toBe(String(BASE_NOW))
  })

  it('suppresses a second reload within the 30s window', () => {
    const reload = vi.fn()
    const storage = makeStorage({ 'autoerp-chunk-reload-at': String(BASE_NOW) })

    handleStalePreloadError(makeEvent(), { reload, now: () => BASE_NOW + 29_999, storage })

    expect(reload).not.toHaveBeenCalled()
  })

  it('does not call preventDefault when suppressed, letting the ErrorBoundary show', () => {
    const event = makeEvent()
    const preventDefaultSpy = vi.spyOn(event, 'preventDefault')
    const storage = makeStorage({ 'autoerp-chunk-reload-at': String(BASE_NOW) })

    handleStalePreloadError(event, { reload: vi.fn(), now: () => BASE_NOW + 1_000, storage })

    expect(preventDefaultSpy).not.toHaveBeenCalled()
  })

  it('allows a reload again once the suppression window has elapsed', () => {
    const reload = vi.fn()
    const storage = makeStorage({ 'autoerp-chunk-reload-at': String(BASE_NOW) })

    handleStalePreloadError(makeEvent(), { reload, now: () => BASE_NOW + 30_000, storage })

    expect(reload).toHaveBeenCalledTimes(1)
    expect(storage.getItem('autoerp-chunk-reload-at')).toBe(String(BASE_NOW + 30_000))
  })
})
