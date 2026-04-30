import { api } from '@/lib/api'
import type {
  CustomerHistorySearchFilters,
  CustomerHistorySearchListResponse,
} from '../types/customerHistorySearch'

/**
 * List customer history searches (paginated).
 *
 * Uses api.get directly — NOT apiGet — to preserve the {data, meta} envelope.
 * See MEMORY.md: apiGet strips the meta wrapper and breaks pagination.
 */
export async function listCustomerHistorySearches(
  filters: CustomerHistorySearchFilters = {},
): Promise<CustomerHistorySearchListResponse> {
  const params = new URLSearchParams()
  if (filters.page) params.set('page', String(filters.page))
  if (filters.per_page) params.set('per_page', String(filters.per_page))
  if (filters.cashier_id) params.set('cashier_id', filters.cashier_id)
  if (filters.terminal_id) params.set('terminal_id', filters.terminal_id)
  if (filters.partner_id) params.set('partner_id', filters.partner_id)
  if (filters.was_rejected) params.set('was_rejected', filters.was_rejected)
  if (filters.from_date) params.set('from_date', filters.from_date)
  if (filters.to_date) params.set('to_date', filters.to_date)

  const query = params.toString()
  const response = await api.get<CustomerHistorySearchListResponse>(
    `/pos/customer-history-searches${query ? `?${query}` : ''}`,
  )
  return response.data
}
