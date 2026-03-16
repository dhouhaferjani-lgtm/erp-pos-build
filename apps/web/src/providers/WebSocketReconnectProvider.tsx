import { useEffect, useRef, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useWebSocketConnection } from '../hooks/useWebSocketConnection'

interface WebSocketReconnectProviderProps {
  children: ReactNode
}

/**
 * Provider that invalidates all active queries when WebSocket reconnects.
 *
 * Handles the gap recovery problem: when WebSocket drops and reconnects,
 * clients may have missed events. Invalidating all queries on reconnect
 * ensures stale data is refetched from the database (source of truth).
 */
export function WebSocketReconnectProvider({ children }: WebSocketReconnectProviderProps) {
  const { isConnected } = useWebSocketConnection()
  const queryClient = useQueryClient()
  const wasDisconnected = useRef(false)

  useEffect(() => {
    if (!isConnected) {
      wasDisconnected.current = true
      return
    }

    if (wasDisconnected.current) {
      wasDisconnected.current = false
      void queryClient.invalidateQueries()
    }
  }, [isConnected, queryClient])

  return <>{children}</>
}
