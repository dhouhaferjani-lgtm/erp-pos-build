import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { WebSocketReconnectProvider } from './WebSocketReconnectProvider'

let connected = true
vi.mock('../hooks/useWebSocketConnection', () => ({
  useWebSocketConnection: () => ({ isConnected: connected }),
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
})
