import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getAgedPayables } from '../api'
import type { AgedPayablesFilters } from '../types'

export function useAgedPayables(filters?: AgedPayablesFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['aged-payables', filters]),
    queryFn: () => getAgedPayables(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}
