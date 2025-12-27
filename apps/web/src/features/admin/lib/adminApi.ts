import axios, { type AxiosInstance } from 'axios'

/**
 * Create admin API client with cookie-based auth
 *
 * SECURITY: Authentication is handled via httpOnly cookies set by Laravel Sanctum.
 * No tokens are stored in localStorage or sent via Authorization header.
 * CSRF protection is provided via the XSRF-TOKEN cookie.
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
