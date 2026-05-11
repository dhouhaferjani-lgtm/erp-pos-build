import { useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { logMileage, type LogMileagePayload } from '../api/vehicleMileageApi'

export function useLogVehicleMileage(vehicleId: string | undefined) {
  const queryClient = useQueryClient()
  useAuthStore((state) => state.user?.tenant_id ?? null)
  useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: LogMileagePayload) => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return logMileage(vehicleId, payload)
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey(['vehicle', vehicleId, 'mileage']),
        }),
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey(['vehicle', vehicleId]),
        }),
      ])
    },
  })
}
