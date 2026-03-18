import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useAuthStore } from '../stores/authStore'

declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo: Echo<'reverb'> | null
  }
}

// Make Pusher available globally (required by Laravel Echo)
window.Pusher = Pusher

/**
 * Laravel Echo configuration for WebSocket connections.
 *
 * Connects through same-origin nginx proxy (/app/ and /broadcasting/auth)
 * so no separate WS host/port env vars are needed in production.
 * Authentication uses Bearer token to work behind reverse proxies.
 */
export function createEchoInstance(): Echo<'reverb'> {
  const token = useAuthStore.getState().token

  return new Echo({
    broadcaster: 'reverb',
    key: import.meta.env['VITE_REVERB_APP_KEY'] || 'local_key',
    wsHost: window.location.hostname,
    wsPort: window.location.port ? parseInt(window.location.port) : 80,
    wssPort: window.location.port ? parseInt(window.location.port) : 443,
    forceTLS: window.location.protocol === 'https:',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    },
  })
}

/**
 * Get or create the global Echo instance.
 * Lazily initializes on first access.
 */
export function getEcho(): Echo<'reverb'> {
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
