import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getVehicles } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { Vehicle, VehicleType } from '../types/catalog'

const SIX_HOURS = 1000 * 60 * 60 * 6

export function useVehicles(
  modelSeriesId: string,
  type: VehicleType = 'pc'
): UseQueryResult<Vehicle[]> {
  return useQuery({
    queryKey: partsCatalogKeys.vehicles(modelSeriesId, type),
    queryFn: () => getVehicles(modelSeriesId, type),
    enabled: Boolean(modelSeriesId),
    staleTime: SIX_HOURS,
  })
}
