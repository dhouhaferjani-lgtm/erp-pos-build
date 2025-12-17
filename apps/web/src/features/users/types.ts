/**
 * User Management Types
 * Matches backend Identity module User model
 */

export type UserStatus = 'active' | 'inactive' | 'pending_verification' | 'locked'

export interface User {
  id: string
  name: string
  email: string
  phone: string | null
  status: UserStatus
  roles: string[]
  lastLoginAt: string | null
  createdAt: string
}

export interface GetUsersParams {
  status?: UserStatus | 'all'
  search?: string | undefined
  per_page?: number
}

export interface PaginatedUsersResponse {
  data: User[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
    timestamp: string
    request_id: string
  }
}
