import { create } from 'zustand'
import { persist } from 'zustand/middleware'

interface SuperAdmin {
  id: string
  email: string
  name: string
  role: 'super_admin'
}

/**
 * Admin auth state interface
 *
 * SECURITY: `token` is held in MEMORY ONLY (never persisted to localStorage).
 * Bearer-only deploys (SANCTUM_STATEFUL_DOMAINS="") have no session cookie, so
 * this is the only credential. A page refresh therefore requires re-login by
 * design. Only the display profile (`admin`) is persisted for UI rendering.
 */
interface AdminAuthState {
  admin: SuperAdmin | null
  token: string | null
  isAuthenticated: boolean
  setAuth: (admin: SuperAdmin, token: string) => void
  logout: () => void
}

/**
 * Admin auth store with selective persistence.
 *
 * SECURITY NOTE: The Bearer token is NEVER written to localStorage.
 * Only `admin` (display profile) is persisted via `partialize`.
 * `isAuthenticated` and `token` reset on page refresh — consistent with
 * the memory-only security posture.
 */
export const useAdminAuthStore = create<AdminAuthState>()(
  persist(
    (set) => ({
      admin: null,
      token: null,
      isAuthenticated: false,
      setAuth: (admin, token) => {
        set({ admin, token, isAuthenticated: true })
      },
      logout: () => {
        set({ admin: null, token: null, isAuthenticated: false })
      },
    }),
    {
      name: 'admin-auth-storage',
      // Only the display profile is persisted — never token/isAuthenticated.
      partialize: (state) => ({
        admin: state.admin,
      }),
    }
  )
)
