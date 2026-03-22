import { useEffect, useRef, type ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { api } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'
import { clearAllAppState } from '../../lib/clearAppState'

interface MeResponseUser {
  id: string
  name: string
  email: string
  tenantId: string
  roles: string[]
  emailVerifiedAt: string | null
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
 * SECURITY: Authentication is handled via httpOnly cookies set by Laravel Sanctum.
 * We always check the session on mount - if a valid session cookie exists,
 * the /auth/me endpoint will return the user data.
 *
 * GUARD: clearAllAppState is only called when a previously authenticated session
 * becomes invalid (wasAuthenticated ref). An initial 401 on page load (before
 * login/registration) will NOT clear app state, preventing a race condition
 * that could wipe tokens set during registration.
 */
export function AuthProvider({ children }: AuthProviderProps) {
  const user = useAuthStore((state) => state.user)
  const setUser = useAuthStore((state) => state.setUser)
  const setLoading = useAuthStore((state) => state.setLoading)
  const queryClient = useQueryClient()
  const wasAuthenticated = useRef(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const response = await api.get<MeResponse>('/auth/me')
      return response.data.data
    },
    retry: false,
    staleTime: 1000 * 60 * 5, // 5 minutes
    // Always check session on mount - cookie-based auth doesn't require stored token
    enabled: true,
  })

  useEffect(() => {
    if (isLoading) {
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
        email_verified_at: data.emailVerifiedAt,
      }
      setUser(userData)
    } else if (isError) {
      if (wasAuthenticated.current) {
        // Session was valid but is now expired — clear all app state
        clearAllAppState(queryClient)
        wasAuthenticated.current = false
      }
      setLoading(false)
    }
  }, [data, isLoading, isError, setUser, setLoading, queryClient])

  // Show loading only while checking session
  if (isLoading && !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-4">
          <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
          <p className="text-gray-500">Loading...</p>
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
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-4">
          <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
          <p className="text-gray-500">Loading...</p>
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

