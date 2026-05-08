import { useAuthStore } from '@/stores/authStore';
import { serializeErrorForLog } from '@/lib/errorLogging';
import { fetchWithTimeout } from '@/lib/fetchWithTimeout';

/**
 * Health-probe timeout (T0.3 contract): kept short (5s) so a stuck probe
 * doesn't block UI degradation to "offline" mode. NOT subject to the 30s
 * receipt-POST ceiling — the probe IS what tells the rest of the app to
 * treat the server as unreachable.
 */
const HEALTH_TIMEOUT_MS = 5000;

export async function checkServerHealth(): Promise<boolean> {
  const { serverUrl } = useAuthStore.getState();
  if (!serverUrl) return false;

  try {
    // T0.3: Use fetchWithTimeout so an unresponsive server (post-connect
    // hang) bounces back at HEALTH_TIMEOUT_MS rather than waiting on
    // Tauri's connect-only timeout. A FetchTimeoutError here counts as a
    // failed probe (returns false in the catch below).
    const response = await fetchWithTimeout(`${serverUrl}/api/v1/health`, {
      method: 'GET',
      connectTimeout: HEALTH_TIMEOUT_MS,
    }, HEALTH_TIMEOUT_MS);
    return response.ok;
  } catch (error) {
    // Health check fails routinely on going-offline — log at debug so it's
    // visible during diagnosis without spamming devtools in steady state.
    console.debug('[POS][connectivity] health check failed', {
      ...serializeErrorForLog(error),
    });
    return false;
  }
}

export function isBrowserOnline(): boolean {
  return navigator.onLine;
}
