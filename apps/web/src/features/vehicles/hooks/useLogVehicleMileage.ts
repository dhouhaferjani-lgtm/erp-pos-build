import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { logMileage, type LogMileagePayload } from '../api/vehicleMileageApi'

export function useLogVehicleMileage(vehicleId: string | undefined) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: LogMileagePayload) => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return logMileage(vehicleId, payload)
    },
    onSuccess: async () => {
      await Promise.all([
        // Bare literal prefix: matches the tenant-suffixed mileage keys.
        queryClient.invalidateQueries({
          queryKey: ['vehicle', vehicleId, 'mileage'],
        }),
        // Vehicle DETAIL only (pinned by vehicles tenantScope test
        // .762-.767): the explicit full key — literal prefix + the same
        // tenant/company suffixes tenantScopedKey stamps — matches just the
        // detail entry, not sibling sub-resources ('mileage', 'ownerships').
        queryClient.invalidateQueries({
          queryKey: ['vehicle', vehicleId, tenantId, companyId],
        }),
      ])
    },
  })
}
