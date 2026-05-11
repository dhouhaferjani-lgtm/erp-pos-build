import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getTrialBalance } from '../api'
import type { TrialBalanceFilters } from '../types'

export function useTrialBalance(filters?: TrialBalanceFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['trial-balance', filters]),
    queryFn: () => getTrialBalance(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}
