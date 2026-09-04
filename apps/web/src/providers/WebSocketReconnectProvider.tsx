import { useEffect, useRef, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useWebSocketConnection } from '../hooks/useWebSocketConnection'

interface WebSocketReconnectProviderProps {
  children: ReactNode
}

/**
 * Minimum delay between two reconnect-driven invalidation sweeps.
 *
 * A flapping socket would otherwise replay a full active-query refetch on every
 * transition; the cooldown bounds that to one sweep per 30 seconds.
 */
const RECONNECT_INVALIDATION_COOLDOWN_MS = 30_000

/**
 * Provider that invalidates all active queries when WebSocket reconnects.
 *
 * Handles the gap recovery problem: when WebSocket drops and reconnects,
 * clients may have missed events. Invalidating all queries on reconnect
 * ensures stale data is refetched from the database (source of truth).
 *
 * Only *active* (mounted) queries are refetched, and a disconnected-to-connected
 * transition inside {@link RECONNECT_INVALIDATION_COOLDOWN_MS} of the previous
 * sweep is suppressed. There is deliberately no reference-data allowlist: gap
 * recovery must not silently skip any query. CompanySelector's own
 * `queryClient.invalidateQueries()` on company switch is a separate, intentional
 * behaviour and is untouched by this provider.
 */
export function WebSocketReconnectProvider({ children }: WebSocketReconnectProviderProps) {
  const { isConnected } = useWebSocketConnection()
  const queryClient = useQueryClient()
  const wasDisconnected = useRef(false)
  const lastInvalidationAt = useRef<number | null>(null)

  useEffect(() => {
    if (!isConnected) {
      wasDisconnected.current = true
      return
    }

    if (!wasDisconnected.current) return
    wasDisconnected.current = false

    const now = Date.now()
    if (
      lastInvalidationAt.current !== null
      && now - lastInvalidationAt.current < RECONNECT_INVALIDATION_COOLDOWN_MS
    ) {
      return
    }

    lastInvalidationAt.current = now
    void queryClient.invalidateQueries({ refetchType: 'active' })
  }, [isConnected, queryClient])

  return <>{children}</>
}
