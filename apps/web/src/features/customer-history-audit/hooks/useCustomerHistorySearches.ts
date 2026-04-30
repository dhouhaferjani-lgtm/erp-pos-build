import { useQuery } from '@tanstack/react-query'
import { listCustomerHistorySearches } from '../api/customerHistorySearchApi'
import type { CustomerHistorySearchFilters } from '../types/customerHistorySearch'

export const CUSTOMER_HISTORY_SEARCHES_KEY = ['customer-history-searches'] as const

export function useCustomerHistorySearches(filters: CustomerHistorySearchFilters = {}) {
  return useQuery({
    queryKey: [...CUSTOMER_HISTORY_SEARCHES_KEY, filters],
    queryFn: () => listCustomerHistorySearches(filters),
  })
}
