import { fetch } from '@tauri-apps/plugin-http';
import { useAuthStore } from '@/stores/authStore';
import { serializeErrorForLog } from '@/lib/errorLogging';

const HEALTH_TIMEOUT_MS = 5000;

export async function checkServerHealth(): Promise<boolean> {
  const { serverUrl } = useAuthStore.getState();
  if (!serverUrl) return false;

  try {
    const response = await fetch(`${serverUrl}/api/v1/health`, {
      method: 'GET',
      connectTimeout: HEALTH_TIMEOUT_MS,
    });
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
