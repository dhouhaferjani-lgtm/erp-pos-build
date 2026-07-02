import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { act, renderHook } from '@testing-library/react'

type Listener = (payload?: unknown) => void

const listeners: Record<string, Listener[]> = {}
const fakeConnection: { state: string; bind: (evt: string, cb: Listener) => void } = {
  state: 'connecting',
  bind: (evt, cb) => {
    ;(listeners[evt] ??= []).push(cb)
  },
}
const fakeEcho = { connector: { pusher: { connection: fakeConnection } } }

vi.mock('../lib/echo', () => ({
  getEcho: () => fakeEcho,
}))

function emit(event: string, payload?: unknown) {
  act(() => {
    ;(listeners[event] ?? []).forEach((cb) => cb(payload))
  })
}

describe('useWebSocketConnection give-up behaviour', () => {
  beforeEach(() => {
    for (const key of Object.keys(listeners)) delete listeners[key]
    fakeConnection.state = 'connecting'
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('starts in a connecting state without giving up', async () => {
    const { useWebSocketConnection } = await import('./useWebSocketConnection')
    const { result } = renderHook(() => useWebSocketConnection())
    expect(result.current.isConnecting).toBe(true)
    expect(result.current.hasGivenUp).toBe(false)
  })

  it('gives up after repeated failed connection attempts (WSS unreachable)', async () => {
    const { useWebSocketConnection, MAX_CONNECTION_ATTEMPTS } = await import('./useWebSocketConnection')
    const { result } = renderHook(() => useWebSocketConnection())

    for (let i = 0; i < MAX_CONNECTION_ATTEMPTS; i++) {
      emit('unavailable')
    }

    expect(result.current.hasGivenUp).toBe(true)
    expect(result.current.isConnecting).toBe(false)
    expect(result.current.isConnected).toBe(false)
  })

  it('gives up after the fallback timeout even if no failure event fires', async () => {
    const { useWebSocketConnection, CONNECTION_GIVE_UP_MS } = await import('./useWebSocketConnection')
    const { result } = renderHook(() => useWebSocketConnection())

    act(() => {
      vi.advanceTimersByTime(CONNECTION_GIVE_UP_MS + 1)
    })

    expect(result.current.hasGivenUp).toBe(true)
    expect(result.current.isConnecting).toBe(false)
  })

  it('never gives up once the socket connects', async () => {
    const { useWebSocketConnection, CONNECTION_GIVE_UP_MS } = await import('./useWebSocketConnection')
    const { result } = renderHook(() => useWebSocketConnection())

    emit('connected')
    expect(result.current.isConnected).toBe(true)

    act(() => {
      vi.advanceTimersByTime(CONNECTION_GIVE_UP_MS + 1)
    })

    expect(result.current.hasGivenUp).toBe(false)
    expect(result.current.isConnected).toBe(true)
  })
})
