export type RejectedFilter = '' | 'true' | 'false'

export interface CustomerHistorySearch {
  id: string
  company_id: string
  cashier_id: string | null
  terminal_id: string | null
  partner_id: string | null
  search_hash: string
  was_rejected: boolean
  rejection_reason: string | null
  result_count: number
  created_at: string
  // Denormalized joins from backend
  cashier_name: string | null
  terminal_name: string | null
  partner_name: string | null
}

export interface CustomerHistorySearchMeta extends OffsetPaginationMeta {
  rejected_total: number
}

export interface CustomerHistorySearchListResponse {
  data: CustomerHistorySearch[]
  meta: CustomerHistorySearchMeta
}

export interface CustomerHistorySearchFilters {
  cashier_id?: string
  terminal_id?: string
  partner_id?: string
  was_rejected?: '' | 'true' | 'false'
  from_date?: string
  to_date?: string
  page?: number
  per_page?: number
}
import type { OffsetPaginationMeta } from '@/types/pagination'
