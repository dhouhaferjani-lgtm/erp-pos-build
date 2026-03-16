import { fetch } from '@tauri-apps/plugin-http';
import i18n from '@/lib/i18n';
import { useAuthStore } from '@/stores/authStore';

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
  };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  if (companyId) {
    headers['X-Company-Id'] = companyId;
  }
  return headers;
}

async function request<T>(
  method: string,
  url: string,
  body?: unknown,
  params?: Record<string, unknown>,
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

  const response = await fetch(fullUrl, {
    method,
    headers: getHeaders(),
    body: body ? JSON.stringify(body) : undefined,
    connectTimeout: 30000,
  });

  if (response.status === 401) {
    const reqUrl = url;
    if (!reqUrl.includes('/auth/me')) {
      console.warn('[API] 401 on', reqUrl, '— triggering logout');
      useAuthStore.getState().logout();
    }
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
    } catch {
      // Response wasn't JSON
    }

    throw new ApiRequestError(response.status, apiMessage, code, details);
  }

  const json = (await response.json()) as ApiResponse<T>;
  return json.data;
}

export async function apiGet<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  return request<T>('GET', url, undefined, params);
}

export async function apiPost<T>(url: string, data?: unknown): Promise<T> {
  return request<T>('POST', url, data);
}

export async function apiPut<T>(url: string, data?: unknown): Promise<T> {
  return request<T>('PUT', url, data);
}

export async function apiDelete<T>(url: string): Promise<T> {
  return request<T>('DELETE', url);
}
