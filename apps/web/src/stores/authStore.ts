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
  setAuth: (user: User, token: string) => void
  setUser: (user: User | null) => void
  setLoading: (loading: boolean) => void
  logout: () => void
  getToken: () => string | null
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
 */
export const useAuthStore = create<AuthStore>()(
  persist(
    (set, get) => ({
      ...initialState,

      setAuth: (user, token) =>
        set({
          user,
          token,
          isAuthenticated: true,
          isLoading: false,
        }),

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

      getToken: () => get().token,
    }),
    {
      name: 'autoerp-auth',
      partialize: (state) => ({
        user: state.user,
        token: state.token,
        isAuthenticated: state.isAuthenticated,
      }),
    }
  )
)
