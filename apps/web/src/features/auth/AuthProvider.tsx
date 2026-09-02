import { useEffect, useRef, type ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { clearAllAppState } from '../../lib/clearAppState'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface MeResponseUser {
  id: string
  name: string
  email: string
  tenantId: string
  roles: string[]
  permissions: string[]
  emailVerifiedAt: string | null
  impersonation: {
    session_id: string
    subject_user_id: string
    subject_name: string
    reason: string
    ticket_ref: string
    access_level: 'read_only' | 'write_elevated'
    expires_at: string
    remaining_seconds: number
  } | null
}

interface MeResponse {
  data: MeResponseUser
}

interface AuthProviderProps {
  children: ReactNode
}

interface RequireAuthProps {
  children: ReactNode
}

/**
 * AuthProvider checks for existing session on mount
 * and maintains auth state throughout the app.
 *
 * Authentication is bootstrapped only when the persisted auth store contains
 * a bearer token. Public routes must not probe /auth/me without credentials.
 *
 * GUARD: clearAllAppState is only called when a previously authenticated session
 * becomes invalid (wasAuthenticated ref). A cold-load 401 clears only the stale
 * auth session, and an in-flight response cannot clear a newer token.
 */
export function AuthProvider({ children }: AuthProviderProps) {
  const user = useAuthStore((state) => state.user)
  const token = useAuthStore((state) => state.token)
  const setUser = useAuthStore((state) => state.setUser)
  const setLoading = useAuthStore((state) => state.setLoading)
  const queryClient = useQueryClient()
  const wasAuthenticated = useRef(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: tenantScopedKey(['auth', 'me']),
    queryFn: async () => {
      const response = await api.get<MeResponse>('/auth/me')
      return response.data.data
    },
    retry: false,
    staleTime: 1000 * 60 * 5, // 5 minutes
    enabled: token !== null,
  })

  useEffect(() => {
    if (token === null) {
      if (wasAuthenticated.current) {
        // api.ts owns the token-identity-guarded logout. This effect clears
        // only the now-ended session's cached tenant state.
        clearAllAppState(queryClient, { authAlreadyCleared: true })
        wasAuthenticated.current = false
      }
      setLoading(false)
    } else if (isLoading) {
      setLoading(true)
    } else if (data) {
      wasAuthenticated.current = true
      // Map tenantId to tenant_id for store compatibility
      const userData = {
        id: data.id,
        name: data.name,
        email: data.email,
        tenant_id: data.tenantId,
        roles: data.roles,
        permissions: data.permissions,
        email_verified_at: data.emailVerifiedAt,
        impersonation: data.impersonation,
      }
      setUser(userData)
    } else if (isError) {
      setLoading(false)
    }
  }, [data, isLoading, isError, token, setUser, setLoading, queryClient])

  // Show loading only while checking session
  if (isLoading && !user) {
    return (
      <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page}`}>
        <div className="flex flex-col items-center gap-4">
          <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text}`} />
          <p className={colorTokens.text.subtle}>Loading...</p>
        </div>
      </div>
    )
  }

  return <>{children}</>
}

/**
 * RequireAuth wraps protected routes and redirects to login
 * if user is not authenticated
 */
export function RequireAuth({ children }: RequireAuthProps) {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const isLoading = useAuthStore((state) => state.isLoading)
  const location = useLocation()

  if (isLoading) {
    return (
      <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page}`}>
        <div className="flex flex-col items-center gap-4">
          <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text}`} />
          <p className={colorTokens.text.subtle}>Loading...</p>
        </div>
      </div>
    )
  }

  if (!isAuthenticated) {
    // Redirect to login while preserving the attempted URL
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  return <>{children}</>
}
