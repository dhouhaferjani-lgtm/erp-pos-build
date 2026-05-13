import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchMileageHistory } from '../api/vehicleMileageApi'

export function useVehicleMileageHistory(vehicleId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['vehicle', vehicleId, 'mileage']),
    queryFn: () => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return fetchMileageHistory(vehicleId)
    },
    enabled: Boolean(vehicleId) && tenantId !== null && companyId !== null,
  })
}
