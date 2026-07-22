import { useQuery } from '@tanstack/react-query'

import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getRebalance, type RebalanceRow } from '../api/stockMatrix'

export function useRebalanceSuggestions() {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { scope, effectiveLocationIds } = useViewScope()

  return useQuery({
    queryKey: locationScopedKey(['inventory', 'stock-matrix', 'rebalance'], scope),
    queryFn: () => getRebalance(effectiveLocationIds),
    enabled: tenantId !== null && companyId !== null,
    select: (response): RebalanceRow[] => response.data,
  })
}
