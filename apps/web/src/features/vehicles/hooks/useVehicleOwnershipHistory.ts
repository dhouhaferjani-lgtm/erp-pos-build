import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchOwnershipHistory } from '../api/vehicleOwnershipApi'

export function useVehicleOwnershipHistory(vehicleId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['vehicle', vehicleId, 'ownerships']),
    queryFn: () => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return fetchOwnershipHistory(vehicleId)
    },
    enabled: Boolean(vehicleId) && tenantId !== null && companyId !== null,
  })
}
