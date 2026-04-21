import { useQuery } from '@tanstack/react-query'
import { fetchMileageHistory } from '../api/vehicleMileageApi'

export function useVehicleMileageHistory(vehicleId: string | undefined) {
  return useQuery({
    queryKey: ['vehicle', vehicleId, 'mileage'],
    queryFn: () => {
      if (vehicleId === undefined) {
        throw new Error('vehicleId is required')
      }
      return fetchMileageHistory(vehicleId)
    },
    enabled: Boolean(vehicleId),
  })
}
