import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getAgedReceivables } from '../api'
import type { AgedReceivablesFilters } from '../types'

export function useAgedReceivables(filters?: AgedReceivablesFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['aged-receivables', filters]),
    queryFn: () => getAgedReceivables(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}
