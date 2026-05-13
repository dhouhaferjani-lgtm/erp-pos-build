import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchVehiclesForPartner } from '../api/partnerVehiclesApi'

export function usePartnerVehicles(partnerId: string | undefined, perPage = 15) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['partner-vehicles', partnerId, perPage]),
    queryFn: () => {
      if (partnerId === undefined) {
        throw new Error('partnerId is required')
      }
      return fetchVehiclesForPartner(partnerId, perPage)
    },
    enabled: Boolean(partnerId) && tenantId !== null && companyId !== null,
  })
}
