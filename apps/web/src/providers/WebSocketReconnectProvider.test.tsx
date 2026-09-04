import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { WebSocketConnectionState } from '../hooks/useWebSocketConnection'
import { WebSocketReconnectProvider } from './WebSocketReconnectProvider'

let connected = true
vi.mock('../hooks/useWebSocketConnection', () => ({
  useWebSocketConnection: (): WebSocketConnectionState => ({
    echo: null,
    isConnected: connected,
    isConnecting: false,
    error: null,
    hasGivenUp: false,
  }),
}))

function tree(client: QueryClient, children: ReactNode = <div />) {
  return (
    <QueryClientProvider client={client}>
      <WebSocketReconnectProvider>{children}</WebSocketReconnectProvider>
    </QueryClientProvider>
  )
}

describe('WebSocketReconnectProvider', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(0)
    connected = true
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('invalidates all active queries on first reconnect and applies a 30 second cooldown', () => {
    const client = new QueryClient()
    const spy = vi.spyOn(client, 'invalidateQueries').mockResolvedValue(undefined)
    const view = render(tree(client))

    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(1)
    expect(spy).toHaveBeenLastCalledWith({ refetchType: 'active' })

    act(() => { vi.advanceTimersByTime(10_000) })
    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(1)

    act(() => { vi.advanceTimersByTime(20_000) })
    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(2)
  })

  it('ignores the initial connect: only a genuine reconnect invalidates and arms the cooldown', () => {
    connected = false
    const client = new QueryClient()
    const spy = vi.spyOn(client, 'invalidateQueries').mockResolvedValue(undefined)
    const view = render(tree(client))

    // t=1s — the first successful connect after mount. Not a reconnect: nothing
    // was missed, so no sweep and the cooldown stays unarmed.
    act(() => { vi.advanceTimersByTime(1_000) })
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(0)

    // t=15s genuine drop -> t=17s genuine reconnect: this gap must be recovered.
    act(() => { vi.advanceTimersByTime(14_000) })
    connected = false
    view.rerender(tree(client))
    act(() => { vi.advanceTimersByTime(2_000) })
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(1)
    expect(spy).toHaveBeenLastCalledWith({ refetchType: 'active' })

    // t=20s — inside the 30s cooldown armed at t=17s: suppressed.
    act(() => { vi.advanceTimersByTime(3_000) })
    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(1)

    // t=47s — exactly 30s after the sweep at t=17s: cooldown expired.
    act(() => { vi.advanceTimersByTime(27_000) })
    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(2)
  })

  /**
   * T8 re-gate r2, R2-N5. "First connect" is only a startup handshake while it
   * lands near mount. `useWebSocketConnection` gives up at
   * CONNECTION_GIVE_UP_MS = 15s without disconnecting the socket, so a client
   * can sit disconnected for tens of seconds and then connect for the first
   * time — everything published in that window was missed, and pre-fix that
   * case DID sweep. Classifying it as "initial" (the r1 fix) silently dropped
   * the recovery; the grace window restores it without bringing back the
   * login-time sweep the r1 fix removed.
   */
  it('sweeps a FIRST connect that lands after the grace window: a give-up gap is not a handshake', () => {
    connected = false
    const client = new QueryClient()
    const spy = vi.spyOn(client, 'invalidateQueries').mockResolvedValue(undefined)
    const view = render(tree(client))

    // t=20s — past the 5s grace and past the 15s give-up: the first connect
    // this client ever sees, over a gap that must be recovered.
    act(() => { vi.advanceTimersByTime(20_000) })
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(1)
    expect(spy).toHaveBeenLastCalledWith({ refetchType: 'active' })

    // ...and it ARMS the cooldown like any other sweep: a flap 3s later is
    // suppressed rather than replaying a second full refetch.
    act(() => { vi.advanceTimersByTime(3_000) })
    connected = false
    view.rerender(tree(client))
    connected = true
    view.rerender(tree(client))
    expect(spy).toHaveBeenCalledTimes(1)
  })
})
