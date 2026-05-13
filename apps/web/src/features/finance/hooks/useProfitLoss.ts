import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getProfitLoss } from '../api'
import type { ProfitLossFilters } from '../types'

export function useProfitLoss(filters?: ProfitLossFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['profit-loss', filters]),
    queryFn: () => getProfitLoss(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}
