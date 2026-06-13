import axios, { type AxiosInstance } from 'axios'
import { useAdminAuthStore } from '../stores/adminAuthStore'

/**
 * Admin API client — cookie-based Sanctum auth with a Bearer fallback.
 *
 * Bearer-only deploys (db-per-tenant: SANCTUM_STATEFUL_DOMAINS="") cannot
 * establish a session cookie, so the login response token (kept in the
 * memory-only adminAuthStore) is attached as Authorization: Bearer. On
 * cookie-capable deploys the cookie still works and the header is a no-op
 * for the same principal. 401 responses clear auth state and bounce to the
 * admin login page.
 */
function createAdminApiClient(): AxiosInstance {
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

  client.interceptors.request.use((config) => {
    const token = useAdminAuthStore.getState().token
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  })

  client.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
      if (axios.isAxiosError(error) && error.response?.status === 401) {
        useAdminAuthStore.getState().logout()
        if (!window.location.pathname.startsWith('/admin/login')) {
          window.location.assign('/admin/login')
        }
      }
      return Promise.reject(error instanceof Error ? error : new Error(String(error)))
    }
  )

  return client
}

export const adminApi = createAdminApiClient()

/**
 * Helper for GET requests (extracts inner data from { data: T } wrapper)
 */
export async function adminApiGet<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const response = await adminApi.get<{ data: T }>(url, { params })
  return response.data.data
}

/**
 * Helper for paginated GET requests (returns the full paginated response)
 * Use this for endpoints that return { data: T[], total: number, ... }
 */
export async function adminApiGetPaginated<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const response = await adminApi.get<T>(url, { params })
  return response.data
}

/**
 * Helper for POST requests
 */
export async function adminApiPost<T>(url: string, data?: unknown): Promise<T> {
  const response = await adminApi.post<{ data: T }>(url, data)
  return response.data.data
}

/**
 * Helper for PATCH requests
 */
export async function adminApiPatch<T>(url: string, data?: unknown): Promise<T> {
  const response = await adminApi.patch<{ data: T }>(url, data)
  return response.data.data
}

/**
 * Helper for PUT requests
 */
export async function adminApiPut<T>(url: string, data?: unknown): Promise<T> {
  const response = await adminApi.put<{ data: T }>(url, data)
  return response.data.data
}
