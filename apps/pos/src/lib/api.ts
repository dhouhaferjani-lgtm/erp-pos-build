import i18n from '@/lib/i18n';
import { useAuthStore } from '@/stores/authStore';
import { serializeErrorForLog } from '@/lib/errorLogging';
import { fetchWithTimeout } from '@/lib/fetchWithTimeout';

/**
 * T0.3 — default request timeout (read / overall) for non-receipt traffic.
 * Receipt sync POSTs override to 30s via the `opts.timeoutMs` parameter on
 * `apiPost`; health probes use connectivity.ts's own 5s.
 */
const DEFAULT_REQUEST_TIMEOUT_MS = 10_000;

export interface ApiResponse<T> {
  data: T;
  meta: {
    timestamp: string;
    request_id: string;
  };
}

export interface ApiError {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
  meta: {
    timestamp: string;
    request_id: string;
  };
}

export function getErrorMessage(error: unknown): string {
  if (error instanceof ApiRequestError) {
    return error.apiMessage;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return i18n.t('errors.unexpected', { ns: 'pos' });
}

export class ApiRequestError extends Error {
  constructor(
    public readonly status: number,
    public readonly apiMessage: string,
    public readonly code: string,
    public readonly details?: Record<string, unknown>,
  ) {
    super(apiMessage);
    this.name = 'ApiRequestError';
  }
}

function getBaseUrl(): string {
  const { serverUrl } = useAuthStore.getState();
  if (!serverUrl) throw new Error('Server URL not configured');
  return `${serverUrl}/api/v1`;
}

function getHeaders(): Record<string, string> {
  const { token, companyId } = useAuthStore.getState();
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    // T1.4 — announces this client to the backend's AuthController, which
    // then issues a 12-month per-token expiry on /auth/login (instead of
    // the 30-day global sanctum default). Web back-office clients omit
    // this header and stay on the default. Set on every outbound request
    // (not just /auth/login) so future server-side routing decisions
    // (e.g. preferred response shape, telemetry) can also key on it.
    'X-Client-Type': 'pos-tauri',
  };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  if (companyId) {
    headers['X-Company-Id'] = companyId;
  }
  return headers;
}

/**
 * Per-call timeout override (T0.3). Null/undefined → DEFAULT_REQUEST_TIMEOUT_MS.
 * Used by callers like the receipt-sync POST at syncService.ts that need a
 * 30s ceiling instead of the 10s default.
 *
 * T1.1 Step 1.5: `signal` lets callers thread a user-initiated cancellation
 * AbortSignal (e.g. LoginPage's Cancel-after-8s button) all the way down to
 * fetchWithTimeout, which combines it with its internal timeout controller.
 * Aborting the user signal rejects the in-flight request without the JS
 * side waiting on the 10s timeout to fire.
 */
export interface ApiRequestOptions {
  timeoutMs?: number;
  signal?: AbortSignal;
}

async function request<T>(
  method: string,
  url: string,
  body?: unknown,
  params?: Record<string, unknown>,
  opts?: ApiRequestOptions,
): Promise<T> {
  let fullUrl = `${getBaseUrl()}${url}`;

  if (params) {
    const searchParams = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null) {
        searchParams.set(key, String(value));
      }
    }
    const qs = searchParams.toString();
    if (qs) fullUrl += `?${qs}`;
  }

  // T0.3: replace the previous bare `fetch(... { connectTimeout: 10000 })`
  // with the AbortController+race wrapper. connectTimeout still bounds the
  // TCP-connect phase (Tauri plugin-http enforces it Rust-side); timeoutMs
  // bounds the entire request (connect + send + read).
  const timeoutMs = opts?.timeoutMs ?? DEFAULT_REQUEST_TIMEOUT_MS;
  const response = await fetchWithTimeout(fullUrl, {
    method,
    headers: getHeaders(),
    body: body ? JSON.stringify(body) : undefined,
    connectTimeout: 10_000,
    signal: opts?.signal,
  }, timeoutMs);

  if (response.status === 401) {
    // Do NOT call logout() here — authStore.initialize() handles 401 from /auth/me.
    // Calling logout() on any 401 causes a race condition during startup where
    // terminal/sync API calls can trigger logout before session validation completes.
    // Callers should handle 401 errors explicitly if needed.
    throw new ApiRequestError(401, 'Unauthorized', 'UNAUTHORIZED');
  }

  if (!response.ok) {
    let apiMessage = `Request failed (${String(response.status)})`;
    let code = 'UNKNOWN';
    let details: Record<string, unknown> | undefined;

    try {
      const errorBody = (await response.json()) as { error?: ApiError['error'] };
      if (errorBody.error) {
        apiMessage = errorBody.error.message;
        code = errorBody.error.code;
        details = errorBody.error.details;
      }
    } catch (parseError) {
      // Response wasn't JSON — log so a non-JSON 5xx (HTML/proxy error page,
      // gateway timeout body, etc.) is visible in devtools rather than vanishing.
      console.error('[POS][api] non-JSON error body', {
        ...serializeErrorForLog(parseError),
        status: response.status,
        url: fullUrl,
        method,
      });
    }

    throw new ApiRequestError(response.status, apiMessage, code, details);
  }

  const json = (await response.json()) as ApiResponse<T>;
  return json.data;
}

export async function apiGet<T>(
  url: string,
  params?: Record<string, unknown>,
  opts?: ApiRequestOptions,
): Promise<T> {
  return request<T>('GET', url, undefined, params, opts);
}

export async function apiPost<T>(
  url: string,
  data?: unknown,
  opts?: ApiRequestOptions,
): Promise<T> {
  return request<T>('POST', url, data, undefined, opts);
}

export async function apiPut<T>(
  url: string,
  data?: unknown,
  opts?: ApiRequestOptions,
): Promise<T> {
  return request<T>('PUT', url, data, undefined, opts);
}

export async function apiDelete<T>(
  url: string,
  opts?: ApiRequestOptions,
): Promise<T> {
  return request<T>('DELETE', url, undefined, undefined, opts);
}
