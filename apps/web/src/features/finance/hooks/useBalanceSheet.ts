import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getBalanceSheet } from '../api'
import type { BalanceSheetFilters } from '../types'

export function useBalanceSheet(filters?: BalanceSheetFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['balance-sheet', filters]),
    queryFn: () => getBalanceSheet(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}
