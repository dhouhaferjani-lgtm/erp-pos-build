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
 * How long after mount a FIRST connect still counts as the startup handshake.
 *
 * `useWebSocketConnection` gives up at `CONNECTION_GIVE_UP_MS` (15s) without
 * disconnecting the socket, so a client can sit disconnected for tens of
 * seconds and only then connect for the first time. Everything published in
 * that window was missed, which is exactly the gap this provider exists to
 * recover — so only a first connect INSIDE this window is treated as "initial"
 * (T8 re-gate r2, R2-N5). The window is deliberately shorter than the give-up
 * timeout: the real login handshake lands ~1s after mount.
 */
const INITIAL_CONNECT_GRACE_MS = 5_000

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
 *
 * The *first* connect after mount is not a reconnect — but only while it lands
 * within {@link INITIAL_CONNECT_GRACE_MS} of mount. `useWebSocketConnection`
 * starts at `isConnected: false`, so without `hasEverConnected` the initial
 * connect (~1s after login) would look like a transition: it would sweep queries
 * that had just been fetched and — worse — arm the cooldown, suppressing a
 * genuine drop/reconnect in the following 30 seconds and never recovering that
 * gap. That handshake therefore neither invalidates nor arms the cooldown. A
 * first connect that lands LATER (the give-up path) is a real gap and is swept
 * like any reconnect.
 */
export function WebSocketReconnectProvider({ children }: WebSocketReconnectProviderProps) {
  const { isConnected } = useWebSocketConnection()
  const queryClient = useQueryClient()
  const wasDisconnected = useRef(false)
  const hasEverConnected = useRef(false)
  const mountedAt = useRef<number | null>(null)
  const lastInvalidationAt = useRef<number | null>(null)

  useEffect(() => {
    // Stamped on the first effect run (mount) rather than during render:
    // `Date.now()` in a render body is an impure call (react-hooks/purity).
    const mountedAtMs = mountedAt.current ?? Date.now()
    mountedAt.current = mountedAtMs

    if (!isConnected) {
      wasDisconnected.current = true
      return
    }

    const isFirstConnect = !hasEverConnected.current
    hasEverConnected.current = true

    // The startup handshake: a first connect close to mount is not a reconnect.
    // Consume the edge without sweeping and without arming the cooldown. A
    // first connect after the grace window falls through and is treated as the
    // gap recovery it is.
    if (isFirstConnect && Date.now() - mountedAtMs < INITIAL_CONNECT_GRACE_MS) {
      wasDisconnected.current = false
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
