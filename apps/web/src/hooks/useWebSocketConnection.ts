import { useEffect, useState } from 'react'
import { getEcho, disconnectEcho } from '../lib/echo'
import type Echo from 'laravel-echo'

export interface WebSocketConnectionState {
  /** Laravel Echo instance (null if not connected) */
  echo: Echo | null
  /** Whether the WebSocket is currently connected */
  isConnected: boolean
  /** Whether the WebSocket is attempting to connect */
  isConnecting: boolean
  /** Last connection error, if any */
  error: Error | null
}

/**
 * Hook for managing WebSocket connection lifecycle.
 *
 * Automatically connects when component mounts and disconnects on unmount.
 * Provides connection state for UI feedback.
 *
 * @example
 * ```tsx
 * const { echo, isConnected } = useWebSocketConnection()
 *
 * if (!isConnected) {
 *   return <div>Connecting to real-time updates...</div>
 * }
 * ```
 */
export function useWebSocketConnection(): WebSocketConnectionState {
  const [state, setState] = useState<WebSocketConnectionState>({
    echo: null,
    isConnected: false,
    isConnecting: true,
    error: null,
  })

  useEffect(() => {
    let echo: Echo | null = null
    let isMounted = true

    try {
      echo = getEcho()

      // Listen for connection events
      echo.connector.pusher.connection.bind('connected', () => {
        if (isMounted) {
          setState({
            echo,
            isConnected: true,
            isConnecting: false,
            error: null,
          })
        }
      })

      echo.connector.pusher.connection.bind('disconnected', () => {
        if (isMounted) {
          setState((prev) => ({
            ...prev,
            isConnected: false,
            isConnecting: false,
          }))
        }
      })

      echo.connector.pusher.connection.bind('error', (err: Error) => {
        if (isMounted) {
          setState({
            echo,
            isConnected: false,
            isConnecting: false,
            error: err,
          })
        }
      })

      // Initial state
      setState({
        echo,
        isConnected: echo.connector.pusher.connection.state === 'connected',
        isConnecting: echo.connector.pusher.connection.state === 'connecting',
        error: null,
      })
    } catch (err) {
      if (isMounted) {
        setState({
          echo: null,
          isConnected: false,
          isConnecting: false,
          error: err instanceof Error ? err : new Error('Failed to initialize Echo'),
        })
      }
    }

    return () => {
      isMounted = false
      // Note: We don't disconnect Echo here because other components might be using it
      // Call disconnectEcho() manually on logout if needed
    }
  }, [])

  return state
}
