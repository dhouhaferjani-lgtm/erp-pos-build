import { useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { clearAllAppState } from '../../lib/clearAppState'

/**
 * useLogout hook for logging out users
 */
export function useLogout() {
  const queryClient = useQueryClient()

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Logout locally even if API call fails
    } finally {
      clearAllAppState(queryClient)
      window.location.href = '/login'
    }
  }

  return handleLogout
}
