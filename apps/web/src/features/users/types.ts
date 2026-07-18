/**
 * User Management Types
 * Matches backend Identity module User model
 */

import type { OffsetPaginationMeta } from '@/types/pagination'

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
}

export interface GetUsersParams {
  status?: UserStatus | 'all'
  search?: string | undefined
  per_page?: number
}

export interface PaginatedUsersResponse {
  data: User[]
  meta: OffsetPaginationMeta & {
    timestamp: string
    request_id: string
  }
}
