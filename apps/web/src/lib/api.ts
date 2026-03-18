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

/**
 * Extract error message from API error
 */
export function getErrorMessage(error: unknown): string {
  if (isApiError(error)) {
    const data = error.response?.data
    if (data) {
      return data.error.message
    }
    return 'An unexpected error occurred'
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
        // Don't redirect for /auth/me - that's expected when not logged in
        // The AuthProvider handles the redirect via React Router
        if (response.status === 401) {
          const url = error.config?.url ?? ''
          if (!url.includes('/auth/me')) {
            // For other endpoints, log out to disable protected queries.
            // Don't call queryClient.clear() here — it destroys the auth query
            // cache, triggering a refetch of /auth/me which re-sets isAuthenticated,
            // re-enabling the failing query in an infinite 401 loop.
            console.warn('Unauthorized request:', url)
            useAuthStore.getState().logout()
          }
        }

        // Handle 403 Forbidden
        if (response.status === 403) {
          console.error('Access denied:', response.data.error.message)
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
          console.error('Server error:', response.data.error.message)
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
 * Helper for DELETE requests
 */
export async function apiDelete<T>(url: string): Promise<T> {
  const response = await api.delete<ApiResponse<T>>(url)
  return response.data.data
}
