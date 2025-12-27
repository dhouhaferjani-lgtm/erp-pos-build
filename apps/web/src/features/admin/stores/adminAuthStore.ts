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
 * SECURITY: Token is NOT stored - authentication relies on httpOnly cookies
 * managed by Sanctum. Only admin info is persisted for UI display.
 */
interface AdminAuthState {
  admin: SuperAdmin | null
  isAuthenticated: boolean
  setAuth: (admin: SuperAdmin) => void
  logout: () => void
}

/**
 * Admin auth store with persistence
 *
 * SECURITY NOTE: Authentication tokens are managed via httpOnly cookies
 * by Laravel Sanctum. We only persist admin info for UI display purposes.
 * The actual authentication state is determined by the session cookie.
 */
export const useAdminAuthStore = create<AdminAuthState>()(
  persist(
    (set) => ({
      admin: null,
      isAuthenticated: false,
      setAuth: (admin) => {
        set({ admin, isAuthenticated: true })
      },
      logout: () => {
        set({ admin: null, isAuthenticated: false })
      },
    }),
    {
      name: 'admin-auth-storage',
      // Only persist admin info for UI - NOT authentication state
      // The session cookie determines actual auth status
      partialize: (state) => ({
        admin: state.admin,
      }),
    }
  )
)
