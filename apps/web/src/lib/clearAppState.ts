import type { QueryClient } from '@tanstack/react-query'
import { useAuthStore } from '../stores/authStore'
import { useCompanyStore } from '../stores/companyStore'
import { useLocationStore } from '../stores/locationStore'

/**
 * Clears the PREVIOUS session's scope at the start of a NEW one.
 *
 * Must be called on every successful login and register, BEFORE the new session
 * is installed and therefore before the first authenticated request goes out.
 *
 * W2-1 (campaign wave 2): the company selection is persisted origin-wide under
 * `autoerp-company-selection`, and only `clearAllAppState` — wired to logout and
 * to 401 expiry — ever dropped it. So a fresh signup on a browser that had held
 * another account's company inherited that id, sent it on every call, and 403'd
 * on all of them; the app was dead with no in-product recovery.
 *
 * Unlike {@link clearAllAppState} this deliberately does NOT touch the auth
 * store: the caller is in the middle of establishing a session and owns it.
 */
export function clearScopeForNewSession(queryClient: QueryClient): void {
  // Stores first, queries second: removeQueries can restart active queries
  // immediately, and they must be re-keyed and header-free when they do.
  useCompanyStore.getState().reset()
  useLocationStore.getState().reset()

  // Drop the previous account's cached data. `removeQueries` (not `clear`)
  // leaves the mutation cache intact — this runs inside the login/register
  // mutation's own onSuccess.
  queryClient.removeQueries()
}

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
