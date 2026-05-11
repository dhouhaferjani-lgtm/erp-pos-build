import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { listCustomerHistorySearches } from '../api/customerHistorySearchApi'
import type { CustomerHistorySearchFilters } from '../types/customerHistorySearch'

export const CUSTOMER_HISTORY_SEARCHES_KEY = ['customer-history-searches'] as const

export function useCustomerHistorySearches(filters: CustomerHistorySearchFilters = {}) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...CUSTOMER_HISTORY_SEARCHES_KEY, filters]),
    queryFn: () => listCustomerHistorySearches(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}
