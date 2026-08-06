import axios, { type AxiosError, type AxiosInstance, type AxiosResponse } from 'axios'
import { useCompanyStore } from '../stores/companyStore'
import { useAuthStore } from '../stores/authStore'

/**
 * API Response Format (per CLAUDE.md)
 */
export interface ApiResponse<T> {
  data: T
  meta: {
    timestamp: string
    request_id: string
  }
}

/**
 * Paginated API Response Format (cursor-based)
 */
export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    per_page: number
    has_more: boolean
    total?: number
  }
  links: {
    next: string | null
    prev: string | null
  }
}

/**
 * API Error Format (per CLAUDE.md)
 */
export interface ApiError {
  error: {
    code: string
    message: string
    details?: Record<string, unknown>
  }
  meta: {
    timestamp: string
    request_id: string
  }
}

/**
 * Type guard for API errors
 */
export function isApiError(error: unknown): error is AxiosError<ApiError> {
  if (!axios.isAxiosError(error)) {
    return false
  }
  const data = error.response?.data as ApiError | undefined
  return data?.error !== undefined
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

/**
 * Read a string field off an unknown response body without assuming its shape.
 */
function readString(source: unknown, key: string): string | null {
  if (!isRecord(source)) return null
  const value = source[key]
  return typeof value === 'string' && value !== '' ? value : null
}

/**
 * Extract a human-readable message from an API error.
 *
 * BUG-005 / RCA B3 — this used to read `data.error.message` behind an
 * `isApiError` guard, with two holes: a body carrying only a bare top-level
 * `message` (Laravel's untyped `{"message":"Server Error"}`) failed the guard
 * and lost the server's text entirely, and an envelope whose `error` object
 * had no `message` returned `undefined` despite the `string` return type.
 *
 * Fallback chain: `data?.error?.message ?? data?.message ?? error.message`.
 */
export function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data: unknown = error.response?.data
    const envelope = isRecord(data) ? data['error'] : null

    return (
      readString(envelope, 'message') ??
      readString(data, 'message') ??
      (error.message !== '' ? error.message : 'An unexpected error occurred')
    )
  }
  if (error instanceof Error) {
    return error.message
  }
  return 'An unexpected error occurred'
}

/**
 * Fetch CSRF cookie from Sanctum before making auth requests.
 * This sets the XSRF-TOKEN cookie that axios will automatically
 * include in subsequent requests via withCredentials.
 */
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', {
    withCredentials: true,
  })
}

const PUBLIC_PATHS = ['/login', '/register', '/verify-email', '/forgot-password',
  '/reset-password', '/privacy', '/terms', '/admin/login']
let redirectedToLogin = false
// jsdom makes window.location.assign non-configurable (vi.spyOn cannot patch it),
// so the redirect is injectable for tests via __setRedirectForTests.
let redirect: (path: string) => void = (path) => {
  window.location.assign(path)
}
export function __resetRedirectGuard(): void { redirectedToLogin = false }
export function __setRedirectForTests(fn: (path: string) => void): void { redirect = fn }

export function handleUnauthorized(url: string, sentToken: string | null): void {
  const store = useAuthStore.getState()
  if (url.includes('/auth/me')) {
    // A 401 on /auth/me proves the token we SENT is bad. Only clear if it is
    // still the current token — protects the registration race (AuthProvider
    // wasAuthenticated guard) where a fresh token landed while we were in flight.
    if (sentToken !== null && sentToken === store.token) store.logout()
    return
  }
  console.warn('Unauthorized request:', url)
  store.logout()
  const path = window.location.pathname
  if (!redirectedToLogin && !PUBLIC_PATHS.some((p) => path === p || path.startsWith(`${p}/`))) {
    redirectedToLogin = true
    redirect('/login')
  }
}

/**
 * Create the base API client with cookie-based auth handling
 *
 * SECURITY: Authentication is handled via httpOnly cookies set by Laravel Sanctum.
 * No tokens are stored in localStorage or sent via Authorization header.
 * CSRF protection is provided via the XSRF-TOKEN cookie.
 */
