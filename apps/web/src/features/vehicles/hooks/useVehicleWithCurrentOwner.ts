import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/api'
import type {
  VehicleData,
  VehicleMileageReadingData,
  VehicleOwnershipData,
} from '../types'

/**
 * Detail-response shape for `GET /api/v1/vehicles/{id}`.
 *
 * Backend serialises vehicle fields flat under `data` (matching the list
 * endpoint), with `current_ownership` and `recent_mileage_readings` as
 * siblings — see docs/conventions/01-API-RESPONSES.md. Closed audit
 * finding 🟠-3.
 */
export interface VehicleWithCurrentOwner extends VehicleData {
  current_ownership: VehicleOwnershipData | null
  recent_mileage_readings: VehicleMileageReadingData[]
}

interface VehicleWithCurrentOwnerResponse {
  data: VehicleWithCurrentOwner
}

export async function fetchVehicleWithCurrentOwner(
  vehicleId: string,
): Promise<VehicleWithCurrentOwner> {
  const response = await api.get<VehicleWithCurrentOwnerResponse>(`/vehicles/${vehicleId}`)
  return response.data.data
}

export function useVehicleWithCurrentOwner(vehicleId: string | undefined) {
  return useQuery({
    queryKey: ['vehicle-with-owner', vehicleId],
    queryFn: () => {
      if (vehicleId === undefined || vehicleId === '') {
        throw new Error('vehicleId is required')
      }
      return fetchVehicleWithCurrentOwner(vehicleId)
    },
    enabled: vehicleId !== undefined && vehicleId !== '',
    // Align with other vehicle hooks that rely on TanStack Query defaults.
    staleTime: 30_000,
  })
}
