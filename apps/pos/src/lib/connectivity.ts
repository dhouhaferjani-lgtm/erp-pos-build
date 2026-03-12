import { fetch } from '@tauri-apps/plugin-http';
import { useAuthStore } from '@/stores/authStore';

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
  } catch {
    return false;
  }
}

export function isBrowserOnline(): boolean {
  return navigator.onLine;
}
