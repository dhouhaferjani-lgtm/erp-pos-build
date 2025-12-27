import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo: Echo | null
  }
}

// Make Pusher available globally (required by Laravel Echo)
window.Pusher = Pusher

/**
 * Laravel Echo configuration for WebSocket connections.
 *
 * Connects to Laravel Reverb server using Pusher protocol.
 * Authentication handled automatically via Sanctum cookies.
 */
export function createEchoInstance(): Echo {
  const apiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8002'
  const wsHost = import.meta.env.VITE_WS_HOST || 'localhost'
  const wsPort = parseInt(import.meta.env.VITE_WS_PORT || '8080', 10)
  const wssPort = parseInt(import.meta.env.VITE_WSS_PORT || '6001', 10)
  const forceTLS = import.meta.env.VITE_WS_FORCE_TLS === 'true'

  return new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'local_key',
    wsHost,
    wsPort,
    wssPort,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
    // Authentication endpoint for private channels
    authEndpoint: `${apiUrl}/broadcasting/auth`,
    // Sanctum uses cookies for auth, so include credentials
    auth: {
      headers: {
        Accept: 'application/json',
      },
    },
    // Add credentials to enable cookie-based auth
    // @ts-expect-error - Pusher types don't include withCredentials but it's valid
    withCredentials: true,
  })
}

/**
 * Get or create the global Echo instance.
 * Lazily initializes on first access.
 */
export function getEcho(): Echo {
  if (!window.Echo) {
    window.Echo = createEchoInstance()
  }
  return window.Echo
}

/**
 * Disconnect and clean up the Echo instance.
 * Useful for logout or cleanup scenarios.
 */
export function disconnectEcho(): void {
  if (window.Echo) {
    window.Echo.disconnect()
    window.Echo = null
  }
}
