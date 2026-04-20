import { useQuery } from '@tanstack/react-query'
import { fetchOwnershipHistory } from '../api/vehicleOwnershipApi'

export function useVehicleOwnershipHistory(vehicleId: string | undefined) {
  return useQuery({
    queryKey: ['vehicle', vehicleId, 'ownerships'],
    queryFn: () => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return fetchOwnershipHistory(vehicleId)
    },
    enabled: Boolean(vehicleId),
  })
}
