import type { QueryClient } from '@tanstack/react-query'
import { useAuthStore } from '../stores/authStore'
import { useCompanyStore } from '../stores/companyStore'
import { useLocationStore } from '../stores/locationStore'

/**
 * Clears all application state when switching accounts or on logout.
 *
 * This prevents stale data from a previous account leaking into a new session.
 * Must be called on every logout and 401 session expiry.
 */
export function clearAllAppState(queryClient: QueryClient): void {
  // Clear all React Query cached data (products, config, documents, etc.)
  queryClient.clear()

  // Reset all Zustand stores to initial state
  useAuthStore.getState().logout()
  useCompanyStore.getState().reset()
  useLocationStore.getState().reset()
}
