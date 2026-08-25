import axios, { type AxiosError, type AxiosInstance, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { markCompanyAccessDenied, useCompanyStore } from '../stores/companyStore'
import { useLocationStore } from '../stores/locationStore'
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

/**
 * Bootstrap endpoints that must NEVER carry `X-Company-Id` (W2-1).
 *
 * These are the calls that TELL the client which companies it may use. Sending
 * the client's own (possibly stale) belief on them makes the answer depend on
 * the question: a browser holding a previous account's selection 403'd on
 * `/user/companies` itself, so the membership list could never load, the
 * switcher opened empty and the deadlock could not self-heal — the exact
 * campaign wave-2 W2-1 failure. Both endpoints are user-scoped server-side
 * (`UserController::companies` reads the caller's own memberships; `/auth/me`
 * is not company-scoped at all), so the header adds nothing but risk.
 */
const COMPANY_CONTEXT_EXEMPT_PATHS = ['/user/companies', '/auth/me']

/**
 * True when a request URL addresses one of the bootstrap endpoints above.
 * Tolerates an absolute URL, an `/api/v1` prefix and a query string.
 */
export function isCompanyContextExempt(url: string | undefined): boolean {
  if (url === undefined || url === '') {
    return false
  }
  const withoutQuery = url.split('?')[0].split('#')[0]
  const withoutOrigin = withoutQuery.replace(/^[a-z]+:\/\/[^/]+/i, '')
  const withoutApiPrefix = withoutOrigin.replace(/^\/api\/v\d+/, '')
  const path = withoutApiPrefix.startsWith('/') ? withoutApiPrefix : `/${withoutApiPrefix}`

  return COMPANY_CONTEXT_EXEMPT_PATHS.includes(path.replace(/\/+$/, ''))
}

/**
 * Typed rejections that mean "the company scope you sent is stale or junk".
 * Both are recoverable by forgetting the selection and re-bootstrapping;
 * `NO_COMPANY_ACCESS` deliberately is NOT here — it means the user is a member
 * of no company at all, which resetting cannot fix.
 */
const COMPANY_SCOPE_REJECTION_CODES = ['COMPANY_ACCESS_DENIED', 'INVALID_COMPANY_ID']

/**
 * Self-heal a stale company scope (W2-1).
 *
 * Drops the persisted selection and the previous company's locations. Because
 * `currentCompanyId` is a suffix of every `tenantScopedKey`, clearing it
 * re-keys the bootstrap query and CompanyProvider refetches `/user/companies`
 * — now WITHOUT the header (see `isCompanyContextExempt`) — so the store
 * relearns the real membership list and `resolveCompanySelection` picks the
 * user's primary company.
 *
 * MATCHING (F-3): `sentCompanyId` is the `X-Company-Id` the FAILING request
 * actually carried. The reset fires only when it is still the current
 * selection. Without that check a 403 for company A, issued moments before the
 * user switched to B, would wipe B — silently undoing a deliberate switch. A
 * request that carried no header was resolved server-side against the user's
 * default company, which no client-side reset can fix, so it is ignored too.
 *
 * LOOP SAFETY: the denied company is recorded (`markCompanyAccessDenied`) so
 * `resolveCompanySelection` cannot re-pick it on the re-bootstrap that follows.
 * Without that, "the selection is null now" is only half the cycle — the
 * provider re-picks `isPrimary`/`companies[0]` deterministically, and if the
 * server denies that one too the pair resets forever. Belt: the reset is also a
 * no-op once `currentCompanyId` is null.
 *
 * @returns whether this call reset the scope.
 */
export function handleCompanyScopeRejection(
  code: string | null,
  sentCompanyId: string | null,
): boolean {
  if (code === null || !COMPANY_SCOPE_REJECTION_CODES.includes(code)) {
    return false
  }

  const companyStore = useCompanyStore.getState()
  const currentCompanyId = companyStore.currentCompanyId
  if (currentCompanyId === null) {
    return false
  }

  if (sentCompanyId === null || sentCompanyId !== currentCompanyId) {
    return false
  }

  console.warn('Stale company scope rejected by the API, resetting selection:', code)
  markCompanyAccessDenied(currentCompanyId)
  companyStore.reset()
  useLocationStore.getState().reset()

  return true
}

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

      // Add company context header for multi-company support.
      // Bootstrap calls are exempt: they are what teaches the client which
      // companies exist, so they must not depend on what it already believes.
      const companyId = useCompanyStore.getState().currentCompanyId
      if (companyId && !isCompanyContextExempt(config.url)) {
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
      // 419 CSRF is handled FIRST and keys on STATUS alone, deliberately
      // outside the `isApiError` gate below.
      //
      // Laravel's CSRF failure is a bare `{"message":"CSRF token mismatch."}` —
      // 419 is an HttpException, which the API's catch-all renderer leaves
      // untyped on purpose — so `isApiError` is false for it and this
      // refresh-and-retry branch was unreachable: every CSRF expiry surfaced as
      // a hard failure instead of self-healing. (Found independently by the
      // media and imports merge gates, 2026-08-06.)
      if (axios.isAxiosError(error) && error.response?.status === 419) {
        const config = error.config as (InternalAxiosRequestConfig & { _csrfRetried?: boolean }) | undefined

        // Replay at most once — a second 419 means the token is not the problem
        // and retrying again would loop.
        if (config && config._csrfRetried !== true) {
          config._csrfRetried = true
          console.warn('CSRF token mismatch, refreshing token...')
          try {
            await ensureCsrfCookie()
          } catch (csrfError) {
            console.error('Failed to refresh CSRF token:', csrfError)
          }

          // Outside the try: a failure of the REPLAY itself (e.g. the retried
          // request 422s or 500s) must propagate as its own error, not be
          // caught by the block above and mislabelled "Failed to refresh CSRF
          // token" while the caller still sees the stale original 419
          // (FE gate round 2, MINOR-R2-1, 2026-08-06).
          return await client.request(config)
        }
      }

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

        // A stale/junk company scope is recoverable: forget the selection and
        // let CompanyProvider re-bootstrap (W2-1). 400 carries INVALID_COMPANY_ID,
        // 403 carries COMPANY_ACCESS_DENIED; both are typed by
        // CompanyContextMiddleware.
        if (response.status === 403 || response.status === 400) {
          const envelope = isRecord(response.data) ? response.data['error'] : null
          const sentCompanyId = error.config?.headers['X-Company-Id']
          handleCompanyScopeRejection(
            readString(envelope, 'code'),
            typeof sentCompanyId === 'string' ? sentCompanyId : null,
          )
        }

        // Handle 403 Forbidden
        if (response.status === 403) {
          console.error('Access denied:', getErrorMessage(error))
        }

        // 419 is handled above, before the isApiError gate.

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