function createApiClient(): AxiosInstance {
  const client = axios.create({
    baseURL: '/api/v1',
    timeout: 30000,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    withCredentials: true, // Required for Sanctum cookie-based auth
    xsrfCookieName: 'XSRF-TOKEN', // Cookie name set by Sanctum
    xsrfHeaderName: 'X-XSRF-TOKEN', // Header name expected by Sanctum
  })

  // Request interceptor for auth token, company context, and language
  client.interceptors.request.use(
    (config) => {
      // Add Bearer token if available (required for reverse-proxy deployments)
      const token = useAuthStore.getState().token
      if (token) {
        config.headers['Authorization'] = `Bearer ${token}`
      }

      // Add company context header for multi-company support
      const companyId = useCompanyStore.getState().currentCompanyId
      if (companyId) {
        config.headers['X-Company-Id'] = companyId
      }

      // Send user's chosen language so backend can localize responses (e.g. receipt PDFs)
      const lang = localStorage.getItem('autoerp-language')
      if (lang) {
        config.headers['Accept-Language'] = lang
      }

      return config
    },
    (error: unknown) => Promise.reject(new Error(String(error)))
  )

  // Response interceptor for error handling
  client.interceptors.response.use(
    (response: AxiosResponse) => response,
    async (error: unknown) => {
      if (isApiError(error)) {
        const response = error.response
        if (!response) {
          return Promise.reject(new Error('Network error'))
        }

        // Handle 401 Unauthorized
        // Don't call queryClient.clear() here — it destroys the auth query
        // cache, triggering a refetch of /auth/me which re-sets isAuthenticated,
        // re-enabling the failing query in an infinite 401 loop.
        if (response.status === 401) {
          const url = error.config?.url ?? ''
          const authHeader = error.config?.headers.Authorization
          const sentToken =
            typeof authHeader === 'string' && authHeader.startsWith('Bearer ')
              ? authHeader.slice('Bearer '.length)
              : null
          handleUnauthorized(url, sentToken)
        }

        // Handle 403 Forbidden
        if (response.status === 403) {
          console.error('Access denied:', getErrorMessage(error))
        }

        // Handle 419 CSRF Token Mismatch - retry after fetching new token
        if (response.status === 419) {
          console.warn('CSRF token mismatch, refreshing token...')
          try {
            await ensureCsrfCookie()
            // Retry the original request
            if (error.config) {
              return client.request(error.config)
            }
          } catch (csrfError) {
            console.error('Failed to refresh CSRF token:', csrfError)
          }
        }

        // Handle 500+ Server Errors
        if (response.status >= 500) {
          console.error('Server error:', getErrorMessage(error))
        }
      }

      return Promise.reject(error instanceof Error ? error : new Error(String(error)))
    }
  )

  return client
}

/**
 * Singleton API client instance
 */
export const api = createApiClient()

/**
 * Helper for GET requests
 */
export async function apiGet<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const response = await api.get<ApiResponse<T>>(url, { params })
  return response.data.data
}

/**
 * Helper for POST requests
 */
export async function apiPost<T>(url: string, data?: unknown): Promise<T> {
  const response = await api.post<ApiResponse<T>>(url, data)
  return response.data.data
}

/**
 * Helper for PATCH requests
 */
export async function apiPatch<T>(url: string, data?: unknown): Promise<T> {
  const response = await api.patch<ApiResponse<T>>(url, data)
  return response.data.data
}

/**
 * Helper for PUT requests
 */
export async function apiPut<T>(url: string, data?: unknown): Promise<T> {
  const response = await api.put<ApiResponse<T>>(url, data)
  return response.data.data
}

/**
 * Download a file via authenticated request (avoids 401 from window.open)
 */
export async function authenticatedDownload(url: string, filename?: string): Promise<void> {
  const response = await api.get(url, { responseType: 'blob' })
  const blob = new Blob([response.data as BlobPart])
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename ?? url.split('/').pop() ?? 'download'
  link.click()
  URL.revokeObjectURL(link.href)
}

/**
 * Helper for DELETE requests
 */
export async function apiDelete<T>(url: string): Promise<T> {
  const response = await api.delete<ApiResponse<T>>(url)
  return response.data.data
}
