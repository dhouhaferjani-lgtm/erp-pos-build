import { useEffect, type ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { api } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'

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
 * and maintains auth state throughout the app
 */
export function AuthProvider({ children }: AuthProviderProps) {
  const token = useAuthStore((state) => state.token)
  const setUser = useAuthStore((state) => state.setUser)
  const setLoading = useAuthStore((state) => state.setLoading)
  const logout = useAuthStore((state) => state.logout)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      const response = await api.get<MeResponse>('/auth/me')
      return response.data.data
    },
    retry: false,
    staleTime: 1000 * 60 * 5, // 5 minutes
    enabled: !!token, // Only run if we have a token
  })

  useEffect(() => {
    // No token means not authenticated
    if (!token) {
      setLoading(false)
      return
    }

    if (isLoading) {
      setLoading(true)
    } else if (data) {
      // Map tenantId to tenant_id for store compatibility
      const user = {
        id: data.id,
        name: data.name,
        email: data.email,
        tenant_id: data.tenantId,
        roles: data.roles,
        email_verified_at: data.emailVerifiedAt,
      }
      setUser(user)
    } else if (isError) {
      // Token is invalid, clear auth state
      logout()
    }
  }, [token, data, isLoading, isError, setUser, setLoading, logout])

  // Show loading only if we have a token and are checking it
  if (token && isLoading) {
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

