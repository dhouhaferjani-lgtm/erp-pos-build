import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getVehicles } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import { usePartsCatalogTenantScope } from './usePartsCatalogTenantScope'
import type { Vehicle, VehicleType } from '../types/catalog'

const SIX_HOURS = 1000 * 60 * 60 * 6

export function useVehicles(
  modelSeriesId: string,
  type: VehicleType = 'pc'
): UseQueryResult<Vehicle[]> {
  const hasTenantScope = usePartsCatalogTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...partsCatalogKeys.vehicles(modelSeriesId, type)]),
    queryFn: () => getVehicles(modelSeriesId, type),
    enabled: Boolean(modelSeriesId) && hasTenantScope,
    staleTime: SIX_HOURS,
  })
}
