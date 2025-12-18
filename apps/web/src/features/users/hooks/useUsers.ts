import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getUsers, getUser } from '../api/users'
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
  return useQuery({
    queryKey: userKeys.list(params),
    queryFn: () => getUsers(params),
    staleTime: 30000, // Consider data fresh for 30 seconds
  })
}

/**
 * Hook to fetch a single user by ID
 */
export function useUser(id: string): UseQueryResult<User> {
  return useQuery({
    queryKey: userKeys.detail(id),
    queryFn: () => getUser(id),
    enabled: Boolean(id), // Only run if id is provided
  })
}
