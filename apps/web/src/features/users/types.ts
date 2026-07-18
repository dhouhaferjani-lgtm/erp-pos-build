/**
 * User Management Types
 * Matches backend Identity module User model
 */

export type UserStatus = 'active' | 'inactive' | 'pending_verification' | 'locked'

export interface User {
  id: string
  name: string
  email: string | null
  phone: string | null
  status: UserStatus
  roles: string[]
  canDiscount?: boolean
  maxDiscountPercent?: string | null
  lastLoginAt: string | null
  createdAt: string
  allowed_location_ids?: string[] | null
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
