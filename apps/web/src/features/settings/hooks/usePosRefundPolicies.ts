import { useQuery } from '@tanstack/react-query'
import { useCompany } from '@/hooks/useCompany'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getPosRefundPolicies } from '../api/posRefundPoliciesApi'

export function usePosRefundPolicies() {
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['pos-refund-policies', currentCompany?.id]),
    queryFn: () => getPosRefundPolicies(currentCompany!.id),
    enabled: !!currentCompany?.id && tenantId !== null && companyId !== null,
  })
}
