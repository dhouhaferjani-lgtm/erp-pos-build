type DocumentStatus = 'draft' | 'confirmed' | 'posted' | 'cancelled'

/**
 * Income metadata with receipt and account information.
 */
export interface IncomeMetadata {
  source_name: string | null
  reference_number: string | null
  payment_date: string | null
  is_received: boolean
  income_account_id: string | null
  payment_method_id: string | null
  payment_repository_id: string | null
  income_account?: {
    id: string
    code: string
    name: string
  } | null
  payment_method?: {
    id: string
    name: string
    code: string
  } | null
  payment_repository?: {
    id: string
    name: string
    type: string
  } | null
}

/**
 * Income document.
 */
export interface Income {
  id: string
  type: 'income'
  status: DocumentStatus
  document_number: string
  document_date: string
  total: string
  currency: string
  notes: string | null
  created_at: string
  updated_at: string
  metadata: IncomeMetadata | null
  company?: {
    id: string
    name: string
  }
}

/**
 * DTO for creating/updating an income record. Money is a canonical string —
 * never a JS number (precision rule 19).
 */
export interface CreateIncomeDTO {
  source_name?: string
  income_account_id?: string
  payment_method_id?: string
  payment_repository_id?: string
  payment_date?: string
  reference_number?: string
  total: string
  notes?: string
  is_received?: boolean
  document_date?: string
  idempotency_key?: string
}

export interface IncomeResponse {
  data: Income
  message?: string
}

export interface IncomeListResponse {
  data: Income[]
  meta?: OffsetPaginationMeta
}

export interface IncomeFilters {
  status?: DocumentStatus
  date_from?: string
  date_to?: string
  search?: string
  per_page?: number
}
import type { OffsetPaginationMeta } from '@/types/pagination'
