import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { ChannelAuthorizationCallback } from 'pusher-js';
import { fetch } from '@tauri-apps/plugin-http';
import { useAuthStore } from '@/stores/authStore';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo: Echo<'reverb'> | null;
  }
}

// Make Pusher available globally (required by Laravel Echo)
window.Pusher = Pusher;

/**
 * Custom authorizer for Tauri POS app.
 *
 * Unlike the web app which uses cookie-based Sanctum auth (withCredentials),
 * the Tauri app uses Bearer tokens. We use @tauri-apps/plugin-http fetch
 * to POST to /broadcasting/auth with the Authorization header.
 */
function createTauriAuthorizer(authEndpoint: string) {
  return (channel: { name: string }) => ({
    authorize: async (socketId: string, callback: ChannelAuthorizationCallback) => {
      const { token, companyId } = useAuthStore.getState();
      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      };
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }
      if (companyId) {
        headers['X-Company-Id'] = companyId;
      }

      try {
        console.log(`[Echo] Authorizing channel="${channel.name}" socketId="${socketId}"`);
        const response = await fetch(authEndpoint, {
          method: 'POST',
          headers,
          body: JSON.stringify({
            socket_id: socketId,
            channel_name: channel.name,
          }),
        });

        if (!response.ok) {
          const body = await response.text().catch(() => '(no body)');
          console.warn(`[Echo] Auth failed for "${channel.name}" — ${String(response.status)}: ${body}`);
          callback(new Error(`Auth failed (${String(response.status)})`), null);
          return;
        }

        const data = (await response.json()) as { auth: string; channel_data?: string };
        console.log(`[Echo] Auth success for "${channel.name}"`);
        callback(null, data);
      } catch (err) {
        console.warn('[Echo] Auth request error:', err);
        callback(err instanceof Error ? err : new Error('Channel auth failed'), null);
      }
    },
  });
}

/**
 * Create a Laravel Echo instance configured for the Tauri POS app.
 * Uses Bearer token auth instead of cookie-based Sanctum.
 */
export function createEchoInstance(): Echo<'reverb'> {
  const { serverUrl } = useAuthStore.getState();
  if (!serverUrl) throw new Error('Server URL not configured');

  const authEndpoint = `${serverUrl}/broadcasting/auth`;

  const url = new URL(serverUrl);
  const wsHost = import.meta.env.VITE_REVERB_HOST || url.hostname;
  const wsPort = parseInt(import.meta.env.VITE_REVERB_PORT || '8085', 10);
  const wssPort = parseInt(import.meta.env.VITE_REVERB_WSS_PORT || '443', 10);
  const forceTLS = import.meta.env.VITE_REVERB_SCHEME === 'https' || url.protocol === 'https:';

  console.log(`[Echo] Creating instance — host=${wsHost} port=${wsPort} wssPort=${wssPort} forceTLS=${String(forceTLS)}`);

  return new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'local_key',
    wsHost,
    wsPort,
    wssPort,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
    authEndpoint,
    authorizer: createTauriAuthorizer(authEndpoint),
  });
}

/**
 * Get or create the global Echo instance.
 * Lazily initializes on first access.
 */
export function getEcho(): Echo<'reverb'> {
  if (!window.Echo) {
    window.Echo = createEchoInstance();
  }
  return window.Echo;
}

/**
 * Read the existing Echo instance without lazily creating one. Use this
 * in cleanup paths (e.g. effect teardown after logout) where calling
 * `getEcho()` would risk re-creating a fresh unauthenticated WebSocket
 * just to leave a channel that has already been torn down.
 */
export function peekEcho(): Echo<'reverb'> | null {
  return window.Echo ?? null;
}

/**
 * Disconnect and clean up the Echo instance.
 */
export function disconnectEcho(): void {
  if (window.Echo) {
    window.Echo.disconnect();
    window.Echo = null;
  }
}
