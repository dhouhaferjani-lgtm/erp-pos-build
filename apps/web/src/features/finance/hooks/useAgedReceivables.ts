import { useQuery } from '@tanstack/react-query'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getAgedReceivables } from '../api'
import type { AgedReceivablesFilters } from '../types'

export function useAgedReceivables(filters?: AgedReceivablesFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { scope, effectiveLocationIds } = useViewScope()
  const scopedFilters = { ...filters, location_ids: effectiveLocationIds }

  return useQuery({
    queryKey: locationScopedKey(['aged-receivables', scopedFilters], scope),
    queryFn: () => getAgedReceivables(scopedFilters),
    enabled: tenantId !== null && companyId !== null,
  })
}
