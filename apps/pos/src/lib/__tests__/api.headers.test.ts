/**
 * T1.4 — assert the POS client announces itself via `X-Client-Type:
 * pos-tauri` on every outbound request. The backend's `AuthController`
 * keys on this header to issue a 12-month per-token expiry instead of
 * the 30-day global Sanctum default. The header MUST be present on
 * `/auth/login` (where the token expiry is decided) but is also set on
 * every other request so future server-side routing decisions can key
 * on it.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const fetchWithTimeoutSpy = vi.fn();

vi.mock('@/lib/fetchWithTimeout', () => ({
  fetchWithTimeout: (...args: unknown[]) => {
    fetchWithTimeoutSpy(...args);
    // Return a minimal Response shape — { data } unwrap path.
    return Promise.resolve(
      new Response(JSON.stringify({ data: {}, meta: { timestamp: '', request_id: '' } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
  },
  FetchTimeoutError: class FetchTimeoutError extends Error {},
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({
      serverUrl: 'https://api.test',
      token: 'fake-token',
      companyId: 'company-1',
    }),
  },
}));

import { apiGet, apiPost } from '@/lib/api';

function lastFetchHeaders(): Record<string, string> {
  const calls = fetchWithTimeoutSpy.mock.calls;
  if (calls.length === 0) throw new Error('fetchWithTimeout was not called');
  const lastCall = calls[calls.length - 1] as unknown[];
  const init = lastCall[1] as RequestInit | undefined;
  return (init?.headers ?? {}) as Record<string, string>;
}

describe('T1.4 — outbound api.ts requests carry X-Client-Type: pos-tauri', () => {
  beforeEach(() => {
    fetchWithTimeoutSpy.mockClear();
  });

  it('apiGet attaches X-Client-Type: pos-tauri', async () => {
    await apiGet('/products');
    const headers = lastFetchHeaders();
    expect(headers['X-Client-Type']).toBe('pos-tauri');
  });

  it('apiPost attaches X-Client-Type: pos-tauri', async () => {
    await apiPost('/auth/login', { email: 'a@b.test', password: 'x' });
    const headers = lastFetchHeaders();
    expect(headers['X-Client-Type']).toBe('pos-tauri');
  });

  it('preserves other identity headers (Authorization, X-Company-Id, Content-Type)', async () => {
    await apiPost('/orders', { foo: 'bar' });
    const headers = lastFetchHeaders();
    expect(headers['Authorization']).toBe('Bearer fake-token');
    expect(headers['X-Company-Id']).toBe('company-1');
    expect(headers['Content-Type']).toBe('application/json');
    expect(headers['X-Client-Type']).toBe('pos-tauri');
  });
});
