import { useQuery } from '@tanstack/react-query'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getAgedPayables } from '../api'
import type { AgedPayablesFilters } from '../types'

export function useAgedPayables(filters?: AgedPayablesFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { scope, effectiveLocationIds } = useViewScope()
  const scopedFilters = { ...filters, location_ids: effectiveLocationIds }

  return useQuery({
    queryKey: locationScopedKey(['aged-payables', scopedFilters], scope),
    queryFn: () => getAgedPayables(scopedFilters),
    enabled: tenantId !== null && companyId !== null,
  })
}
