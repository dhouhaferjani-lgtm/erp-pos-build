import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getUsers, getUser } from '../api/users'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { GetUsersParams, PaginatedUsersResponse, User } from '../types'

/**
 * Query key factory for users
 */
export const userKeys = {
  all: ['users'] as const,
  lists: () => [...userKeys.all, 'list'] as const,
  list: (params?: GetUsersParams) => [...userKeys.lists(), params] as const,
  details: () => [...userKeys.all, 'detail'] as const,
  detail: (id: string) => [...userKeys.details(), id] as const,
}

/**
 * Hook to fetch paginated list of users
 */
export function useUsers(
  params?: GetUsersParams
): UseQueryResult<PaginatedUsersResponse> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(userKeys.list(params)),
    queryFn: () => getUsers(params),
    enabled: tenantId !== null && companyId !== null,
    staleTime: 30000, // Consider data fresh for 30 seconds
  })
}

/**
 * Hook to fetch a single user by ID
 */
export function useUser(id: string): UseQueryResult<User> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(userKeys.detail(id)),
    queryFn: () => getUser(id),
    enabled: Boolean(id) && tenantId !== null && companyId !== null,
  })
}
