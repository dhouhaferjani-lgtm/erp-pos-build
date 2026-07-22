import { useQuery } from '@tanstack/react-query'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getUpcomingPayments } from '../api'

export function useUpcomingPayments(days = 30) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { scope, effectiveLocationIds } = useViewScope()

  return useQuery({
    queryKey: locationScopedKey(['upcoming-payments', days], scope),
    queryFn: () => getUpcomingPayments(days, effectiveLocationIds),
    enabled: tenantId !== null && companyId !== null,
  })
}
