import { useEffect, useState } from 'react'
import { getEcho } from '../lib/echo'
import type Echo from 'laravel-echo'

export interface WebSocketConnectionState {
  /** Laravel Echo instance (null if not connected) */
  echo: Echo<'reverb'> | null
  /** Whether the WebSocket is currently connected */
  isConnected: boolean
  /** Whether the WebSocket is attempting to connect */
  isConnecting: boolean
  /** Last connection error, if any */
  error: Error | null
  /**
   * Whether we have stopped trying to reach the realtime server. Set after a
   * handful of failed attempts (or a fallback timeout) so the UI can hide the
   * "connecting…" affordance instead of spinning forever when the WebSocket
   * endpoint is unreachable (e.g. Reverb/Pusher WSS not running locally).
   */
  hasGivenUp: boolean
}

/**
 * How many failed connection attempts (`unavailable` / `failed` transitions)
 * we tolerate before hiding the indicator.
 */
export const MAX_CONNECTION_ATTEMPTS = 3

/**
 * Fallback: give up after this long without a successful connection even if
 * the socket layer never emits an explicit failure event (it may sit in
 * `connecting` indefinitely when the endpoint is unreachable).
 */
export const CONNECTION_GIVE_UP_MS = 15000

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
    hasGivenUp: false,
  })

  useEffect(() => {
    let echo: Echo<'reverb'> | null = null
    let isMounted = true
    let failedAttempts = 0
    let giveUpTimer: ReturnType<typeof setTimeout> | null = null

    const clearGiveUpTimer = () => {
      if (giveUpTimer) {
        clearTimeout(giveUpTimer)
        giveUpTimer = null
      }
    }

    // Stop trying: hide the "connecting…" affordance. Never overrides an
    // established connection.
    const giveUp = () => {
      if (!isMounted) return
      clearGiveUpTimer()
      setState((prev) =>
        prev.isConnected
          ? prev
          : { ...prev, isConnecting: false, hasGivenUp: true },
      )
    }

    const registerFailedAttempt = () => {
      failedAttempts += 1
      if (failedAttempts >= MAX_CONNECTION_ATTEMPTS) {
        giveUp()
      }
    }

    try {
      echo = getEcho()
      const connection = echo.connector.pusher.connection

      // Listen for connection events
      connection.bind('connected', () => {
        if (isMounted) {
          failedAttempts = 0
          clearGiveUpTimer()
          setState({
            echo,
            isConnected: true,
            isConnecting: false,
            error: null,
            hasGivenUp: false,
          })
        }
      })

      connection.bind('disconnected', () => {
        if (isMounted) {
          setState((prev) => ({
            ...prev,
            isConnected: false,
            isConnecting: false,
          }))
        }
      })

      connection.bind('error', (err: Error) => {
        if (isMounted) {
          setState((prev) => ({
            ...prev,
            echo,
            isConnected: false,
            isConnecting: false,
            error: err,
          }))
        }
      })

      // Repeated unreachable/failed transitions count toward giving up so the
      // indicator stops spinning when WSS is down (common in local dev).
      connection.bind('unavailable', registerFailedAttempt)
      connection.bind('failed', registerFailedAttempt)

      // Fallback: give up after a bounded wait even if no failure event fires
      // (the socket can sit in `connecting` forever against a dead endpoint).
      giveUpTimer = setTimeout(giveUp, CONNECTION_GIVE_UP_MS)

      // Initial state
      setState({
        echo,
        isConnected: connection.state === 'connected',
        isConnecting: connection.state === 'connecting',
        error: null,
        hasGivenUp: false,
      })
    } catch (err) {
      if (isMounted) {
        // A hard init failure (no config, throwing constructor) is terminal —
        // there is nothing to retry, so hide the indicator immediately.
        setState({
          echo: null,
          isConnected: false,
          isConnecting: false,
          error: err instanceof Error ? err : new Error('Failed to initialize Echo'),
          hasGivenUp: true,
        })
      }
    }

    return () => {
      isMounted = false
      clearGiveUpTimer()
      // Note: We don't disconnect Echo here because other components might be using it
      // Call disconnectEcho() manually on logout if needed
    }
  }, [])

  return state
}
