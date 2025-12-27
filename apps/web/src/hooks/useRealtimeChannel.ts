import { useEffect, useRef } from 'react'
import { useWebSocketConnection } from './useWebSocketConnection'
import type Echo from 'laravel-echo'
import type { Channel } from 'laravel-echo'

export interface UseRealtimeChannelOptions<T> {
  /** Channel name (without 'private-' prefix for private channels) */
  channelName: string
  /** Event name to listen for */
  eventName: string
  /** Callback when event is received */
  onEvent: (data: T) => void
  /** Callback when subscription fails (e.g., authorization denied) */
  onError?: (error: Error) => void
  /** Whether to use a private channel (default: true) */
  isPrivate?: boolean
}

/**
 * Hook for subscribing to a WebSocket channel and listening to events.
 *
 * Automatically manages subscription lifecycle (subscribe on mount, unsubscribe on unmount).
 * Handles both private and public channels.
 *
 * @example
 * ```tsx
 * useRealtimeChannel({
 *   channelName: 'tenant.123.company.456.product.789',
 *   eventName: 'product.cost-price-updated',
 *   onEvent: (data) => {
 *     console.log('Product updated:', data)
 *   },
 *   onError: (err) => {
 *     console.error('Failed to subscribe:', err)
 *   },
 * })
 * ```
 */
export function useRealtimeChannel<T = unknown>(
  options: UseRealtimeChannelOptions<T>
): void {
  const { channelName, eventName, onEvent, onError, isPrivate = true } = options
  const { echo, isConnected } = useWebSocketConnection()
  const channelRef = useRef<Channel | null>(null)
  const onEventRef = useRef(onEvent)
  const onErrorRef = useRef(onError)

  // Keep refs updated
  useEffect(() => {
    onEventRef.current = onEvent
  }, [onEvent])

  useEffect(() => {
    onErrorRef.current = onError
  }, [onError])

  useEffect(() => {
    if (!echo || !isConnected) {
      return
    }

    try {
      // Subscribe to channel
      const channel = isPrivate
        ? echo.private(channelName)
        : echo.channel(channelName)

      channelRef.current = channel

      // Listen to event
      channel.listen(eventName, (data: T) => {
        onEventRef.current(data)
      })

      // Listen for subscription errors (private channels only)
      if (isPrivate) {
        channel.error((error: Error) => {
          onErrorRef.current?.(error)
        })
      }
    } catch (error) {
      onErrorRef.current?.(
        error instanceof Error ? error : new Error('Failed to subscribe to channel')
      )
    }

    return () => {
      // Unsubscribe and leave channel
      if (channelRef.current && echo) {
        channelRef.current.stopListening(eventName)
        echo.leave(channelName)
        channelRef.current = null
      }
    }
  }, [echo, isConnected, channelName, eventName, isPrivate])
}
