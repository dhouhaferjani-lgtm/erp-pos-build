import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getScopedLocations } from '../api/scopedLocations'
import type { ScopedLocation } from '../api/scopedLocations'

export function useScopedLocations(): UseQueryResult<ScopedLocation[]> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['company-locations', 'scoped']),
    queryFn: getScopedLocations,
    enabled: Boolean(tenantId && companyId),
    staleTime: 300_000,
  })
}
