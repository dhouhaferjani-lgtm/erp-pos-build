import { api } from '@/lib/api'
import type { GetUsersParams, PaginatedUsersResponse, User } from '../types'

/**
 * Get list of users with optional filtering
 */
export async function getUsers(params?: GetUsersParams): Promise<PaginatedUsersResponse> {
  const response = await api.get<PaginatedUsersResponse>('/users', { params })
  return response.data
}

/**
 * Get a single user by ID
 */
export async function getUser(id: string): Promise<User> {
  const response = await api.get<{ data: User }>(`/users/${id}`)
  return response.data.data
}
