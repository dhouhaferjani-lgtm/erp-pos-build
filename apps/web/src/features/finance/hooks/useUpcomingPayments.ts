import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getUpcomingPayments } from '../api'

export function useUpcomingPayments(days = 30) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['upcoming-payments', days]),
    queryFn: () => getUpcomingPayments(days),
    enabled: tenantId !== null && companyId !== null,
  })
}
