import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getTransactionLocations, type TransactionLocation } from '../api/locations'

export function useTransactionLocations(): UseQueryResult<TransactionLocation[]> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['locations', 'transaction-destinations']),
    queryFn: getTransactionLocations,
    enabled: !!tenantId && !!companyId,
    staleTime: 300000,
  })
}
