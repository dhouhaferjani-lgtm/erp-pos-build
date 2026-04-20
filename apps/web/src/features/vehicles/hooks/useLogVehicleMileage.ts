import { useMutation, useQueryClient } from '@tanstack/react-query'
import { logMileage, type LogMileagePayload } from '../api/vehicleMileageApi'

export function useLogVehicleMileage(vehicleId: string | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: LogMileagePayload) => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return logMileage(vehicleId, payload)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['vehicle', vehicleId, 'mileage'] })
      void queryClient.invalidateQueries({ queryKey: ['vehicle', vehicleId] })
    },
  })
}
