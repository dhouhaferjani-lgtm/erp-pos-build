import { create } from 'zustand'
import { persist } from 'zustand/middleware'

/**
 * User type (will be replaced with generated type from backend)
 */
interface User {
  id: string
  name: string
  email: string
  tenant_id: string
  roles: string[]
  email_verified_at: string | null
}

/**
 * Auth state interface
 *
 * Token-based auth: Bearer token from login is stored and sent via Authorization header.
 * This is required when the frontend is behind a reverse proxy (different Host header).
 */
interface AuthState {
  user: User | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
}

/**
 * Auth actions interface
 */
interface AuthActions {
  setAuth: (user: User, token?: string) => void
  setUser: (user: User | null) => void
  setLoading: (loading: boolean) => void
  logout: () => void
}

/**
 * Auth store type
 */
type AuthStore = AuthState & AuthActions

/**
 * Initial state
 */
const initialState: AuthState = {
  user: null,
  token: null,
  isAuthenticated: false,
  isLoading: true,
}

/**
 * Auth store with persistence
 *
 * Uses Zustand for minimal client state (per CLAUDE.md).
 * Server state is managed by TanStack Query.
 *
 * SECURITY NOTE: Authentication tokens are managed via httpOnly cookies
 * by Laravel Sanctum. We only persist user info for UI display purposes.
 * The actual authentication state is determined by the session cookie.
 */
export const useAuthStore = create<AuthStore>()(
  persist(
    (set) => ({
      ...initialState,

      setAuth: (user, token) =>
        set((state) => ({
          user,
          token: token ?? state.token,
          isAuthenticated: true,
          isLoading: false,
        })),

      setUser: (user) =>
        set({
          user,
          isAuthenticated: user !== null,
          isLoading: false,
        }),

      setLoading: (isLoading) => set({ isLoading }),

      logout: () =>
        set({
          user: null,
          token: null,
          isAuthenticated: false,
          isLoading: false,
        }),
    }),
    {
      name: 'autoerp-auth',
      // Only persist user info for UI - NOT authentication state
      // The session cookie determines actual auth status
      partialize: (state) => ({
        user: state.user,
        token: state.token,
      }),
    }
  )
)
